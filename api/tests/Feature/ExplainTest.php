<?php

declare(strict_types=1);

use App\Evaluation\Bucketing;
use Tests\Contract\ContractFile;
use Tests\Support\Api;

dataset('stored evaluation cases', function (): array {
    $dataset = [];

    foreach (ContractFile::cases() as $case) {
        if ($case['flag'] === null) {
            continue;
        }

        $dataset[$case['id'].' '.$case['name']] = [$case];
    }

    return $dataset;
});

it('explains every shared evaluation case that has a stored flag', function (array $case): void {
    $flag = Api::createFlag($case['flagKey']);

    /** @var array{enabled: bool, percentage: int, allowList: list<string>} $config */
    $config = $case['flag'];

    Api::configure(
        $flag,
        'prod',
        $config['enabled'],
        $config['percentage'],
        $config['allowList'],
    );

    $response = $this->postJson(
        "/v1/flags/{$case['flagKey']}/environments/prod/explain",
        ['userId' => $case['userId'], 'defaultValue' => $case['defaultValue']],
        Api::adminHeaders(),
    );

    /** @var array{value: bool, reason: string} $expected */
    $expected = $case['expected'];

    $response
        ->assertOk()
        ->assertJsonPath('value', $expected['value'])
        ->assertJsonPath('reason', $expected['reason'])
        ->assertJsonStructure(['value', 'reason', 'bucket']);

    $bucket = $response->json('bucket');

    if (in_array($expected['reason'], ['PERCENTAGE_IN', 'PERCENTAGE_OUT'], true)) {
        expect($bucket)->toBeInt();
        expect($bucket)->toBe(Bucketing::bucket($case['flagKey'], (string) $case['userId']));
    } else {
        expect($bucket)->toBeNull();
    }
})->with('stored evaluation cases');

it('explains the documented example with a capital A', function (): void {
    $flag = Api::createFlag('new-checkout');
    Api::configure($flag, 'prod', true, 20, []);

    $this->postJson(
        '/v1/flags/new-checkout/environments/prod/explain',
        ['userId' => 'Alice', 'defaultValue' => false],
        Api::adminHeaders(),
    )
        ->assertOk()
        ->assertExactJson([
            'value' => true,
            'reason' => 'PERCENTAGE_IN',
            'bucket' => 17,
        ]);
});

it('prefers the allow list over the percentage for the lowercase alice', function (): void {
    $flag = Api::createFlag('new-checkout');
    Api::configure($flag, 'prod', true, 20, ['alice']);

    $this->postJson(
        '/v1/flags/new-checkout/environments/prod/explain',
        ['userId' => 'alice'],
        Api::adminHeaders(),
    )
        ->assertOk()
        ->assertExactJson([
            'value' => true,
            'reason' => 'ALLOW_LIST',
            'bucket' => null,
        ]);
});

it('defaults defaultValue to false', function (): void {
    $flag = Api::createFlag('new-checkout');
    Api::configure($flag, 'prod', true, 50, []);

    $response = $this->postJson(
        '/v1/flags/new-checkout/environments/prod/explain',
        ['userId' => 'user-116'],
        Api::adminHeaders(),
    );

    $response->assertOk()->assertJsonPath('value', false)->assertJsonPath('reason', 'PERCENTAGE_OUT');
});

it('uses defaultValue when the user id is missing', function (): void {
    $flag = Api::createFlag('new-checkout');
    Api::configure($flag, 'prod', true, 50, []);

    $this->postJson(
        '/v1/flags/new-checkout/environments/prod/explain',
        ['defaultValue' => true],
        Api::adminHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('value', true)
        ->assertJsonPath('reason', 'MISSING_USER_ID')
        ->assertJsonPath('bucket', null);
});

it('returns 404 for an unknown flag or environment in explain', function (): void {
    Api::createFlag('new-checkout');

    $this->postJson(
        '/v1/flags/missing/environments/prod/explain',
        [],
        Api::adminHeaders(),
    )->assertNotFound();

    $this->postJson(
        '/v1/flags/new-checkout/environments/qa/explain',
        [],
        Api::adminHeaders(),
    )->assertNotFound();
});

it('rejects unknown fields and a non-boolean defaultValue in explain', function (): void {
    Api::createFlag('new-checkout');

    $this->postJson(
        '/v1/flags/new-checkout/environments/prod/explain',
        ['userId' => 'alice', 'extra' => true],
        Api::adminHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('extra');

    $this->postJson(
        '/v1/flags/new-checkout/environments/prod/explain',
        ['defaultValue' => 'true'],
        Api::adminHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('defaultValue');

    $this->postJson(
        '/v1/flags/new-checkout/environments/prod/explain',
        ['defaultValue' => ''],
        Api::adminHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('defaultValue');

    $this->postJson(
        '/v1/flags/new-checkout/environments/prod/explain',
        ['userId' => 42],
        Api::adminHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('userId');
});

it('does not modify the flag when explaining', function (): void {
    $flag = Api::createFlag('new-checkout');
    Api::configure($flag, 'prod', true, 50, ['alice']);
    $this->travel(2)->seconds();

    $before = $this->getJson('/v1/flags/new-checkout', Api::adminHeaders())->json();

    $this->postJson(
        '/v1/flags/new-checkout/environments/prod/explain',
        ['userId' => 'alice'],
        Api::adminHeaders(),
    )->assertOk();

    expect($this->getJson('/v1/flags/new-checkout', Api::adminHeaders())->json())->toBe($before);
});
