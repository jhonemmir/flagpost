<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ApiKeyService;
use Illuminate\Console\Command;

final class CreateApiKey extends Command
{
    protected $signature = 'flagpost:key:create {environment : dev, staging or prod}';

    protected $description = 'Create an environment key. The full key is printed only once.';

    public function handle(): int
    {
        $environment = (string) $this->argument('environment');

        /** @var list<string> $environments */
        $environments = config('flagpost.environments');

        if (! in_array($environment, $environments, true)) {
            $this->error('Unknown environment. Use dev, staging or prod.');

            return self::FAILURE;
        }

        $issued = ApiKeyService::issue($environment);

        $this->info("Created {$issued['apiKey']->id} for {$environment}.");
        $this->line($issued['plainKey']);
        $this->warn('Store the key now: the API only keeps its SHA-256 hash.');

        return self::SUCCESS;
    }
}
