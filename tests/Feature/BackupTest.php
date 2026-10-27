<?php

namespace Tests\Feature;

use App\Backups\DatabaseDumper;
use App\Backups\FtpUploader;
use App\Backups\RunBackup;
use App\Filament\Clusters\Settings\Pages\BackupSettings as SettingsPage;
use App\Mail\BackupMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\ModuleLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PDO;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    /** @var list<array{file: string, keep: int, config: array}> */
    private array $uploads = [];

    private ?string $ftpError = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/gaiacp-backups-'.uniqid();
        config(['gaiacp.backups.directory' => $this->dir, 'gaiacp.backups.keep' => 3]);

        $test = $this;
        $this->app->bind(FtpUploader::class, fn ($app, array $params) => new class($test, $params['config'] ?? []) extends FtpUploader
        {
            public function __construct(private BackupTest $test, private array $settings)
            {
                parent::__construct($settings);
            }

            public function test(): void
            {
                $this->test->ftpCalled($this->settings, null, 0);
            }

            public function upload(string $localPath, int $keep): array
            {
                $this->test->ftpCalled($this->settings, $localPath, $keep);

                return [];
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** @internal called by the fake FTP uploader */
    public function ftpCalled(array $config, ?string $file, int $keep): void
    {
        if ($this->ftpError) {
            throw new RuntimeException($this->ftpError);
        }

        $this->uploads[] = ['file' => $file, 'keep' => $keep, 'config' => $config];
    }

    private function sqlIn(string $zipPath, ?string $password = null): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));

        if ($password) {
            $zip->setPassword($password);
        }

        $sql = $zip->getFromIndex(0);
        $zip->close();

        $this->assertIsString($sql);

        return $sql;
    }

    public function test_the_dump_restores_into_an_empty_database(): void
    {
        Client::factory()->create(['first_name' => 'Νίκος', 'last_name' => "O'Brien"]);
        Client::factory()->count(150)->create();

        $path = $this->dir.'/dump.sql';
        File::ensureDirectoryExists($this->dir);
        app(DatabaseDumper::class)->dump($path);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(file_get_contents($path));

        $this->assertSame(151, (int) $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        $this->assertSame('Νίκος', $pdo->query("SELECT first_name FROM clients WHERE last_name = 'O''Brien'")->fetchColumn());
        $this->assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
    }

    public function test_backup_is_zipped_uploaded_and_emailed(): void
    {
        Mail::fake();
        Client::factory()->create(['first_name' => 'Μαρία']);
        config(['gaiacp.backups.email' => 'admin@acme.gr', 'gaiacp.backups.ftp' => ['enabled' => true, 'host' => 'ftp.example.com', 'username' => 'u', 'password' => 'p', 'path' => '/b']]);

        $this->artisan('gaiacp:backup')->assertSuccessful();

        $files = RunBackup::localBackups();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/gaiacp-backup-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/', $files[0]);
        $this->assertStringContainsString('Μαρία', $this->sqlIn($files[0]));
        $this->assertSame([], glob($this->dir.'/*.sql'), 'the plain SQL file is deleted');

        $this->assertCount(1, $this->uploads);
        $this->assertSame($files[0], $this->uploads[0]['file']);
        $this->assertSame(3, $this->uploads[0]['keep']);
        $this->assertSame('ftp.example.com', $this->uploads[0]['config']['host']);

        Mail::assertSent(BackupMail::class, fn (BackupMail $mail) => $mail->hasTo('admin@acme.gr') && count($mail->attachments()) === 1);
        $this->assertSame('backup.created', ActivityLog::query()->latest('id')->value('event'));
        $this->assertStringContainsString('sent to FTP and email', ActivityLog::query()->latest('id')->value('description'));
    }

    public function test_logs_go_into_the_backup_empty_unless_included(): void
    {
        Client::factory()->create(['first_name' => 'Μαρία']);
        ModuleLog::create(['module' => 'plesk', 'action' => 'get', 'url' => 'POST x', 'response' => 'HUGE-REPLY', 'created_at' => now()]);
        EmailLog::create(['to' => 'a@example.com', 'subject' => 'Old email', 'status' => 'sent', 'created_at' => now()]);

        app(RunBackup::class)();
        $sql = $this->sqlIn(RunBackup::localBackups()[0]);

        $this->assertStringContainsString('Μαρία', $sql);
        $this->assertStringContainsString('CREATE TABLE "module_logs"', $sql, 'the table is still there to restore into');
        $this->assertStringNotContainsString('HUGE-REPLY', $sql);
        $this->assertStringNotContainsString('Old email', $sql);

        $this->travel(1)->seconds();
        config(['gaiacp.backups.include_logs' => true]);
        app(RunBackup::class)();
        $sql = $this->sqlIn(RunBackup::localBackups()[0]);

        $this->assertStringContainsString('HUGE-REPLY', $sql);
        $this->assertStringContainsString('Old email', $sql);
    }

    public function test_zip_password_encrypts_the_dump(): void
    {
        config(['gaiacp.backups.zip_password' => 's3cret']);

        app(RunBackup::class)();

        $zip = new ZipArchive;
        $zip->open(RunBackup::localBackups()[0]);
        $this->assertSame(ZipArchive::EM_AES_256, $zip->statIndex(0)['encryption_method']);
        $this->assertFalse($zip->getFromIndex(0), 'unreadable without the password');
        $zip->close();

        $this->assertStringContainsString('CREATE TABLE', $this->sqlIn(RunBackup::localBackups()[0], 's3cret'));
    }

    public function test_only_the_newest_backups_are_kept(): void
    {
        File::ensureDirectoryExists($this->dir);
        foreach (['2026-01-01-030000', '2026-01-02-030000', '2026-01-03-030000', '2026-01-04-030000'] as $stamp) {
            File::put("{$this->dir}/gaiacp-backup-{$stamp}.zip", 'old');
        }
        File::put("{$this->dir}/notes.zip", 'not a backup');

        $result = app(RunBackup::class)();

        $this->assertSame(['gaiacp-backup-2026-01-02-030000.zip', 'gaiacp-backup-2026-01-01-030000.zip'], $result->pruned);
        $this->assertCount(3, RunBackup::localBackups());
        $this->assertSame(basename($result->path), basename(RunBackup::localBackups()[0]));
        $this->assertFileExists("{$this->dir}/notes.zip");
    }

    public function test_an_ftp_failure_still_emails_and_is_logged(): void
    {
        Mail::fake();
        $this->ftpError = 'Login to ftp.example.com failed';
        config(['gaiacp.backups.email' => 'admin@acme.gr', 'gaiacp.backups.ftp.enabled' => true]);

        $this->artisan('gaiacp:backup')->assertFailed();

        Mail::assertSent(BackupMail::class, fn (BackupMail $mail) => count($mail->attachments()) === 1
            && str_contains($mail->render(), 'Login to ftp.example.com failed')
            && str_contains($mail->envelope()->subject, 'with errors'));
        $log = ActivityLog::query()->latest('id')->first();
        $this->assertSame('backup.failed', $log->event);
        $this->assertSame(['email'], $log->properties['copies']);
        $this->assertCount(1, RunBackup::localBackups(), 'the backup is kept on the server');
    }

    public function test_a_big_backup_is_not_attached(): void
    {
        $result = app(RunBackup::class)();
        $result->size = RunBackup::MAX_ATTACHMENT_BYTES + 1;

        $mail = new BackupMail($result);

        $this->assertSame([], $mail->attachments());
        $this->assertStringContainsString('too big to attach', $mail->render());
    }

    public function test_backups_are_scheduled_only_when_enabled(): void
    {
        $events = fn () => collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'gaiacp:backup'));

        $this->assertCount(0, $events());

        config(['gaiacp.backups.enabled' => true, 'gaiacp.backups.frequency' => 'weekly', 'gaiacp.backups.weekday' => 0, 'gaiacp.backups.time' => '02:30']);
        require base_path('routes/console.php');

        $this->assertSame('30 2 * * 0', $events()->first()->expression);
    }

    public function test_settings_store_secrets_encrypted_and_blank_keeps_them(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SettingsPage::class)
            ->fillForm(['backups' => [
                'enabled' => true, 'frequency' => 'daily', 'time' => '03:15', 'keep' => '10',
                'zip_password' => 'zip-pass', 'email' => 'admin@acme.gr',
                'ftp' => ['enabled' => true, 'protocol' => 'ftps', 'host' => 'ftp.example.com', 'port' => '', 'username' => 'backup', 'password' => 'ftp-pass', 'path' => '/gaiacp', 'passive' => true],
            ]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('data.backups.ftp.password', null);

        $stored = Setting::query()->find('backups.ftp.password')->value;
        $this->assertStringNotContainsString('ftp-pass', $stored);
        $this->assertSame(10, config('gaiacp.backups.keep'));
        $this->assertSame(21, config('gaiacp.backups.ftp.port'));

        // Next boot decrypts; the form never receives the secrets and a blank field keeps them.
        config(['gaiacp.backups.ftp.password' => null, 'gaiacp.backups.zip_password' => null]);
        Settings::apply();
        $this->assertSame('ftp-pass', config('gaiacp.backups.ftp.password'));
        $this->assertSame('zip-pass', config('gaiacp.backups.zip_password'));

        Livewire::test(SettingsPage::class)
            ->assertSet('data.backups.ftp.password', null)
            ->assertSet('data.backups.ftp.host', 'ftp.example.com')
            ->fillForm(['backups' => ['ftp' => ['host' => 'ftp2.example.com']]])
            ->call('save')
            ->assertHasNoFormErrors();

        Settings::apply();
        $this->assertSame('ftp-pass', config('gaiacp.backups.ftp.password'));
        $this->assertSame('ftp2.example.com', config('gaiacp.backups.ftp.host'));
    }

    public function test_ftp_settings_need_a_host_when_enabled(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SettingsPage::class)
            ->fillForm(['backups' => ['ftp' => ['enabled' => true, 'host' => '', 'username' => '']]])
            ->call('save')
            ->assertHasFormErrors(['backups.ftp.host', 'backups.ftp.username']);
    }

    public function test_back_up_now_and_test_ftp_buttons(): void
    {
        $this->actingAs(User::factory()->create());
        Settings::save(['backups.ftp.password' => 'stored-pass']);

        $this->get(SettingsPage::getUrl())->assertOk()->assertSee('Back up now')->assertSee('No backup yet.');

        Livewire::test(SettingsPage::class)
            ->fillForm(['backups' => ['ftp' => ['host' => 'unsaved.example.com', 'username' => 'u', 'password' => '']]])
            ->callAction(TestAction::make('testFtp')->schemaComponent('ftpActions', 'form'))
            ->assertNotified('FTP connection works');

        $this->assertSame('unsaved.example.com', $this->uploads[0]['config']['host']);
        $this->assertSame('stored-pass', $this->uploads[0]['config']['password']);
    }
}
