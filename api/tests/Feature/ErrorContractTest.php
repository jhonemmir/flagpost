<?php

declare(strict_types=1);

use Tests\Support\Api;

it('returns 400 for malformed JSON', function (): void {
    $response = Api::callRaw(
        $this,
        'POST',
        '/v1/flags',
        '{"key":',
        Api::adminHeaders(),
    );

    $response
        ->assertStatus(400)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 400)
        ->assertJsonPath('title', 'Bad request');

    expect($response->json('type'))->toEndWith('/problems/bad-request');
});

it('returns 400 for valid JSON that is not an object', function (string $body): void {
    Api::callRaw(
        $this,
        'POST',
        '/v1/flags',
        $body,
        Api::adminHeaders(),
    )
        ->assertStatus(400)
        ->assertHeader('Content-Type', 'application/problem+json');
})->with([
    'string' => ['"hello"'],
    'number' => ['123'],
    'boolean' => ['true'],
    'null' => ['null'],
]);

it('treats an empty JSON array as an empty body', function (): void {
    $this->postJson('/v1/flags', [], Api::adminHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors('key');
});

it('returns 415 when a body is not JSON', function (): void {
    $response = Api::callRaw(
        $this,
        'POST',
        '/v1/flags',
        '{"key":"new-checkout"}',
        Api::adminHeaders(),
        'text/plain',
    );

    $response
        ->assertStatus(415)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 415);
});

it('returns 415 when a body has no content type', function (): void {
    Api::callRaw(
        $this,
        'POST',
        '/v1/flags',
        '{"key":"new-checkout"}',
        Api::adminHeaders(),
        '',
    )
        ->assertStatus(415);
});

it('accepts a JSON content type with a charset parameter', function (): void {
    Api::callRaw(
        $this,
        'POST',
        '/v1/flags',
        '{"key":"new-checkout"}',
        Api::adminHeaders(),
        'application/json; charset=utf-8',
    )->assertCreated();
});

it('returns 413 for a body over 1 MiB', function (): void {
    $response = Api::callRaw(
        $this,
        'POST',
        '/v1/flags',
        str_repeat('a', 1_048_577),
        Api::adminHeaders(),
    );

    $response
        ->assertStatus(413)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 413);
});

it('returns 404 for unknown resources', function (): void {
    Api::createFlag('new-checkout');

    $this->getJson('/v1/flags/missing', Api::adminHeaders())
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 404);

    $this->putJson(
        '/v1/flags/new-checkout/environments/qa',
        ['enabled' => true, 'percentage' => 50, 'allowList' => []],
        Api::adminHeaders(),
    )->assertStatus(404);
});

it('returns 405 for a method that is not allowed', function (): void {
    $response = $this->putJson('/v1/flags', [], Api::adminHeaders());

    $response
        ->assertStatus(405)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 405);
});

it('returns 409 for a duplicate flag', function (): void {
    Api::createFlag('new-checkout');

    Api::callRaw(
        $this,
        'POST',
        '/v1/flags',
        '{"key":"new-checkout"}',
        Api::adminHeaders(),
    )
        ->assertStatus(409)
        ->assertJsonPath('status', 409);
});

it('returns 422 with field errors and without echoing values', function (): void {
    $response = $this->postJson(
        '/v1/flags',
        ['key' => 'Not-A-Key'],
        Api::adminHeaders(),
    );

    $response
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 422)
        ->assertJsonPath('title', 'Validation failed')
        ->assertJsonPath('detail', 'One or more fields are invalid.')
        ->assertJsonStructure(['type', 'title', 'status', 'detail', 'errors' => ['key']]);

    expect($response->json('type'))->toEndWith('/problems/validation-error');
    expect((string) $response->getContent())->not->toContain('Not-A-Key');
});

it('rate limits management requests per IP', function (): void {
    config(['flagpost.rate_limits.management_per_minute' => 1]);

    $this->getJson('/v1/flags', Api::adminHeaders())->assertOk();

    $limited = $this->getJson('/v1/flags', Api::adminHeaders())
        ->assertStatus(429)
        ->assertHeader('Content-Type', 'application/problem+json');

    expect($limited->headers->has('Retry-After'))->toBeTrue();
    expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
});

it('exposes every problem detail with the RFC 9457 required members', function (int $status, string $method, string $uri, string $body, string $contentType): void {
    $response = Api::callRaw(
        $this,
        $method,
        $uri,
        $body,
        Api::adminHeaders(),
        $contentType,
    );

    $response
        ->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonStructure(['type', 'title', 'status', 'detail'])
        ->assertJsonPath('status', $status);

    expect($response->json('type'))->toBeString();
    expect($response->json('title'))->toBeString();
    expect($response->json('detail'))->toBeString();
})->with([
    '400 malformed JSON' => [400, 'POST', '/v1/flags', '{', 'application/json'],
    '415 wrong media type' => [415, 'POST', '/v1/flags', '{}', 'text/plain'],
    '404 unknown flag' => [404, 'GET', '/v1/flags/missing', '', 'application/json'],
    '405 wrong method' => [405, 'PUT', '/v1/flags', '', 'application/json'],
]);
