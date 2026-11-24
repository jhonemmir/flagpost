<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Evaluation\Bucketing;
use App\Evaluation\Evaluator;
use App\Evaluation\FlagConfig;
use App\Evaluation\Reason;
use App\Http\Controllers\Controller;
use App\Http\ProblemDetails;
use App\Http\Requests\CreateFlagRequest;
use App\Http\Requests\ExplainRequest;
use App\Http\Requests\ReplaceEnvironmentRequest;
use App\Http\Requests\UpdateFlagRequest;
use App\Models\Flag;
use App\Support\FlagpostConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FlagController extends Controller
{
    public function index(): JsonResponse
    {
        $flags = Flag::query()
            ->with('configs')
            ->get()
            ->sortBy(static fn (Flag $flag): string => $flag->key, SORT_STRING)
            ->values();

        return response()->json([
            'flags' => $flags->map(static fn (Flag $flag): array => $flag->toApiArray())->all(),
        ]);
    }

    public function store(CreateFlagRequest $request): JsonResponse
    {
        $key = $request->string('key')->toString();

        if (Flag::query()->where('key', $key)->exists()) {
            return ProblemDetails::response(409, 'A flag with that key already exists.');
        }

        if (Flag::query()->count() >= FlagpostConfig::limit('flags')) {
            throw ValidationException::withMessages([
                'key' => ['An installation can have at most 500 flags.'],
            ]);
        }

        $description = $request->input('description');

        try {
            $flag = DB::transaction(function () use ($key, $description): Flag {
                $flag = Flag::create([
                    'key' => $key,
                    'description' => is_string($description) && $description !== '' ? $description : null,
                ]);

                foreach (FlagpostConfig::environments() as $environment) {
                    $flag->configs()->create([
                        'environment' => $environment,
                        'enabled' => false,
                        'percentage' => 100,
                        'allow_list' => [],
                    ]);
                }

                return $flag;
            });
        } catch (UniqueConstraintViolationException) {
            return ProblemDetails::response(409, 'A flag with that key already exists.');
        }

        $flag->load('configs');

        return response()->json($flag->toApiArray(), 201, [
            'Location' => '/v1/flags/'.$flag->key,
        ]);
    }

    public function show(string $key): JsonResponse
    {
        $flag = Flag::query()->with('configs')->where('key', $key)->firstOrFail();

        return response()->json($flag->toApiArray());
    }

    public function update(UpdateFlagRequest $request, string $key): JsonResponse
    {
        $flag = Flag::query()->where('key', $key)->firstOrFail();
        $description = $request->input('description');

        $flag->update([
            'description' => is_string($description) && $description !== '' ? $description : null,
        ]);

        $flag->load('configs');

        return response()->json($flag->toApiArray());
    }

    public function destroy(string $key): Response
    {
        Flag::query()->where('key', $key)->firstOrFail()->delete();

        return response()->noContent();
    }

    public function replaceEnvironment(
        ReplaceEnvironmentRequest $request,
        string $key,
        string $environment,
    ): JsonResponse {
        $flag = Flag::query()->where('key', $key)->firstOrFail();

        /** @var list<string> $submitted */
        $submitted = $request->input('allowList');
        $allowList = array_values(array_unique($submitted));

        $flag->configs()->updateOrCreate(
            ['environment' => $environment],
            [
                'enabled' => $request->boolean('enabled'),
                'percentage' => $request->integer('percentage'),
                'allow_list' => $allowList,
            ],
        );

        $flag->touch();
        $flag->load('configs');

        return response()->json($flag->toApiArray());
    }

    public function explain(
        ExplainRequest $request,
        string $key,
        string $environment,
    ): JsonResponse {
        $flag = Flag::query()->where('key', $key)->firstOrFail();
        $config = $flag->configs()->where('environment', $environment)->first();

        if ($config === null) {
            return ProblemDetails::response(404, 'Unknown environment for this flag.');
        }

        $userId = $request->input('userId');
        $userId = is_string($userId) ? $userId : null;
        $defaultValue = $request->has('defaultValue') ? $request->boolean('defaultValue') : false;

        /** @var list<string> $allowList */
        $allowList = $config->allow_list;

        $result = Evaluator::evaluate(
            $flag->key,
            new FlagConfig($config->enabled, $config->percentage, $allowList),
            $userId,
            $defaultValue,
        );

        $bucket = in_array($result->reason, [Reason::PercentageIn, Reason::PercentageOut], true)
            ? Bucketing::bucket($flag->key, (string) $userId)
            : null;

        return response()->json([
            'value' => $result->value,
            'reason' => $result->reason->value,
            'bucket' => $bucket,
        ]);
    }
}
