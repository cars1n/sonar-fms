<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $connection = $app['config']->get('database.default');
        if (! $app->environment('testing') || $app['config']->get("database.connections.{$connection}.database") !== 'fms_test') {
            throw new \LogicException('Tests require the testing environment and disposable fms_test database.');
        }

        return $app;
    }
}
