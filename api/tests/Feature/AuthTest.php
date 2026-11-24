<?php

declare(strict_types=1);

use Tests\Support\Api;

it('answers the health check without authentication', function (): void {
    $this->get('/up')->assertOk();
});

it('rejects management requests without a credential', function (): void {
    $response = $this->getJson('/v1/flags');

    $response
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 401)
        ->assertJsonPath('title', 'Unauthorized');
});

it('rejects an unknown admin key', function (): void {
    $this->getJson('/v1/flags', ['Authorization' => 'Bearer not-the-admin-key'])
        ->assertStatus(401);
});

it('accepts the admin key on management endpoints', function (): void {
    $this->getJson('/v1/flags', Api::adminHeaders())->assertOk();
});

it('returns 403 when a valid environment key is used for management', function (): void {
    $issued = Api::environmentKey('prod');

    $this->getJson('/v1/flags', ['X-Api-Key' => $issued['plainKey']])
        ->assertStatus(403)
        ->assertJsonPath('status', 403);
});

it('returns 401 when a revoked environment key is used for management', function (): void {
    $issued = Api::environmentKey('prod');
    $issued['apiKey']->update(['revoked_at' => now()]);

    $this->getJson('/v1/flags', ['X-Api-Key' => $issued['plainKey']])
        ->assertStatus(401);
});

it('returns 403 when the admin key is used on the SDK endpoint', function (): void {
    $this->getJson('/v1/sdk/flags', ['Authorization' => 'Bearer '.Api::ADMIN_KEY])
        ->assertStatus(403);
});

it('returns 500 without leaking details when the admin key is not configured', function (): void {
    config(['flagpost.admin_key' => '']);

    $response = $this->getJson('/v1/flags', Api::adminHeaders());

    $response
        ->assertStatus(500)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 500)
        ->assertJsonPath('title', 'Internal server error');

    $body = (string) $response->getContent();

    expect($body)->not->toContain(Api::ADMIN_KEY);
    expect($body)->not->toContain('#0');
    expect($body)->not->toContain('Stack trace');
});

it('does not accept the admin key through X-Api-Key', function (): void {
    $this->getJson('/v1/flags', ['X-Api-Key' => Api::ADMIN_KEY])->assertStatus(401);
});

it('does not echo an unknown environment key in the error body', function (): void {
    $plainKey = 'fp_prod_'.str_repeat('a', 32);

    $response = $this->getJson('/v1/sdk/flags', Api::sdkHeaders($plainKey))->assertStatus(401);

    expect((string) $response->getContent())->not->toContain($plainKey);
});

it('never returns environment keys from management responses', function (): void {
    $issued = Api::environmentKey('prod');
    Api::createFlag('new-checkout');

    $list = $this->getJson('/v1/flags', Api::adminHeaders())->assertOk();
    $show = $this->getJson('/v1/flags/new-checkout', Api::adminHeaders())->assertOk();

    expect((string) $list->getContent())->not->toContain($issued['plainKey']);
    expect((string) $show->getContent())->not->toContain($issued['plainKey']);
});
