<?php

declare(strict_types=1);

namespace App\Evaluation;

use LogicException;

/**
 * Deterministic bucketing shared with the C# SDK.
 *
 * The digest input is the UTF-8 encoding of "{flagKey}:{userId}". The first
 * four bytes are read as a big-endian unsigned 32-bit integer and reduced
 * modulo 100.
 */
final class Bucketing
{
    public static function bucket(string $flagKey, string $userId): int
    {
        return self::bucketDetailed($flagKey, $userId)['bucket'];
    }

    /**
     * @return array{hex: string, value: int, bucket: int}
     */
    public static function bucketDetailed(string $flagKey, string $userId): array
    {
        $digest = hash('sha256', $flagKey.':'.$userId, true);
        $firstFourBytes = substr($digest, 0, 4);

        $unpacked = unpack('N', $firstFourBytes);

        if ($unpacked === false || ! is_int($unpacked[1])) {
            // unpack('N', ...) cannot fail on a four-byte string.
            throw new LogicException('Unable to decode the SHA-256 prefix.');
        }

        $value = $unpacked[1];

        return [
            'hex' => bin2hex($firstFourBytes),
            'value' => $value,
            'bucket' => $value % 100,
        ];
    }
}
