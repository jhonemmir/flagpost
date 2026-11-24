<?php

declare(strict_types=1);

use App\Models\ApiKey;
use Tests\Support\Api;

it('returns the flags of the environment that owns the key', function (): void {
    $flag = Api::createFlag('new-checkout');
    Api::configure($flag, 'prod', true, 20, ['alice']);
    Api::configure($flag, 'dev', false, 100, []);

    $prod = Api::environmentKey('prod');
    $dev = Api::environmentKey('dev');

    $this->getJson('/v1/sdk/flags', Api::sdkHeaders($prod['plainKey']))
        ->assertOk()
        ->assertExactJson([
            'schemaVersion' => 1,
            'environment' => 'prod',
            'flags' => [
                ['key' => 'new-checkout', 'enabled' => true, 'percentage' => 20, 'allowList' => ['alice']],
            ],
        ]);

    $this->getJson('/v1/sdk/flags', Api::sdkHeaders($dev['plainKey']))
        ->assertOk()
        ->assertJsonPath('environment', 'dev')
        ->assertJsonPath('flags.0.enabled', false)
        ->assertJsonPath('flags.0.percentage', 100)
        ->assertJsonPath('flags.0.allowList', []);
});

it('sorts flags by key using ordinal comparison', function (): void {
    $keys = ['zeta', 'alpha', 'alpha.b', 'alpha-b', 'alpha_b', 'alpha0'];

    foreach ($keys as $key) {
        Api::createFlag($key);
    }

    $key = Api::environmentKey('prod');

    $response = $this->getJson('/v1/sdk/flags', Api::sdkHeaders($key['plainKey']))->assertOk();

    $sorted = $keys;
    sort($sorted, SORT_STRING);

    expect(array_column($response->json('flags'), 'key'))->toBe($sorted);
});

it('returns every flag even when its configuration row is missing', function (): void {
    $flag = Api::createFlag('new-checkout');
    $flag->configs()->where('environment', 'prod')->delete();

    $key = Api::environmentKey('prod');

    $this->getJson('/v1/sdk/flags', Api::sdkHeaders($key['plainKey']))
        ->assertOk()
        ->assertJsonPath('flags.0.key', 'new-checkout')
        ->assertJsonPath('flags.0.enabled', false)
        ->assertJsonPath('flags.0.percentage', 100)
        ->assertJsonPath('flags.0.allowList', []);
});

it('sends a quoted SHA-256 ETag and the cache headers', function (): void {
    $flag = Api::createFlag('new-checkout');
    Api::configure($flag, 'prod', true, 20, ['alice']);
    $key = Api::environmentKey('prod');

    $response = $this->getJson('/v1/sdk/flags', Api::sdkHeaders($key['plainKey']))->assertOk();

    $body = $response->getContent();

    expect($body)->toBeString();
    expect($response->headers->get('ETag'))->toBe('"'.hash('sha256', (string) $body).'"');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache');
    expect($response->headers->get('Vary'))->toBe('X-Api-Key');
    expect($response->headers->get('Content-Type'))->toBe('application/json');
    expect($response->json('flags.0'))->toHaveKeys(['key', 'enabled', 'percentage', 'allowList']);
    expect($response->json('flags.0'))->not->toHaveKey('description');
});

it('keeps the body and ETag stable across requests', function (): void {
    $flag = Api::createFlag('new-checkout');
    Api::configure($flag, 'prod', true, 20, ['alice']);
    $key = Api::environmentKey('prod');

    $first = $this->getJson('/v1/sdk/flags', Api::sdkHeaders($key['plainKey']));
    $second = $this->getJson('/v1/sdk/flags', Api::sdkHeaders($key['plainKey']));

    expect($second->getContent())->toBe($first->getContent());
    expect($second->headers->get('ETag'))->toBe($first->headers->get('ETag'));
});

it('returns 304 for each accepted If-None-Match form', function (string $template): void {
    Api::createFlag('new-checkout');
    $key = Api::environmentKey('prod');

    $first = $this->getJson('/v1/sdk/flags', Api::sdkHeaders($key['plainKey']))->assertOk();
    $etag = (string) $first->headers->get('ETag');

    $header = str_replace('{etag}', $etag, $template);

    $response = $this->getJson(
        '/v1/sdk/flags',
        array_merge(Api::sdkHeaders($key['plainKey']), ['If-None-Match' => $header]),
    );

    $response->assertStatus(304);
    expect($response->getContent())->toBe('');
    expect($response->headers->get('ETag'))->toBe($etag);
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache');
    expect($response->headers->get('Vary'))->toBe('X-Api-Key');
})->with([
    'exact' => ['{etag}'],
    'weak' => ['W/{etag}'],
    'list' => ['"other", W/{etag}'],
    'wildcard' => ['*'],
]);

it('returns 200 when If-None-Match does not match', function (): void {
    Api::createFlag('new-checkout');
    $key = Api::environmentKey('prod');

    $this->getJson(
        '/v1/sdk/flags',
        array_merge(Api::sdkHeaders($key['plainKey']), ['If-None-Match' => '"nope"']),
    )->assertOk();
});

it('returns a different ETag per environment', function (): void {
    $flag = Api::createFlag('new-checkout');
    Api::configure($flag, 'prod', true, 20, ['alice']);
    Api::configure($flag, 'dev', true, 80, ['bob']);

    $prod = Api::environmentKey('prod');
    $dev = Api::environmentKey('dev');

    $prodEtag = $this->getJson('/v1/sdk/flags', Api::sdkHeaders($prod['plainKey']))
        ->headers->get('ETag');
    $devEtag = $this->getJson('/v1/sdk/flags', Api::sdkHeaders($dev['plainKey']))
        ->headers->get('ETag');

    expect($devEtag)->not->toBe($prodEtag);
});

it('requires a valid environment key', function (): void {
    $this->getJson('/v1/sdk/flags')->assertStatus(401);
    $this->getJson('/v1/sdk/flags', Api::sdkHeaders('fp_prod_unknown'))->assertStatus(401);
});

it('rejects the admin key on the SDK endpoint', function (): void {
    $this->getJson('/v1/sdk/flags', ['Authorization' => 'Bearer '.Api::ADMIN_KEY])
        ->assertStatus(403);
});

it('rejects a revoked environment key', function (): void {
    $issued = Api::environmentKey('prod');
    $issued['apiKey']->update(['revoked_at' => now()]);

    $this->getJson('/v1/sdk/flags', Api::sdkHeaders($issued['plainKey']))->assertStatus(401);
});

it('accepts several active keys for one environment', function (): void {
    Api::createFlag('new-checkout');

    $first = Api::environmentKey('prod');
    $second = Api::environmentKey('prod');

    $this->getJson('/v1/sdk/flags', Api::sdkHeaders($first['plainKey']))->assertOk();
    $this->getJson('/v1/sdk/flags', Api::sdkHeaders($second['plainKey']))->assertOk();

    expect(ApiKey::query()->where('environment', 'prod')->whereNull('revoked_at')->count())->toBe(2);
});

it('rate limits the SDK per environment key', function (): void {
    config(['flagpost.rate_limits.sdk_per_minute' => 1]);
    Api::createFlag('new-checkout');
    $key = Api::environmentKey('prod');

    $this->getJson('/v1/sdk/flags', Api::sdkHeaders($key['plainKey']))->assertOk();

    $limited = $this->getJson('/v1/sdk/flags', Api::sdkHeaders($key['plainKey']))
        ->assertStatus(429)
        ->assertHeader('Content-Type', 'application/problem+json');

    expect($limited->headers->has('Retry-After'))->toBeTrue();
    expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
});

it('does not let one environment key exhaust another key limit', function (): void {
    config(['flagpost.rate_limits.sdk_per_minute' => 1]);
    Api::createFlag('new-checkout');

    $first = Api::environmentKey('prod');
    $second = Api::environmentKey('prod');

    $this->getJson('/v1/sdk/flags', Api::sdkHeaders($first['plainKey']))->assertOk();
    $this->getJson('/v1/sdk/flags', Api::sdkHeaders($second['plainKey']))->assertOk();
});

it('rejects methods other than GET on the SDK endpoint', function (): void {
    $key = Api::environmentKey('prod');

    $this->postJson('/v1/sdk/flags', [], Api::sdkHeaders($key['plainKey']))->assertStatus(405);
});
