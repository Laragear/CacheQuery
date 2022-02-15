<?php

namespace Tests;

use Laragear\CacheQuery\CacheQueryServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [CacheQueryServiceProvider::class];
    }
}