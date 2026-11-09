<?php

namespace Tests\Feature;

use RuntimeException;
use Tests\TestCase;

class TestDatabaseIsolationTest extends TestCase
{
    public function test_mysql_is_rejected_before_any_test_database_reset(): void
    {
        config(['database.default' => 'mysql']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Feature tests require SQLite :memory:');
        $this->assertIsolatedTestDatabase($this->app);
    }

    public function test_file_backed_sqlite_is_rejected_before_any_test_database_reset(): void
    {
        config(['database.connections.sqlite.database' => '/path/that/must/not/be/created.sqlite']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Feature tests require SQLite :memory:');
        $this->assertIsolatedTestDatabase($this->app);
    }
}
