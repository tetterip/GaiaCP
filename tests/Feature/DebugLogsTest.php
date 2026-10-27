<?php

namespace Tests\Feature;

use App\Enums\MailTemplate;
use App\Filament\Clusters\Settings\Pages\LoggingSettings;
use App\Filament\Pages\ErrorLog;
use App\Filament\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Filament\Resources\ModuleLogs\Pages\ListModuleLogs;
use App\Mail\ClientMailer;
use App\Mail\TestMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\ModuleLog;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ProvisioningException;
use App\Provisioning\RegistrarFactory;
use App\Support\LogReader;
use App\Support\ModuleLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Plugins\Aade\AadeRegistry;
use Plugins\Cpanel\WhmClient;
use Plugins\EasyGr\EasyRegistrar;
use Plugins\Plesk\PleskClient;
use Tests\FakesEasy;
use Tests\TestCase;

class DebugLogsTest extends TestCase
{
    use FakesEasy, RefreshDatabase;

    private function whm(): WhmClient
    {
        return new WhmClient(Server::factory()->create(['hostname' => 'whm.example.com', 'port' => 2087, 'username' => 'root', 'api_token' => 'TOKEN123']));
    }

    public function test_module_log_is_off_by_default(): void
    {
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1], 'data' => []])]);

        $this->whm()->call('listaccts');

        $this->assertSame(0, ModuleLog::count());
    }

    public function test_whm_request_and_response_are_logged_with_secrets_masked(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1], 'data' => ['user' => 'acme']])]);

        $data = $this->whm()->call('createacct', ['username' => 'acme', 'password' => 'S3cr&t pass']);

        $this->assertSame(['user' => 'acme'], $data, 'the client still reads the reply');
        $log = ModuleLog::sole();
        $this->assertSame('cpanel', $log->module);
        $this->assertSame('createacct', $log->action);
        $this->assertSame(200, $log->status);
        $this->assertStringContainsString('https://whm.example.com:2087/json-api/createacct', $log->url);
        $this->assertStringContainsString('username=acme', $log->request);
        $this->assertStringContainsString('"user":"acme"', $log->response);
        foreach (['TOKEN123', 'S3cr', 'S3cr%26t'] as $secret) {
            $this->assertStringNotContainsString($secret, $log->request.$log->response.$log->url);
        }
        $this->assertStringContainsString('Authorization: '.ModuleLogger::MASK, $log->request);
    }

    public function test_connection_failures_are_logged(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Connection timed out (see https://curl.se/libcurl/c/libcurl-errors.html)'));

        try {
            $this->whm()->call('version');
            $this->fail('expected an exception');
        } catch (ProvisioningException) {
        }

        $log = ModuleLog::sole();
        $this->assertNull($log->status);
        $this->assertSame('cURL error 28: Connection timed out', $log->error);
        $this->assertTrue($log->failed());
    }

    public function test_plesk_passwords_in_the_packet_are_masked(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        Http::fake(['*' => Http::response('<?xml version="1.0"?><packet><customer><add><result><status>ok</status><id>7</id></result></add></customer></packet>')]);
        $server = Server::factory()->create(['type' => 'plesk', 'hostname' => 'plesk.example.com', 'port' => 8443, 'username' => 'admin', 'api_token' => 'ADMINPASS']);

        (new PleskClient($server))->call('customer', 'add', ['gen_info' => ['login' => 'acme', 'passwd' => 'Cust<Pass>', 'pname' => 'Acme']]);
        (new PleskClient($server))->call('webspace', 'set', ['values' => ['hosting' => ['vrt_hst' => ['property' => [['name' => 'ftp_password', 'value' => 'FtpPass9']]]]]]);

        $logs = ModuleLog::orderBy('id')->get();
        $this->assertSame(['customer add', 'webspace set'], $logs->pluck('action')->all());
        $all = $logs->map(fn (ModuleLog $l) => $l->request.$l->response)->implode('');
        $this->assertStringContainsString('<login>acme</login>', $all);
        foreach (['ADMINPASS', 'Cust&lt;Pass&gt;', 'Cust<Pass>', 'FtpPass9'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    public function test_easy_password_and_epp_code_are_masked(): void
    {
        config(['gaiacp.debug.module_log' => true]);
        $this->fakeEasy();
        $this->easyDomains['acme.gr'] = ['expires' => '2027-03-15', 'epp' => 'Epp-Secret-42'];
        $registrar = app(RegistrarFactory::class)->make('easygr', ['username' => 'reseller', 'password' => 'EasyPass1']);

        $this->assertSame('Epp-Secret-42', $registrar->eppCode('acme.gr'));

        $log = ModuleLog::sole();
        $this->assertSame('easy', $log->module);
        $this->assertSame(EasyRegistrar::EPP_CODE.' EPP code', $log->action);
        $this->assertStringContainsString('username=reseller', $log->request);
        $this->assertStringNotContainsString('EasyPass1', $log->request);
        $this->assertStringNotContainsString('Epp-Secret-42', $log->response);
    }

    public function test_aade_password_is_masked(): void
    {
        config(['gaiacp.debug.module_log' => true, 'gaiacp.aade' => ['enabled' => true, 'username' => 'ACMECODE', 'password' => 'p&ss<word>', 'afm_called_by' => null]]);
        Http::fake([AadeRegistry::ENDPOINT => Http::response('<env:Envelope/>', 500)]);

        rescue(fn () => AadeRegistry::lookup('094014298'), report: false);

        $log = ModuleLog::sole();
        $this->assertSame('aade', $log->module);
        $this->assertStringContainsString('ACMECODE', $log->request);
        $this->assertStringNotContainsString('p&amp;ss&lt;word&gt;', $log->request);
        $this->assertStringContainsString('<ns1:Password>'.ModuleLogger::MASK.'</ns1:Password>', $log->request);
    }

    public function test_masking_by_field_name(): void
    {
        $this->assertSame('user=a&password='.ModuleLogger::MASK.'&x=1', ModuleLogger::mask('user=a&password=hunter2&x=1'));
        $this->assertSame('<passwd>'.ModuleLogger::MASK.'</passwd>', ModuleLogger::mask('<passwd>hunter2</passwd>'));
        $this->assertSame('{"pw":"'.ModuleLogger::MASK.'","a":1}', ModuleLogger::mask('{"pw":"hunter2","a":1}'));
    }

    public function test_client_emails_are_logged_with_client_and_template(): void
    {
        $client = Client::factory()->create(['email' => 'client@example.gr']);
        config(['gaiacp.reminders.bcc' => 'copy@example.com']);

        $this->assertTrue(app(ClientMailer::class)->send(MailTemplate::ClientSignup, $client, evenIfOff: true));
        Mail::to('admin@example.com')->send(new TestMail);

        [$clientMail, $adminMail] = EmailLog::orderBy('id')->get()->all();
        $this->assertSame('sent', $clientMail->status);
        $this->assertSame($client->id, $clientMail->client_id);
        $this->assertSame(MailTemplate::ClientSignup, $clientMail->template);
        $this->assertStringContainsString('client@example.gr', $clientMail->to);
        $this->assertStringContainsString('copy@example.com', $clientMail->bcc);
        $this->assertNotEmpty($clientMail->subject);
        $this->assertStringContainsString('<html', $clientMail->body);

        $this->assertNull($adminMail->client_id);
        $this->assertNull($adminMail->template);
        $this->assertStringContainsString('admin@example.com', $adminMail->to);
    }

    public function test_failed_client_emails_are_logged_with_the_reason(): void
    {
        $client = Client::factory()->create(['email' => 'client@example.gr']);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);

        $this->assertFalse(app(ClientMailer::class)->send(MailTemplate::ClientSignup, $client, evenIfOff: true));

        $log = EmailLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertSame($client->id, $log->client_id);
        $this->assertNotEmpty($log->error);
        $this->assertNotEmpty($log->subject);
    }

    public function test_prune_keeps_only_the_configured_days(): void
    {
        config(['gaiacp.debug.module_log_days' => 30, 'gaiacp.debug.email_log_days' => 90]);
        $module = fn (int $daysAgo) => ModuleLog::create(['module' => 'cpanel', 'action' => 'version', 'url' => 'POST x', 'created_at' => now()->subDays($daysAgo)]);
        $email = fn (int $daysAgo) => EmailLog::create(['to' => 'a@example.com', 'status' => 'sent', 'created_at' => now()->subDays($daysAgo)]);
        $module(40);
        $module(5);
        $email(100);
        $email(40);

        $this->artisan('gaiacp:prune-logs')->assertSuccessful();

        $this->assertSame(1, ModuleLog::count());
        $this->assertSame(1, EmailLog::count());
        $this->assertTrue(EmailLog::sole()->created_at->gt(now()->subDays(41)));
    }

    public function test_log_reader_splits_entries_newest_first(): void
    {
        $dir = storage_path('framework/testing/logs-'.uniqid());
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/laravel.log", "[2026-10-09 10:00:00] local.INFO: Started\n[2026-10-09 10:05:00] production.ERROR: Boom {\"x\":1}\n#0 /app/Foo.php(12): bar()\n#1 {main}\n");

        $reader = new LogReader($dir);
        $entries = $reader->entries('laravel.log');

        $this->assertSame(['laravel.log'], array_keys($reader->files()));
        $this->assertCount(2, $entries);
        $this->assertSame('error', $entries[0]['level']);
        $this->assertSame('Boom {"x":1}', $entries[0]['message']);
        $this->assertStringContainsString('#0 /app/Foo.php(12)', $entries[0]['details']);
        $this->assertSame('info', $entries[1]['level']);
        $this->assertNull($reader->path('../.env'));

        $this->assertTrue($reader->clear('laravel.log'));
        $this->assertCount(0, $reader->entries('laravel.log'));
        File::deleteDirectory($dir);
    }

    public function test_log_pages_render(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = ModuleLog::create(['module' => 'plesk', 'action' => 'webspace get', 'url' => 'POST https://plesk.example.com:8443/enterprise/control/agent.php', 'request' => '<packet/>', 'status' => 200, 'response' => '<packet><ok/></packet>', 'duration_ms' => 120]);
        $email = EmailLog::create(['to' => 'client@example.gr', 'subject' => 'Welcome aboard', 'body' => '<p>Hello</p>', 'status' => 'sent']);
        $this->app->instance(LogReader::class, $reader = new LogReader($dir = storage_path('framework/testing/logs-'.uniqid())));
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/laravel.log", "[2026-10-09 10:05:00] production.ERROR: Something broke\n#0 trace line\n");

        $this->get(LoggingSettings::getUrl())->assertOk()->assertSee('Log module calls');
        Livewire::test(ListModuleLogs::class)
            ->assertSee('webspace get')
            ->assertSee('Logging is off')
            ->mountTableAction('view', $entry)
            ->assertMountedActionModalSee('<packet><ok/></packet>');
        Livewire::test(ListEmailLogs::class)
            ->assertSee('Welcome aboard')
            ->mountTableAction('view', $email)
            ->assertMountedActionModalSee('To client@example.gr');
        Livewire::test(ErrorLog::class)
            ->assertSee('Something broke')
            ->mountTableAction('view', '0-0')
            ->assertMountedActionModalSee('#0 trace line');
        Livewire::test(ErrorLog::class)->callAction('clear');
        $this->assertCount(0, $reader->entries('laravel.log'));
        File::deleteDirectory($dir);
    }

    public function test_logging_settings_save(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(LoggingSettings::class)
            ->set('data.debug.module_log', true)
            ->set('data.debug.module_log_days', 7)
            ->set('data.debug.email_log_days', 60)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(config('gaiacp.debug.module_log'));
        $this->assertSame(7, config('gaiacp.debug.module_log_days'));
        $this->assertSame(60, config('gaiacp.debug.email_log_days'));
        $this->assertNull(config('gaiacp.debug.activity_log_days'));

        Livewire::test(LoggingSettings::class)
            ->set('data.debug.activity_log_days', 10)
            ->call('save')
            ->assertHasErrors(['data.debug.activity_log_days' => 'min'])
            ->set('data.debug.activity_log_days', 365)
            ->set('data.debug.notification_days', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(365, config('gaiacp.debug.activity_log_days'));
        $this->assertNull(config('gaiacp.debug.notification_days'));
    }

    public function test_prune_trims_activity_log_notifications_and_expired_cache_when_set(): void
    {
        $user = User::factory()->create();
        $activity = fn (int $daysAgo) => ActivityLog::query()->forceCreate(['event' => 'test', 'description' => 'x', 'created_at' => now()->subDays($daysAgo)]);
        $notification = fn (int $daysAgo) => DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => '{}', 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()]);
        $activity(400);
        $activity(10);
        $notification(100);
        $notification(10);
        DB::table('cache')->insert([['key' => 'old', 'value' => 'x', 'expiration' => now()->subMinute()->getTimestamp()], ['key' => 'new', 'value' => 'x', 'expiration' => now()->addHour()->getTimestamp()]]);

        config(['gaiacp.debug.activity_log_days' => null, 'gaiacp.debug.notification_days' => null]);
        $this->artisan('gaiacp:prune-logs')->assertSuccessful();
        $this->assertSame(2, ActivityLog::query()->where('event', 'test')->count(), 'empty keeps everything');
        $this->assertSame(2, DB::table('notifications')->count());
        $this->assertSame(['new'], DB::table('cache')->pluck('key')->all());

        config(['gaiacp.debug.activity_log_days' => 365, 'gaiacp.debug.notification_days' => 90]);
        $this->artisan('gaiacp:prune-logs')->assertSuccessful();
        $this->assertSame(1, ActivityLog::query()->where('event', 'test')->count());
        $this->assertSame(1, DB::table('notifications')->count());
    }
}
