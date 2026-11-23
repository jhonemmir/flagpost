<?php

declare(strict_types=1);

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case
|--------------------------------------------------------------------------
|
| Feature tests boot the Laravel application. Unit tests do not: the
| evaluation core is plain PHP and must stay framework-free.
|
*/

pest()->extend(TestCase::class)->in('Feature');
