<?php

declare(strict_types=1);

namespace App\Evaluation;

/**
 * Pure evaluation of one flag for one user.
 *
 * Steps are evaluated in order and the first one that applies returns. The
 * function is deterministic and returns a result for every input combination.
 */
final class Evaluator
{
    public static function evaluate(
        string $flagKey,
        ?FlagConfig $flag,
        ?string $userId,
        bool $defaultValue,
    ): EvaluationResult {
        if ($flag === null) {
            return new EvaluationResult($defaultValue, Reason::NotFound);
        }

        if (! $flag->enabled) {
            return new EvaluationResult(false, Reason::Disabled);
        }

        if ($userId !== null && $userId !== '' && in_array($userId, $flag->allowList, true)) {
            return new EvaluationResult(true, Reason::AllowList);
        }

        if ($flag->percentage === 100) {
            return new EvaluationResult(true, Reason::FullRollout);
        }

        if ($flag->percentage === 0) {
            return new EvaluationResult(false, Reason::NoRollout);
        }

        if ($userId === null || $userId === '') {
            return new EvaluationResult($defaultValue, Reason::MissingUserId);
        }

        $inside = Bucketing::bucket($flagKey, $userId) < $flag->percentage;

        return $inside
            ? new EvaluationResult(true, Reason::PercentageIn)
            : new EvaluationResult(false, Reason::PercentageOut);
    }
}
