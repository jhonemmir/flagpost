<?php

declare(strict_types=1);

namespace App\Support;

final class FlagpostConfig
{
    /**
     * @return list<string>
     */
    public static function environments(): array
    {
        $environments = config()->array('flagpost.environments', ['dev', 'staging', 'prod']);
        $result = [];

        foreach ($environments as $environment) {
            if (is_string($environment)) {
                $result[] = $environment;
            }
        }

        return $result;
    }

    public static function adminKey(): string
    {
        return config()->string('flagpost.admin_key', '');
    }

    public static function schemaVersion(): int
    {
        return config()->integer('flagpost.schema_version', 1);
    }

    public static function limit(string $name): int
    {
        return config()->integer("flagpost.limits.{$name}", 0);
    }

    public static function rateLimit(string $name): int
    {
        return config()->integer("flagpost.rate_limits.{$name}", 0);
    }
}
