<?php

declare(strict_types=1);

namespace App\Evaluation;

final readonly class EvaluationResult
{
    public function __construct(
        public bool $value,
        public Reason $reason,
    ) {}
}
