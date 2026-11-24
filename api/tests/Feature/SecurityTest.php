<?php

declare(strict_types=1);

use App\Logging\ScrubSecretsProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\Api;

it('scrubs credentials from the request after authentication', function (): void {
    $environmentKey = 'fp_prod_'.str_repeat('a', 32);

    $this->getJson('/v1/flags', Api::adminHeaders() + ['X-Api-Key' => $environmentKey])
        ->assertOk();

    expect(request()->headers->get('Authorization'))->toBe('[REDACTED]')
        ->and(request()->headers->get('X-Api-Key'))->toBe('[REDACTED]')
        ->and(request()->server->get('HTTP_AUTHORIZATION'))->toBe('[REDACTED]')
        ->and(request()->server->get('HTTP_X_API_KEY'))->toBe('[REDACTED]');
});

it('scrubs credentials from SDK requests', function (): void {
    $plainKey = Api::environmentKey('prod')['plainKey'];

    $this->getJson('/v1/sdk/flags', ['X-Api-Key' => $plainKey])
        ->assertOk();

    expect(request()->headers->get('X-Api-Key'))->toBe('[REDACTED]')
        ->and(request()->server->get('HTTP_X_API_KEY'))->toBe('[REDACTED]');
});

it('redacts credentials from log records', function (): void {
    $secret = 'fp_prod_'.str_repeat('b', 32);

    request()->headers->set('X-Api-Key', $secret);

    $record = (new ScrubSecretsProcessor)(new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Error,
        message: 'request failed with '.$secret,
        context: [
            'headers' => [
                'x-api-key' => [$secret],
            ],
            'nested' => ['value' => 'Bearer '.$secret],
        ],
        extra: [],
    ));

    expect($record->message)->not->toContain($secret)
        ->and($record->message)->toContain('[REDACTED]')
        ->and($record->context['headers']['x-api-key'][0])->toBe('[REDACTED]')
        ->and($record->context['nested']['value'])->toBe('Bearer [REDACTED]');
});
