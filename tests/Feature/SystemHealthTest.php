<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemHealth as SystemHealthPage;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ModuleLog;
use App\Models\Registrar;
use App\Models\Server;
use App\Models\User;
use App\Support\Health\Check;
use App\Support\Health\SchedulerMonitor;
use App\Support\Health\Status;
use App\Support\Health\SystemHealth;
use App\Support\Health\TableSizes;
use App\Support\SvgLogo;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\FakesWhm;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use FakesWhm;
    use RefreshDatabase;

    /** What `php artisan schedule:run` from cron announces (Artisan::call fires no command events), without running the due tasks. */
    private function cronRuns(): void
    {
        event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));
    }

    private function check(string $name): Check
    {
        $check = app(SystemHealth::class)->checks()->firstWhere('name', $name);
        $this->assertNotNull($check, "No check named {$name}");

        return $check;
    }

    public function test_page_is_under_settings_and_renders(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/settings')->assertOk()->assertSee(SystemHealthPage::getUrl());
        $this->get(SystemHealthPage::getUrl())
            ->assertOk()
            ->assertSee('PHP version')
            ->assertSee(PHP_VERSION)
            ->assertSee('Required extensions')
            ->assertSee('Cron (scheduler)')
            ->assertSee('Biggest tables');
    }

    public function test_biggest_tables_lists_rows_and_marks_the_ones_left_out_of_backups(): void
    {
        $this->actingAs(User::factory()->create());
        Client::factory()->count(3)->create();
        ModuleLog::create(['module' => 'plesk', 'action' => 'get', 'url' => 'POST x', 'response' => str_repeat('reply ', 50000), 'created_at' => now()]);

        $tables = app(TableSizes::class)->biggest(100);
        $this->assertSame(3, $tables->firstWhere('table', 'clients')['rows']);
        $this->assertSame('module_logs', $tables->first()['table'], 'biggest first');

        $this->get(SystemHealthPage::getUrl())->assertOk()->assertSee('module_logs')->assertSee('left out of backups');
    }

    public function test_php_version_and_extensions_are_checked(): void
    {
        $this->assertSame(Status::Ok, $this->check('PHP version')->status);
        $this->assertSame(PHP_VERSION, $this->check('PHP version')->value);

        $extensions = $this->check('Required extensions');
        $this->assertSame(Status::Ok, $extensions->status);
        $this->assertStringContainsString('curl', $extensions->value);
        $this->assertStringContainsString('intl', $extensions->value);
        $this->assertStringContainsString('pdo_sqlite', $extensions->value);
    }

    public function test_cron_is_a_problem_until_schedule_run_is_seen(): void
    {
        $cron = $this->check('Cron (scheduler)');
        $this->assertSame(Status::Error, $cron->status);
        $this->assertStringContainsString('artisan schedule:run', $cron->hint);

        $this->cronRuns();

        $this->assertSame(Status::Ok, $this->check('Cron (scheduler)')->status);
        $this->assertSame(Status::Ok, $this->check('Cron PHP version')->status);

        $this->travel(2)->hours();
        $this->assertSame(Status::Error, $this->check('Cron (scheduler)')->status);
    }

    public function test_daily_jobs_record_their_last_run_but_dry_runs_do_not(): void
    {
        $finished = fn (array $input) => event(new CommandFinished('gaiacp:suspend-overdue', new ArrayInput($input), new NullOutput, 0));

        $finished(['--dry-run' => true]);
        $this->assertNull(app(SchedulerMonitor::class)->lastRun('gaiacp:suspend-overdue'));
        $this->assertSame(Status::Warning, $this->check('gaiacp:suspend-overdue')->status);

        $finished([]);
        $this->assertSame(Status::Ok, $this->check('gaiacp:suspend-overdue')->status);

        app(SchedulerMonitor::class)->commandFinished('gaiacp:sync-domains', 1);
        $this->assertSame(Status::Error, $this->check('gaiacp:sync-domains')->status);

        $this->travel(2)->days();
        $this->assertSame(Status::Warning, $this->check('gaiacp:suspend-overdue')->status);
    }

    public function test_mail_written_to_the_log_is_a_warning(): void
    {
        config(['mail.default' => 'log']);
        $this->assertSame(Status::Warning, $this->check('Mailer')->status);

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mail.acme.gr', 'mail.mailers.smtp.port' => 587]);
        $this->assertSame(Status::Ok, $this->check('Mailer')->status);
        $this->assertSame('SMTP mail.acme.gr:587', $this->check('Mailer')->value);
    }

    public function test_an_svg_logo_without_its_png_copy_for_emails_is_a_warning(): void
    {
        Storage::fake('public');
        $this->assertSame(Status::Info, $this->check('Logo in emails')->status);

        Storage::disk('public')->put('branding/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 50" width="200" height="50"/>');
        config(['gaiacp.company.logo' => 'branding/logo.svg']);
        $this->assertSame(SvgLogo::canConvert() ? Status::Ok : Status::Warning, $this->check('Logo in emails')->status);

        Storage::disk('public')->put('branding/logo.email.png', UploadedFile::fake()->image('logo.png', 640, 160)->getContent());
        $this->assertSame(Status::Ok, $this->check('Logo in emails')->status);
    }

    public function test_debug_mode_in_production_is_a_problem(): void
    {
        config(['app.debug' => true]);
        $this->app['env'] = 'production';

        $this->assertSame(Status::Error, $this->check('Debug mode')->status);
    }

    public function test_backups_without_a_zip_password_and_plugin_uploads_are_pointed_out(): void
    {
        config(['gaiacp.backups.enabled' => true, 'gaiacp.backups.zip_password' => null, 'gaiacp.plugins.uploads' => true]);
        $this->assertSame(Status::Warning, $this->check('Backup encryption')->status);
        $this->assertSame('On', $this->check('Plugin uploads')->value);

        config(['gaiacp.backups.zip_password' => 'secret', 'gaiacp.plugins.uploads' => false]);
        $this->assertSame(Status::Ok, $this->check('Backup encryption')->status);
        $this->assertSame('Off', $this->check('Plugin uploads')->value);
    }

    public function test_unverified_tls_and_recent_failures_are_warnings(): void
    {
        Server::factory()->create(['name' => 'WHM 1', 'verify_tls' => false]);
        $registrar = Registrar::factory()->create(['name' => 'Easy.gr', 'verify_tls' => false]);
        ActivityLog::record('provisioning.failed', 'domain.synced failed: down', properties: ['event' => 'domain.synced', 'registrar_id' => $registrar->id]);

        $this->assertSame(Status::Warning, $this->check('WHM 1 TLS')->status);
        $this->assertSame(Status::Warning, $this->check('Easy.gr TLS')->status);
        $this->assertSame('1 failed call(s) in 7 days', $this->check('Easy.gr')->value);
        $this->assertSame(Status::Warning, $this->check('Failures in the last 7 days')->status);
    }

    public function test_test_connections_calls_each_active_server(): void
    {
        $this->fakeWhm();
        Server::factory()->create(['name' => 'WHM 1']);
        Server::factory()->create(['name' => 'Old WHM', 'active' => false]);
        $this->actingAs(User::factory()->create());

        Livewire::test(SystemHealthPage::class)
            ->callAction('testConnections')
            ->assertSet('connections.0.name', 'WHM 1')
            ->assertSet('connections.0.status', 'ok')
            ->assertCount('connections', 1)
            ->assertSee('11.130.0.4');

        $this->whmFailures['version'] = 'Access denied';

        Livewire::test(SystemHealthPage::class)
            ->callAction('testConnections')
            ->assertSet('connections.0.status', 'error')
            ->assertSee('Access denied');
    }

    public function test_command_fails_when_something_is_a_problem(): void
    {
        $this->artisan('gaiacp:health')->assertFailed(); // cron never seen

        $this->cronRuns();
        config(['app.debug' => false]);
        $this->artisan('gaiacp:health')->assertSuccessful();
    }
}
