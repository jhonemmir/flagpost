<?php

declare(strict_types=1);

use App\Evaluation\Bucketing;
use Tests\Contract\ContractFile;

dataset('bucket_vectors', function (): array {
    $dataset = [];

    foreach (ContractFile::vectors() as $vector) {
        $dataset[$vector['id']] = [
            $vector['flagKey'],
            $vector['userId'],
            $vector['first4BytesHex'],
            $vector['bucket'],
        ];
    }

    return $dataset;
});

it('reproduces every shared bucket vector', function (
    string $flagKey,
    string $userId,
    string $first4BytesHex,
    int $expectedBucket,
): void {
    $actual = Bucketing::bucketDetailed($flagKey, $userId);

    expect($actual['hex'])->toBe($first4BytesHex)
        ->and($actual['value'])->toBe((int) hexdec($first4BytesHex))
        ->and($actual['bucket'])->toBe($expectedBucket);
})->with('bucket_vectors');
