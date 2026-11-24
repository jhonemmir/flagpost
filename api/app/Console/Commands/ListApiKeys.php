<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

final class ListApiKeys extends Command
{
    protected $signature = 'flagpost:key:list {environment? : dev, staging or prod}';

    protected $description = 'List environment keys. Only the visible prefix is shown.';

    public function handle(): int
    {
        $environment = $this->argument('environment');
        $query = ApiKey::query()->orderBy('created_at');

        if (is_string($environment)) {
            /** @var list<string> $environments */
            $environments = config('flagpost.environments');

            if (! in_array($environment, $environments, true)) {
                $this->error('Unknown environment. Use dev, staging or prod.');

                return self::FAILURE;
            }

            $query->where('environment', $environment);
        }

        $rows = $query->get()->map(static fn (ApiKey $key): array => [
            $key->id,
            $key->environment,
            $key->prefix,
            $key->created_at?->utc()->format('Y-m-d H:i:s') ?? '',
            $key->revoked_at?->utc()->format('Y-m-d H:i:s') ?? 'active',
        ])->all();

        $this->table(
            ['id', 'environment', 'prefix', 'created at', 'revoked at'],
            $rows,
        );

        return self::SUCCESS;
    }
}
