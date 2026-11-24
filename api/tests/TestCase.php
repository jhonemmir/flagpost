<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'flagpost.admin_key' => 'test-admin-key-0123456789abcdef',
            'flagpost.rate_limits.management_per_minute' => 60,
            'flagpost.rate_limits.sdk_per_minute' => 120,
        ]);

        Cache::flush();
    }
}
