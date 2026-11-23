<?php

declare(strict_types=1);

namespace Tests\Contract;

use JsonException;
use RuntimeException;

/**
 * Reads spec/vectors/evaluation.json, the single source of truth shared with
 * the C# SDK.
 */
final class ContractFile
{
    /**
     * @var array{
     *     vectorsVersion: int,
     *     bucketVectors: list<array{
     *         id: string, flagKey: string, userId: string,
     *         first4BytesHex: string, bucket: int
     *     }>,
     *     evaluationCases: list<array{
     *         id: string, name: string, flagKey: string,
     *         flag: array{enabled: bool, percentage: int, allowList: list<string>}|null,
     *         userId: string|null, defaultValue: bool,
     *         expected: array{value: bool, reason: string}
     *     }>
     * }|null
     */
    private static ?array $contract = null;

    /**
     * @return list<array{
     *     id: string, flagKey: string, userId: string,
     *     first4BytesHex: string, bucket: int
     * }>
     */
    public static function vectors(): array
    {
        return self::load()['bucketVectors'];
    }

    /**
     * @return list<array{
     *     id: string, name: string, flagKey: string,
     *     flag: array{enabled: bool, percentage: int, allowList: list<string>}|null,
     *     userId: string|null, defaultValue: bool,
     *     expected: array{value: bool, reason: string}
     * }>
     */
    public static function cases(): array
    {
        return self::load()['evaluationCases'];
    }

    /**
     * @return array{
     *     vectorsVersion: int,
     *     bucketVectors: list<array{
     *         id: string, flagKey: string, userId: string,
     *         first4BytesHex: string, bucket: int
     *     }>,
     *     evaluationCases: list<array{
     *         id: string, name: string, flagKey: string,
     *         flag: array{enabled: bool, percentage: int, allowList: list<string>}|null,
     *         userId: string|null, defaultValue: bool,
     *         expected: array{value: bool, reason: string}
     *     }>
     * }
     */
    private static function load(): array
    {
        if (self::$contract !== null) {
            return self::$contract;
        }

        $path = dirname(__DIR__, 2).'/../spec/vectors/evaluation.json';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read the contract file at {$path}.");
        }

        try {
            /** @var array{vectorsVersion: int, bucketVectors: list<array{id: string, flagKey: string, userId: string, first4BytesHex: string, bucket: int}>, evaluationCases: list<array{id: string, name: string, flagKey: string, flag: array{enabled: bool, percentage: int, allowList: list<string>}|null, userId: string|null, defaultValue: bool, expected: array{value: bool, reason: string}}>} $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("The contract file at {$path} is not valid JSON.", 0, $exception);
        }

        if ($decoded['vectorsVersion'] !== 1) {
            throw new RuntimeException('Unsupported vectorsVersion: '.$decoded['vectorsVersion']);
        }

        return self::$contract = $decoded;
    }
}
