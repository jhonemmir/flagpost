<?php

declare(strict_types=1);

use App\Evaluation\Evaluator;
use App\Evaluation\FlagConfig;
use Tests\Contract\ContractFile;

dataset('evaluation_cases', function (): array {
    $dataset = [];

    foreach (ContractFile::cases() as $case) {
        $flag = $case['flag'];

        $dataset[$case['id']] = [
            $case['flagKey'],
            $flag === null
                ? null
                : new FlagConfig($flag['enabled'], $flag['percentage'], $flag['allowList']),
            $case['userId'],
            $case['defaultValue'],
            $case['expected']['value'],
            $case['expected']['reason'],
        ];
    }

    return $dataset;
});

it('matches every shared evaluation case', function (
    string $flagKey,
    ?FlagConfig $flag,
    ?string $userId,
    bool $defaultValue,
    bool $expectedValue,
    string $expectedReason,
): void {
    $result = Evaluator::evaluate($flagKey, $flag, $userId, $defaultValue);

    expect($result->value)->toBe($expectedValue)
        ->and($result->reason->value)->toBe($expectedReason);
})->with('evaluation_cases');
