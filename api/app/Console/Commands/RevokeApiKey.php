<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

final class RevokeApiKey extends Command
{
    protected $signature = 'flagpost:key:revoke {id : The opaque key id}';

    protected $description = 'Revoke an environment key.';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $apiKey = ApiKey::query()->where('id', $id)->first();

        if ($apiKey === null) {
            $this->error('No key with that id.');

            return self::FAILURE;
        }

        if ($apiKey->revoked_at !== null) {
            $this->info('That key was already revoked.');

            return self::SUCCESS;
        }

        $apiKey->update(['revoked_at' => now()]);

        $this->info("Revoked {$apiKey->id} ({$apiKey->prefix}).");

        return self::SUCCESS;
    }
}
