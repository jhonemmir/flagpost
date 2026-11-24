<?php

declare(strict_types=1);

use App\Models\ApiKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Api;

it('creates a key for each environment and stores only its hash', function (string $environment): void {
    $exit = Artisan::call('flagpost:key:create', ['environment' => $environment]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain("for {$environment}");

    preg_match('/fp_'.$environment.'_[A-Za-z0-9]{32}/', $output, $matches);

    expect($matches)->not->toBeEmpty();

    $plainKey = $matches[0];
    $stored = ApiKey::query()->firstOrFail();

    expect($stored->environment)->toBe($environment);
    expect($stored->hash)->toBe(hash('sha256', $plainKey));
    expect($stored->prefix)->toBe(substr($plainKey, 0, strlen($environment) + 8));
    expect($stored->prefix)->toBe('fp_'.$environment.'_'.substr($plainKey, -32, 4));
    expect($stored->revoked_at)->toBeNull();

    $row = (array) DB::table('api_keys')->firstOrFail();

    expect(json_encode($row))->not->toContain($plainKey);
    expect(json_encode($row))->not->toContain(substr($plainKey, -28));
})->with(['dev', 'staging', 'prod']);

it('rejects an unknown environment and stores nothing', function (): void {
    $exit = Artisan::call('flagpost:key:create', ['environment' => 'qa']);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('Unknown environment');
    expect(ApiKey::query()->count())->toBe(0);
});

it('accepts several active keys per environment', function (): void {
    Artisan::call('flagpost:key:create', ['environment' => 'prod']);
    Artisan::call('flagpost:key:create', ['environment' => 'prod']);

    expect(ApiKey::query()->where('environment', 'prod')->whereNull('revoked_at')->count())->toBe(2);
});

it('lists keys without revealing the secret', function (): void {
    Artisan::call('flagpost:key:create', ['environment' => 'prod']);
    $prodOutput = Artisan::output();
    $prodKey = extractKey($prodOutput, 'prod');

    Artisan::call('flagpost:key:list');
    $list = Artisan::output();

    $stored = ApiKey::query()->firstOrFail();

    expect($list)->toContain($stored->id);
    expect($list)->toContain($stored->prefix);
    expect($list)->toContain('active');
    expect($list)->not->toContain($prodKey);
    expect($list)->not->toContain($stored->hash);
});

it('lists keys for a single environment', function (): void {
    Artisan::call('flagpost:key:create', ['environment' => 'dev']);
    $devKey = extractKey(Artisan::output(), 'dev');

    Artisan::call('flagpost:key:create', ['environment' => 'prod']);
    $prodKey = extractKey(Artisan::output(), 'prod');

    Artisan::call('flagpost:key:list', ['environment' => 'prod']);
    $list = Artisan::output();

    expect($list)->toContain(substr($prodKey, 0, 12));
    expect($list)->not->toContain(substr($devKey, 0, 12));
});

it('rejects an unknown environment when listing', function (): void {
    $exit = Artisan::call('flagpost:key:list', ['environment' => 'qa']);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('Unknown environment');
});

it('revokes a key and stops accepting it', function (): void {
    Artisan::call('flagpost:key:create', ['environment' => 'prod']);
    $plainKey = extractKey(Artisan::output(), 'prod');
    $stored = ApiKey::query()->firstOrFail();

    Api::createFlag('new-checkout');

    $this->getJson('/v1/sdk/flags', Api::sdkHeaders($plainKey))->assertOk();

    $exit = Artisan::call('flagpost:key:revoke', ['id' => $stored->id]);

    expect($exit)->toBe(0);
    expect($stored->refresh()->revoked_at)->not->toBeNull();
    expect(Artisan::output())->toContain('Revoked');

    $this->getJson('/v1/sdk/flags', Api::sdkHeaders($plainKey))->assertStatus(401);

    expect(Artisan::call('flagpost:key:revoke', ['id' => $stored->id]))->toBe(0);
    expect(Artisan::output())->toContain('already revoked');
});

it('fails to revoke an unknown key', function (): void {
    $exit = Artisan::call('flagpost:key:revoke', ['id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('No key with that id');
});

it('leaves other keys of the same environment active when revoking one', function (): void {
    Artisan::call('flagpost:key:create', ['environment' => 'prod']);
    $first = extractKey(Artisan::output(), 'prod');
    Artisan::call('flagpost:key:create', ['environment' => 'prod']);
    $second = extractKey(Artisan::output(), 'prod');

    $firstKey = ApiKey::query()->where('hash', hash('sha256', $first))->firstOrFail();
    ApiKey::query()->where('id', $firstKey->id)->update(['revoked_at' => now()]);

    Api::createFlag('new-checkout');

    $this->getJson('/v1/sdk/flags', Api::sdkHeaders($first))->assertStatus(401);
    $this->getJson('/v1/sdk/flags', Api::sdkHeaders($second))->assertOk();
});

function extractKey(string $output, string $environment): string
{
    preg_match('/fp_'.$environment.'_[A-Za-z0-9]{32}/', $output, $matches);

    if (! isset($matches[0])) {
        throw new RuntimeException('No key found in the command output.');
    }

    return $matches[0];
}
