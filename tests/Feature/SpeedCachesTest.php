<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemHealth as SystemHealthPage;
use App\Models\User;
use App\Support\Health\SpeedCaches;
use App\Support\Health\Status;
use App\Support\Health\SystemHealth;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** System health → Speed fixed from the panel: Optimize now, Use files, and the scheduler on a live site. */
class SpeedCachesTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        // .env and the lock file in a folder of their own, never this checkout's
        $this->dir = sys_get_temp_dir().'/gaiacp-speed-'.uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir.'/.env', "APP_ENV=production\nCACHE_STORE=database\nSESSION_DRIVER=database\n");
        $this->app->useEnvironmentPath($this->dir);
        config(['gaiacp.install_lock' => $this->dir.'/installed']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function speedCheck(string $name)
    {
        return app(SystemHealth::class)->checks()->firstWhere('name', $name);
    }

    public function test_the_health_page_offers_both_fixes(): void
    {
        config(['cache.default' => 'database', 'session.driver' => 'database']);
        $this->assertNotSame([], app(SpeedCaches::class)->missing()); // the test run caches nothing

        $this->actingAs(User::factory()->create(['super_admin' => true]));
        $this->get(SystemHealthPage::getUrl())->assertOk()->assertSee('Optimize now')->assertSee('Use files');
    }

    public function test_optimize_now_builds_the_caches_after_the_response(): void
    {
        $this->mock(SpeedCaches::class, function ($mock) {
            $mock->shouldReceive('missing')->andReturn(['config']);
            $mock->shouldReceive('lastFailure')->andReturn(null);
            $mock->shouldReceive('stale')->andReturn(false);
            $mock->shouldReceive('buildAfterResponse')->once();
        });
        $this->actingAs(User::factory()->create(['super_admin' => true]));

        $page = Livewire::test(SystemHealthPage::class);
        $row = collect($page->instance()->getTable()->getRecords())->search(fn (array $r) => $r['name'] === 'Cached config, routes, components');
        $page->callTableAction('optimize', $row)->assertNotified(__('Optimizing'));
    }

    public function test_use_files_writes_env_and_signs_in_again(): void
    {
        config(['cache.default' => 'database', 'session.driver' => 'database']);
        $this->actingAs(User::factory()->create(['super_admin' => true]));

        $page = Livewire::test(SystemHealthPage::class);
        $row = collect($page->instance()->getTable()->getRecords())->search(fn (array $r) => $r['name'] === 'Cache and sessions');
        $page->callTableAction('useFiles', $row)->assertRedirect(filament()->getLoginUrl());

        $env = (string) file_get_contents($this->dir.'/.env');
        $this->assertStringContainsString("CACHE_STORE=file\n", $env);
        $this->assertStringContainsString("SESSION_DRIVER=file\n", $env);
        $this->assertStringNotContainsString('database', $env);
        $this->assertDatabaseHas('activity_logs', ['event' => 'speed.files']);
    }

    public function test_a_failed_build_clears_what_it_made_and_says_why(): void
    {
        Artisan::shouldReceive('call')->with('optimize')->andThrow(new RuntimeException('Unable to prepare route [x] for serialization.'));
        foreach (['config:clear', 'event:clear', 'route:clear'] as $clear) {
            Artisan::shouldReceive('call')->with($clear)->once();
        }

        $caches = app(SpeedCaches::class);
        $this->assertFalse($caches->build());
        $this->assertSame('Unable to prepare route [x] for serialization.', $caches->lastFailure());
        $this->assertDatabaseHas('activity_logs', ['event' => 'speed.optimize_failed']);

        $check = $this->speedCheck('Cached config, routes, components');
        $this->assertSame(Status::Warning, $check->status);
        $this->assertStringContainsString('Unable to prepare route', $check->hint);
    }

    public function test_the_scheduler_builds_only_on_an_installed_live_site_that_needs_it(): void
    {
        $caches = app(SpeedCaches::class);
        $this->assertFalse($caches->shouldAutoBuild()); // testing, not production

        $this->app['env'] = 'production';
        $this->assertFalse($caches->shouldAutoBuild()); // not installed yet

        touch($this->dir.'/installed');
        $this->assertTrue($caches->shouldAutoBuild());

        config(['gaiacp.speed.auto_optimize' => false]);
        $this->assertFalse($caches->shouldAutoBuild());
        config(['gaiacp.speed.auto_optimize' => true]);

        Cache::put(SpeedCaches::FAILED, 'broken', now()->addDay());
        $this->assertFalse($caches->shouldAutoBuild()); // not every minute after a failure

        $schedule = app(Schedule::class);
        require base_path('routes/console.php');
        $this->assertTrue(collect($schedule->events())->contains(fn (Event $event) => str_contains((string) $event->command, 'gaiacp:optimize')));
    }

    public function test_an_env_saved_after_the_config_was_cached_is_stale(): void
    {
        $cached = $this->dir.'/config.php';
        file_put_contents($cached, '<?php return [];');
        putenv('APP_CONFIG_CACHE='.$cached);
        $_SERVER['APP_CONFIG_CACHE'] = $cached;

        try {
            touch($cached, time() - 60);
            touch($this->dir.'/.env', time());
            $this->assertTrue(app(SpeedCaches::class)->stale());

            touch($cached, time() + 1);
            $this->assertFalse(app(SpeedCaches::class)->stale());
        } finally {
            putenv('APP_CONFIG_CACHE');
            unset($_SERVER['APP_CONFIG_CACHE']);
        }
    }
}
