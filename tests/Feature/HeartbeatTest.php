<?php

namespace Tests\Feature;

use App\Filament\Clusters\Settings\Pages\AutomationSettings;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Health\Heartbeat;
use App\Support\Health\SchedulerMonitor;
use App\Support\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Automation → Ping URL: a monitor outside GaiaCP hears every 5 minutes that the automation runs. */
class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private const OK = 'https://hc-ping.com/abc-123';

    private const FAIL = 'https://kuma.example.com/api/push/tok?status=down&msg={message}';

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response($this->status === 200 ? 'OK' : 'down', $this->status));
    }

    public function test_nothing_is_sent_without_a_ping_url(): void
    {
        $this->artisan('gaiacp:heartbeat')->expectsOutputToContain('No Ping URL is set')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_ping_url_is_called_while_all_is_well(): void
    {
        config(['gaiacp.automation.ping_url' => self::OK]);
        app(SchedulerMonitor::class)->commandFinished('gaiacp:send-reminders', 0);

        $this->artisan('gaiacp:heartbeat')->expectsOutputToContain('Pinged (OK)')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::OK && $request->method() === 'GET');
        $this->assertFalse(Heartbeat::last()['failure']);
    }

    public function test_a_failed_daily_command_or_backup_calls_the_failure_url_with_what_failed(): void
    {
        config(['gaiacp.automation.ping_url' => self::OK, 'gaiacp.automation.ping_fail_url' => self::FAIL, 'gaiacp.backups.enabled' => true]);
        app(SchedulerMonitor::class)->commandFinished('gaiacp:suspend-overdue', 1);
        ActivityLog::record('backup.created', 'Backup ok');
        ActivityLog::record('backup.failed', 'Backup failed: disk full');

        $this->artisan('gaiacp:heartbeat')->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://kuma.example.com/api/push/tok?status=down&msg=')
            && str_contains(urldecode($request->url()), 'gaiacp:suspend-overdue failed; the last backup failed'));
        Http::assertNotSent(fn (Request $request) => $request->url() === self::OK);
        $this->assertTrue(Heartbeat::last()['failure']);
    }

    public function test_without_a_failure_url_a_failure_sends_no_ping_so_the_monitor_alerts(): void
    {
        config(['gaiacp.automation.ping_url' => self::OK]);
        app(SchedulerMonitor::class)->commandFinished('gaiacp:sync-domains', 1);

        $this->artisan('gaiacp:heartbeat')->expectsOutputToContain('No ping sent')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertFalse(Heartbeat::last()['sent']);
    }

    public function test_an_old_failure_no_longer_counts(): void
    {
        config(['gaiacp.automation.ping_url' => self::OK]);
        $this->travelTo(now()->subDays(2));
        app(SchedulerMonitor::class)->commandFinished('gaiacp:sync-domains', 1);
        $this->travelBack();

        $this->assertSame([], app(Heartbeat::class)->problems());
    }

    public function test_a_monitor_that_does_not_answer_is_reported_not_thrown(): void
    {
        config(['gaiacp.automation.ping_url' => self::OK]);
        $this->status = 503;

        $this->artisan('gaiacp:heartbeat')->expectsOutputToContain('The ping failed')->assertFailed();
        $this->assertNotNull(Heartbeat::last()['error']);
    }

    public function test_it_runs_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'gaiacp:heartbeat'));

        $this->assertSame('*/5 * * * *', $event->expression);
    }

    public function test_the_urls_are_stored_encrypted_and_never_sent_back(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(AutomationSettings::class)
            ->fillForm(['automation' => ['ping_url' => self::OK]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('data.automation.ping_url', null);

        $this->assertStringNotContainsString('abc-123', Setting::query()->find('automation.ping_url')->value);

        config(['gaiacp.automation.ping_url' => null]);
        Settings::apply();
        $this->assertSame(self::OK, config('gaiacp.automation.ping_url'));

        Livewire::test(AutomationSettings::class)
            ->assertSet('data.automation.ping_url', null)
            ->assertSee('No ping sent yet')
            ->call('save')
            ->assertHasNoFormErrors();

        Settings::apply();
        $this->assertSame(self::OK, config('gaiacp.automation.ping_url'));
    }
}
