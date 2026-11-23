<?php

declare(strict_types=1);

namespace App\Evaluation;

/**
 * Configuration of one flag in one environment.
 *
 * The evaluation function trusts this object: the API validates configuration
 * before it is stored.
 */
final readonly class FlagConfig
{
    /**
     * @param  list<string>  $allowList
     */
    public function __construct(
        public bool $enabled,
        public int $percentage,
        public array $allowList,
    ) {}
}
