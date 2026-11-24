<?php

declare(strict_types=1);

use App\Models\Flag;
use Illuminate\Support\Facades\DB;
use Tests\Support\Api;

it('creates a flag disabled in every environment', function (): void {
    $response = $this->postJson('/v1/flags', [
        'key' => 'new-checkout',
        'description' => 'Redesigned checkout flow',
    ], Api::adminHeaders());

    $response
        ->assertCreated()
        ->assertHeader('Location', '/v1/flags/new-checkout')
        ->assertJsonPath('key', 'new-checkout')
        ->assertJsonPath('description', 'Redesigned checkout flow')
        ->assertJsonPath('environments.dev.enabled', false)
        ->assertJsonPath('environments.dev.percentage', 100)
        ->assertJsonPath('environments.dev.allowList', [])
        ->assertJsonPath('environments.staging.enabled', false)
        ->assertJsonPath('environments.staging.percentage', 100)
        ->assertJsonPath('environments.staging.allowList', [])
        ->assertJsonPath('environments.prod.enabled', false)
        ->assertJsonPath('environments.prod.percentage', 100)
        ->assertJsonPath('environments.prod.allowList', []);

    expect($response->json('createdAt'))->toBe($response->json('updatedAt'));

    $this->assertDatabaseHas('flags', ['key' => 'new-checkout']);
    $this->assertDatabaseCount('flag_environment_configs', 3);

    foreach (['dev', 'staging', 'prod'] as $environment) {
        $this->assertDatabaseHas('flag_environment_configs', [
            'environment' => $environment,
            'enabled' => false,
            'percentage' => 100,
        ]);
    }
});

it('accepts a flag without a description', function (): void {
    $response = $this->postJson('/v1/flags', ['key' => 'dark-mode'], Api::adminHeaders());

    $response->assertCreated()->assertJsonPath('description', null);
});

it('treats an empty description as null', function (): void {
    $response = $this->postJson(
        '/v1/flags',
        ['key' => 'dark-mode', 'description' => ''],
        Api::adminHeaders(),
    );

    $response->assertCreated()->assertJsonPath('description', null);
});

it('lists flags sorted by key with an ordinal comparison', function (): void {
    $keys = ['zeta', 'alpha', 'alpha.b', 'alpha-b', 'alpha_b', 'alpha0'];

    foreach ($keys as $key) {
        Api::createFlag($key);
    }

    $response = $this->getJson('/v1/flags', Api::adminHeaders());

    $response->assertOk();

    /** @var list<array{key: string}> $flags */
    $flags = $response->json('flags');

    $sorted = $keys;
    sort($sorted, SORT_STRING);

    expect(array_column($flags, 'key'))->toBe($sorted);
    expect($response->json('flags.0.environments'))->toHaveKeys(['dev', 'staging', 'prod']);
});

it('returns an empty list when there are no flags', function (): void {
    $this->getJson('/v1/flags', Api::adminHeaders())
        ->assertOk()
        ->assertExactJson(['flags' => []]);
});

it('shows a single flag', function (): void {
    Api::createFlag('new-checkout', 'Redesigned checkout flow');

    $this->getJson('/v1/flags/new-checkout', Api::adminHeaders())
        ->assertOk()
        ->assertJsonPath('key', 'new-checkout')
        ->assertJsonPath('description', 'Redesigned checkout flow');
});

it('returns 404 for an unknown flag', function (): void {
    $this->getJson('/v1/flags/missing', Api::adminHeaders())->assertNotFound();
});

it('updates and clears the description', function (): void {
    Api::createFlag('new-checkout');
    $this->travel(2)->seconds();

    $this->patchJson(
        '/v1/flags/new-checkout',
        ['description' => 'New checkout, gradual rollout'],
        Api::adminHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('description', 'New checkout, gradual rollout');

    $this->patchJson('/v1/flags/new-checkout', ['description' => null], Api::adminHeaders())
        ->assertOk()
        ->assertJsonPath('description', null);

    $this->patchJson('/v1/flags/new-checkout', ['description' => ''], Api::adminHeaders())
        ->assertOk()
        ->assertJsonPath('description', null);
});

it('changes updatedAt on every write', function (): void {
    $created = $this->postJson(
        '/v1/flags',
        ['key' => 'new-checkout'],
        Api::adminHeaders(),
    )->json('updatedAt');

    $this->travel(2)->seconds();

    $patched = $this->patchJson(
        '/v1/flags/new-checkout',
        ['description' => 'Changed'],
        Api::adminHeaders(),
    )->json('updatedAt');

    expect($patched)->not->toBe($created);

    $this->travel(2)->seconds();

    $replaced = $this->putJson(
        '/v1/flags/new-checkout/environments/prod',
        ['enabled' => true, 'percentage' => 50, 'allowList' => []],
        Api::adminHeaders(),
    )->json('updatedAt');

    expect($replaced)->not->toBe($patched);
});

it('rejects an update that carries fields other than description', function (): void {
    Api::createFlag('new-checkout');

    $this->patchJson(
        '/v1/flags/new-checkout',
        ['description' => 'Changed', 'key' => 'renamed'],
        Api::adminHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('key');
});

it('requires description on update', function (): void {
    Api::createFlag('new-checkout');

    $this->patchJson('/v1/flags/new-checkout', [], Api::adminHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors('description');
});

it('deletes a flag and its configurations', function (): void {
    Api::createFlag('new-checkout');

    $this->deleteJson('/v1/flags/new-checkout', [], Api::adminHeaders())->assertNoContent();

    $this->assertDatabaseMissing('flags', ['key' => 'new-checkout']);
    $this->assertDatabaseCount('flag_environment_configs', 0);
    $this->getJson('/v1/flags/new-checkout', Api::adminHeaders())->assertNotFound();
});

it('returns 409 when the key already exists', function (): void {
    Api::createFlag('new-checkout');

    $this->postJson('/v1/flags', ['key' => 'new-checkout'], Api::adminHeaders())
        ->assertStatus(409);
});

it('enforces the flag cap', function (): void {
    config(['flagpost.limits.flags' => 1]);
    Api::createFlag('first');

    $this->postJson('/v1/flags', ['key' => 'second'], Api::adminHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors('key');
});

it('rejects unknown fields on create', function (): void {
    $this->postJson(
        '/v1/flags',
        ['key' => 'new-checkout', 'enabled' => true],
        Api::adminHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('enabled');
});

dataset('invalid flag keys', [
    'uppercase' => ['New-Checkout'],
    'starts with a dash' => ['-checkout'],
    'starts with a dot' => ['.checkout'],
    'space' => ['new checkout'],
    'slash' => ['new/checkout'],
    'colon' => ['new:checkout'],
    'trailing newline' => ["new-checkout\n"],
    'empty' => [''],
    'too long' => [str_repeat('a', 65)],
]);

it('rejects invalid flag keys', function (string $key): void {
    $this->postJson('/v1/flags', ['key' => $key], Api::adminHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors('key');

    expect(Flag::query()->count())->toBe(0);
})->with('invalid flag keys');

it('accepts a 64 character key', function (): void {
    $key = str_repeat('a', 64);

    $this->postJson('/v1/flags', ['key' => $key], Api::adminHeaders())
        ->assertCreated()
        ->assertJsonPath('key', $key);
});

it('limits the description to 500 characters', function (): void {
    $this->postJson(
        '/v1/flags',
        ['key' => 'new-checkout', 'description' => str_repeat('a', 500)],
        Api::adminHeaders(),
    )->assertCreated();

    $this->postJson(
        '/v1/flags',
        ['key' => 'other', 'description' => str_repeat('a', 501)],
        Api::adminHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('description');
});

it('does not leak configuration rows when creation fails', function (): void {
    Api::createFlag('new-checkout');

    $this->postJson('/v1/flags', ['key' => 'new-checkout'], Api::adminHeaders())
        ->assertStatus(409);

    expect(DB::table('flag_environment_configs')->count())->toBe(3);
});
