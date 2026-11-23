<?php

declare(strict_types=1);

use App\Evaluation\Bucketing;
use App\Evaluation\Evaluator;
use App\Evaluation\FlagConfig;
use App\Evaluation\Reason;

/**
 * Independent oracle: decodes the digest byte by byte instead of relying on
 * the production unpack('N', ...) call.
 */
function flagpostOracleBucket(string $flagKey, string $userId): int
{
    $digest = hash('sha256', $flagKey.':'.$userId, true);
    $bytes = unpack('C*', $digest);

    if ($bytes === false) {
        throw new RuntimeException('Unable to decode the oracle digest.');
    }

    $bytes = array_values($bytes);
    $value = ($bytes[0] << 24) | ($bytes[1] << 16) | ($bytes[2] << 8) | $bytes[3];

    return $value % 100;
}

it('agrees with an independent oracle for 1000 users and every percentage', function (): void {
    $empty = new FlagConfig(true, 50, []);

    for ($i = 0; $i < 1000; $i++) {
        $userId = "user-{$i}";
        $oracle = flagpostOracleBucket('new-checkout', $userId);

        $this->assertSame($oracle, Bucketing::bucket('new-checkout', $userId));

        for ($percentage = 1; $percentage < 100; $percentage++) {
            $flag = new FlagConfig($empty->enabled, $percentage, $empty->allowList);
            $result = Evaluator::evaluate('new-checkout', $flag, $userId, false);

            $this->assertSame($oracle < $percentage, $result->value);
            $this->assertSame(
                $oracle < $percentage ? Reason::PercentageIn : Reason::PercentageOut,
                $result->reason,
            );
        }
    }
});

it('never removes a user when the percentage grows', function (): void {
    for ($i = 0; $i < 1000; $i++) {
        $userId = "user-{$i}";
        $wasInside = false;

        for ($percentage = 0; $percentage <= 100; $percentage++) {
            $result = Evaluator::evaluate(
                'new-checkout',
                new FlagConfig(true, $percentage, []),
                $userId,
                false,
            );

            if ($wasInside) {
                $this->assertTrue($result->value, "user {$userId} left at {$percentage}%");
            }

            $wasInside = $wasInside || $result->value;
        }
    }
});

it('applies the steps in order', function (): void {
    $disabled = Evaluator::evaluate('new-checkout', new FlagConfig(false, 100, ['alice']), 'alice', true);
    $this->assertFalse($disabled->value);
    $this->assertSame(Reason::Disabled, $disabled->reason);

    $allowed = Evaluator::evaluate('new-checkout', new FlagConfig(true, 100, ['alice']), 'alice', false);
    $this->assertTrue($allowed->value);
    $this->assertSame(Reason::AllowList, $allowed->reason);

    $full = Evaluator::evaluate('new-checkout', new FlagConfig(true, 100, []), null, false);
    $this->assertTrue($full->value);
    $this->assertSame(Reason::FullRollout, $full->reason);

    $none = Evaluator::evaluate('new-checkout', new FlagConfig(true, 0, []), null, true);
    $this->assertFalse($none->value);
    $this->assertSame(Reason::NoRollout, $none->reason);

    $missing = Evaluator::evaluate('new-checkout', new FlagConfig(true, 50, []), null, true);
    $this->assertTrue($missing->value);
    $this->assertSame(Reason::MissingUserId, $missing->reason);

    $unknown = Evaluator::evaluate('new-checkout', null, 'alice', true);
    $this->assertTrue($unknown->value);
    $this->assertSame(Reason::NotFound, $unknown->reason);
});

it('returns a result for awkward inputs without throwing', function (): void {
    $userIds = [null, '', ' ', 'a:b', 'ñandú', '🚀', '日本語', str_repeat('u', 256)];
    $flags = [null, new FlagConfig(true, 0, []), new FlagConfig(true, 1, []), new FlagConfig(true, 50, [$userIds[4]]), new FlagConfig(true, 99, []), new FlagConfig(true, 100, [])];

    foreach ($flags as $flag) {
        foreach ($userIds as $userId) {
            foreach ([false, true] as $defaultValue) {
                $result = Evaluator::evaluate('new-checkout', $flag, $userId, $defaultValue);

                $this->assertContains($result->reason, Reason::cases());
            }
        }
    }
});
