<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Flag;
use App\Models\FlagEnvironmentConfig;
use App\Support\FlagpostConfig;
use Closure;
use Illuminate\Validation\Validator;

final class ReplaceEnvironmentRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxEntries = FlagpostConfig::limit('allow_list_entries');

        return [
            'enabled' => ['present'],
            'percentage' => ['present'],
            'allowList' => [
                'present',
                function (string $attribute, mixed $value, Closure $fail) use ($maxEntries): void {
                    if (! is_array($value) || ! array_is_list($value)) {
                        $fail('Must be an array of strings.');

                        return;
                    }

                    if (count($value) > $maxEntries) {
                        $fail("Must have at most {$maxEntries} entries.");
                    }
                },
            ],
        ];
    }

    /**
     * Type checks live here rather than in rule closures: Laravel skips
     * non-implicit rules whose string value trims to "", so "" would slip
     * through as a valid boolean, integer or allow-list entry.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->rejectUnknownFields($validator, ['enabled', 'percentage', 'allowList']);

                if (! is_bool($this->input('enabled'))) {
                    $validator->errors()->add('enabled', 'Must be a boolean.');
                }

                $percentage = $this->input('percentage');

                if (! is_int($percentage) || $percentage < 0 || $percentage > 100) {
                    $validator->errors()->add('percentage', 'Must be an integer between 0 and 100.');
                }

                $submitted = $this->input('allowList');

                if (! is_array($submitted) || ! array_is_list($submitted)) {
                    return;
                }

                $this->validateEntries($validator, $submitted);
                $this->validateBudget($validator, $submitted);
            },
        ];
    }

    /**
     * @param  list<mixed>  $entries
     */
    private function validateEntries(Validator $validator, array $entries): void
    {
        $maxEntryBytes = FlagpostConfig::limit('allow_list_entry_bytes');

        foreach ($entries as $index => $entry) {
            $field = "allowList.{$index}";

            if (! is_string($entry)) {
                $validator->errors()->add($field, 'Must be a string.');

                continue;
            }

            if ($entry === '') {
                $validator->errors()->add($field, 'Must not be empty.');

                continue;
            }

            if (strlen($entry) > $maxEntryBytes) {
                $validator->errors()->add($field, "Must be at most {$maxEntryBytes} bytes in UTF-8.");

                continue;
            }

            if (! mb_check_encoding($entry, 'UTF-8') || preg_match('//u', $entry) !== 1) {
                $validator->errors()->add($field, 'Must be valid UTF-8.');
            }
        }
    }

    /**
     * @param  list<mixed>  $submitted
     */
    private function validateBudget(Validator $validator, array $submitted): void
    {
        $entries = array_values(array_unique(array_filter(
            $submitted,
            static fn (mixed $entry): bool => is_string($entry) && $entry !== '',
        )));

        $bytes = 0;

        foreach ($entries as $entry) {
            $bytes += strlen($entry) + 1;
        }

        $environment = $this->route('env');
        $key = $this->route('key');
        $flagId = $this->currentFlagId();

        // An unknown flag is a 404, not a budget failure: let the controller
        // answer it instead of validating the write against a missing target.
        if (is_string($key) && $flagId === null) {
            return;
        }

        if (is_string($environment)) {
            $bytes += $this->bytesUsedInEnvironment($environment, $flagId);
        }

        if ($bytes > FlagpostConfig::limit('allow_list_budget_bytes')) {
            $validator->errors()->add(
                'allowList',
                'The allow list exceeds the 1 MiB budget for this environment.',
            );
        }
    }

    private function currentFlagId(): ?int
    {
        $key = $this->route('key');

        if (! is_string($key)) {
            return null;
        }

        $id = Flag::query()->where('key', $key)->value('id');

        return is_int($id) ? $id : null;
    }

    private function bytesUsedInEnvironment(string $environment, ?int $excludeFlagId): int
    {
        $query = FlagEnvironmentConfig::query()->where('environment', $environment);

        if ($excludeFlagId !== null) {
            $query->where('flag_id', '!=', $excludeFlagId);
        }

        $bytes = 0;

        foreach ($query->get() as $config) {
            foreach ($config->allow_list as $entry) {
                $bytes += strlen($entry) + 1;
            }
        }

        return $bytes;
    }
}
