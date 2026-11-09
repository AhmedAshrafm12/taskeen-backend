<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $this->assertIsolatedTestDatabase($app);

        return $app;
    }

    protected function assertIsolatedTestDatabase(Application $app): void
    {
        $connection = $app['config']->get('database.default');
        $driver = $app['config']->get('database.connections.'.$connection.'.driver');
        $database = $app['config']->get('database.connections.'.$connection.'.database');

        if ($driver !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException('Feature tests require SQLite :memory:. Check phpunit.xml and clear stale configuration caches before running tests.');
        }
    }
}
