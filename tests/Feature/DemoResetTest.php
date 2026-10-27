<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Support\Demo;
use App\Support\DemoSnapshot;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** gaiacp:demo-reset builds the demo data in a process of its own and copies it in: no test transaction around it. */
class DemoResetTest extends TestCase
{
    use DatabaseMigrations;

    private function demo(): void
    {
        config(['gaiacp.demo.enabled' => true, 'gaiacp.demo.reset_minutes' => 10]);
        app()->forgetInstance(PluginManager::class);
        Demo::boot();
    }

    public function test_a_reset_puts_the_snapshot_back_and_keeps_who_is_signed_in(): void
    {
        $this->demo();
        $snapshot = app(DemoSnapshot::class);
        $backup = is_file($snapshot->path()) ? $snapshot->path().'.test-backup' : null;
        if ($backup) {
            File::move($snapshot->path(), $backup);
        }

        try {
            $snapshot->build();
            $this->assertTrue($snapshot->current());

            Client::factory()->create(['first_name' => 'Visitor']);
            DB::table('sessions')->insert(['id' => 'abc', 'payload' => '', 'last_activity' => time()]);

            $this->artisan('gaiacp:demo-reset')->assertSuccessful();

            $this->assertSame(23, Client::query()->count());
            $this->assertFalse(Client::query()->where('first_name', 'Visitor')->exists());
            $this->assertTrue(DB::table('sessions')->where('id', 'abc')->exists());
            $this->assertTrue(User::query()->where('email', Demo::ADMIN_EMAIL)->exists());
            $this->assertEqualsWithDelta(time(), $snapshot->restoredAt(), 5);
        } finally {
            $this->artisan('migrate:fresh'); // empty, so the rollback after the test runs
            File::delete([$snapshot->path(), dirname($snapshot->path()).'/demo.json', dirname($snapshot->path()).'/restored']);
            if ($backup) {
                File::move($backup, $snapshot->path());
            }
        }
    }
}
