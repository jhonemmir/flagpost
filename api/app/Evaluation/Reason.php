<?php

declare(strict_types=1);

namespace App\Evaluation;

/**
 * Stable, machine-readable reason codes. They never change within a major version.
 */
enum Reason: string
{
    case NotFound = 'NOT_FOUND';
    case Disabled = 'DISABLED';
    case AllowList = 'ALLOW_LIST';
    case FullRollout = 'FULL_ROLLOUT';
    case NoRollout = 'NO_ROLLOUT';
    case MissingUserId = 'MISSING_USER_ID';
    case PercentageIn = 'PERCENTAGE_IN';
    case PercentageOut = 'PERCENTAGE_OUT';
}
