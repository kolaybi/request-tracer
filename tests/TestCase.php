<?php

namespace KolayBi\RequestTracer\Tests;

use KolayBi\RequestTracer\RequestTracerServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [RequestTracerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('kolaybi.request-tracer.queue_connection', 'sync');
        $app['config']->set('kolaybi.request-tracer.queue', 'default');
    }
}
