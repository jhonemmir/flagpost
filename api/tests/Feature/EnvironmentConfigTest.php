<?php

declare(strict_types=1);

use App\Models\Flag;
use Illuminate\Testing\TestResponse;
use Tests\Support\Api;
use Tests\TestCase;

function replaceEnvironment(
    TestCase $test,
    string $key,
    string $environment,
    string $body,
    string $contentType = 'application/json',
): TestResponse {
    return Api::callRaw(
        $test,
        'PUT',
        "/v1/flags/{$key}/environments/{$environment}",
        $body,
        Api::adminHeaders(),
        $contentType,
    );
}

it('replaces the configuration of one environment', function (): void {
    Api::createFlag('new-checkout');

    $response = $this->putJson(
        '/v1/flags/new-checkout/environments/prod',
        ['enabled' => true, 'percentage' => 20, 'allowList' => ['alice']],
        Api::adminHeaders(),
    );

    $response
        ->assertOk()
        ->assertJsonPath('environments.prod.enabled', true)
        ->assertJsonPath('environments.prod.percentage', 20)
        ->assertJsonPath('environments.prod.allowList', ['alice'])
        ->assertJsonPath('environments.dev.enabled', false)
        ->assertJsonPath('environments.staging.enabled', false);

    $this->getJson('/v1/flags/new-checkout', Api::adminHeaders())
        ->assertJsonPath('environments.prod.enabled', true)
        ->assertJsonPath('environments.prod.percentage', 20)
        ->assertJsonPath('environments.prod.allowList', ['alice']);
});

it('refuses to replace an unknown environment or flag', function (): void {
    Api::createFlag('new-checkout');

    $this->putJson(
        '/v1/flags/new-checkout/environments/qa',
        ['enabled' => true, 'percentage' => 50, 'allowList' => []],
        Api::adminHeaders(),
    )->assertNotFound();

    $this->putJson(
        '/v1/flags/missing/environments/prod',
        ['enabled' => true, 'percentage' => 50, 'allowList' => []],
        Api::adminHeaders(),
    )->assertNotFound();
});

dataset('missing environment fields', [
    'enabled' => [['percentage' => 50, 'allowList' => []], 'enabled'],
    'percentage' => [['enabled' => true, 'allowList' => []], 'percentage'],
    'allowList' => [['enabled' => true, 'percentage' => 50], 'allowList'],
]);

it('requires all three environment fields', function (array $body, string $field): void {
    Api::createFlag('new-checkout');

    $this->putJson('/v1/flags/new-checkout/environments/prod', $body, Api::adminHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with('missing environment fields');

it('rejects unknown fields in an environment body', function (): void {
    Api::createFlag('new-checkout');

    $this->putJson(
        '/v1/flags/new-checkout/environments/prod',
        ['enabled' => true, 'percentage' => 50, 'allowList' => [], 'extra' => 1],
        Api::adminHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('extra');
});

dataset('invalid percentages', [
    'negative' => ['-1'],
    'above 100' => ['101'],
    'decimal with zero fraction' => ['50.0'],
    'decimal' => ['50.5'],
    'exponent' => ['1e2'],
    'exponent uppercase' => ['1E2'],
    'string' => ['"50"'],
    'empty string' => ['""'],
    'whitespace string' => ['" "'],
    'boolean true' => ['true'],
    'null' => ['null'],
    'array' => ['[50]'],
]);

it('rejects percentages that are not integer JSON tokens', function (string $percentage): void {
    Api::createFlag('new-checkout');

    replaceEnvironment(
        $this,
        'new-checkout',
        'prod',
        '{"enabled":true,"percentage":'.$percentage.',"allowList":[]}',
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('percentage');

    expect(Flag::query()->firstOrFail()->configs()->where('environment', 'prod')->firstOrFail()->percentage)
        ->toBe(100);
})->with('invalid percentages');

it('accepts the boundary percentages', function (int $percentage): void {
    Api::createFlag('new-checkout');

    $this->putJson(
        '/v1/flags/new-checkout/environments/prod',
        ['enabled' => true, 'percentage' => $percentage, 'allowList' => []],
        Api::adminHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('environments.prod.percentage', $percentage);
})->with([0, 1, 99, 100]);

dataset('invalid enabled values', [
    'string' => ['"true"'],
    'empty string' => ['""'],
    'whitespace string' => ['" "'],
    'integer one' => ['1'],
    'integer zero' => ['0'],
    'null' => ['null'],
]);

it('rejects non-boolean enabled values', function (string $enabled): void {
    Api::createFlag('new-checkout');

    replaceEnvironment(
        $this,
        'new-checkout',
        'prod',
        '{"enabled":'.$enabled.',"percentage":50,"allowList":[]}',
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('enabled');
})->with('invalid enabled values');

dataset('invalid allow lists', [
    'string' => ['"alice"', 'allowList'],
    'object' => ['{"user":"alice"}', 'allowList'],
    'number' => ['1', 'allowList'],
    'null' => ['null', 'allowList'],
    'entry number' => ['[1]', 'allowList.0'],
    'entry boolean' => ['[true]', 'allowList.0'],
    'entry null' => ['[null]', 'allowList.0'],
    'entry object' => ['[{"id":"alice"}]', 'allowList.0'],
    'empty entry' => ['[""]', 'allowList.0'],
    'empty later entry' => ['["alice",""]', 'allowList.1'],
]);

it('rejects allow lists that are not non-empty string arrays', function (string $allowList, string $error): void {
    Api::createFlag('new-checkout');

    replaceEnvironment(
        $this,
        'new-checkout',
        'prod',
        '{"enabled":true,"percentage":50,"allowList":'.$allowList.'}',
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors($error);
})->with('invalid allow lists');

it('accepts at most 1000 allow-list entries', function (): void {
    Api::createFlag('new-checkout');

    $entries = array_map(static fn (int $i): string => 'user-'.$i, range(1, 1000));

    $this->putJson(
        '/v1/flags/new-checkout/environments/prod',
        ['enabled' => true, 'percentage' => 50, 'allowList' => $entries],
        Api::adminHeaders(),
    )->assertOk();

    $entries[] = 'user-1001';

    $this->putJson(
        '/v1/flags/new-checkout/environments/prod',
        ['enabled' => true, 'percentage' => 50, 'allowList' => $entries],
        Api::adminHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('allowList');
});

it('measures allow-list entries in UTF-8 bytes', function (): void {
    Api::createFlag('new-checkout');

    $this->putJson(
        '/v1/flags/new-checkout/environments/prod',
        ['enabled' => true, 'percentage' => 50, 'allowList' => [str_repeat('a', 256)]],
        Api::adminHeaders(),
    )->assertOk();

    $this->putJson(
        '/v1/flags/new-checkout/environments/prod',
        ['enabled' => true, 'percentage' => 50, 'allowList' => [str_repeat('a', 257)]],
        Api::adminHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('allowList.0');

    $this->putJson(
        '/v1/flags/new-checkout/environments/prod',
        ['enabled' => true, 'percentage' => 50, 'allowList' => [str_repeat('🚀', 64)]],
        Api::adminHeaders(),
    )->assertOk();

    $this->putJson(
        '/v1/flags/new-checkout/environments/prod',
        ['enabled' => true, 'percentage' => 50, 'allowList' => [str_repeat('🚀', 65)]],
        Api::adminHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('allowList.0');
});

it('preserves meaningful whitespace in allow-list entries and user ids', function (): void {
    Api::createFlag('new-checkout');

    $this->putJson(
        '/v1/flags/new-checkout/environments/prod',
        ['enabled' => true, 'percentage' => 50, 'allowList' => [' alice', 'alice ', ' ']],
        Api::adminHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('environments.prod.allowList', [' alice', 'alice ', ' ']);
});

it('removes duplicates while preserving order', function (): void {
    Api::createFlag('new-checkout');

    $this->putJson(
        '/v1/flags/new-checkout/environments/prod',
        ['enabled' => true, 'percentage' => 50, 'allowList' => ['bob', 'alice', 'bob', 'carol', 'alice']],
        Api::adminHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('environments.prod.allowList', ['bob', 'alice', 'carol']);
});

it('returns 404 instead of a budget error for an unknown flag', function (): void {
    config(['flagpost.limits.allow_list_budget_bytes' => 1]);

    $this->putJson(
        '/v1/flags/missing/environments/prod',
        ['enabled' => true, 'percentage' => 50, 'allowList' => ['alice']],
        Api::adminHeaders(),
    )->assertNotFound();
});

it('enforces the allow-list budget per environment across flags', function (): void {
    config(['flagpost.limits.allow_list_budget_bytes' => 10]);

    Api::createFlag('first');
    Api::createFlag('second');

    $this->putJson(
        '/v1/flags/first/environments/prod',
        ['enabled' => true, 'percentage' => 50, 'allowList' => ['abcdefgh']],
        Api::adminHeaders(),
    )->assertOk();

    // Replacing the same flag does not count its previous data twice.
    $this->putJson(
        '/v1/flags/first/environments/prod',
        ['enabled' => true, 'percentage' => 50, 'allowList' => ['abcdefgh']],
        Api::adminHeaders(),
    )->assertOk();

    // 9 + 9 bytes exceeds the 10-byte budget for prod.
    $this->putJson(
        '/v1/flags/second/environments/prod',
        ['enabled' => true, 'percentage' => 50, 'allowList' => ['abcdefgh']],
        Api::adminHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('allowList');

    // The budget is scoped to one environment.
    $this->putJson(
        '/v1/flags/second/environments/staging',
        ['enabled' => true, 'percentage' => 50, 'allowList' => ['abcdefgh']],
        Api::adminHeaders(),
    )->assertOk();
});
