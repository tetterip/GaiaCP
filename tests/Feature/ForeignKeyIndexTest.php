<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SQLite does not index foreign keys by itself (MySQL and MariaDB do): without one, a client's page or a report reads a
 * whole table for each row once there are thousands of them. Add ->index() next to a new foreign key.
 */
class ForeignKeyIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_foreign_key_has_an_index(): void
    {
        $migration = require database_path('migrations/2026_11_07_000100_index_foreign_keys.php');

        $this->assertSame([], array_map(fn (array $key) => implode('.', $key), $migration::missing()));
    }
}
