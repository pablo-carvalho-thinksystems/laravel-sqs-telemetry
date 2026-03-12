<?php

namespace Pablocarvalho\SqsTelemetry\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Pablocarvalho\SqsTelemetry\SqsTelemetryServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            SqsTelemetryServiceProvider::class,
        ];
    }
}
