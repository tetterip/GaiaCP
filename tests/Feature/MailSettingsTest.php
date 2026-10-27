<?php

namespace Tests\Feature;

use App\Enums\MailTemplate;
use App\Filament\Clusters\Settings\Pages\MailSettings as SettingsPage;
use App\Mail\ClientMail;
use App\Mail\TestMail;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function form(array $mail = []): array
    {
        return [
            'mail' => $mail + ['smtp' => true, 'host' => 'mail.acme.gr', 'port' => '465', 'encryption' => 'ssl', 'username' => 'billing@acme.gr', 'password' => 's3cret'],
        ];
    }

    public function test_smtp_settings_are_saved_with_the_password_encrypted_and_drive_the_mailer(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SettingsPage::class)
            ->fillForm($this->form())
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = Setting::query()->find('mail.password')->value;
        $this->assertNotSame('s3cret', $stored);
        $this->assertSame('s3cret', Crypt::decryptString($stored));

        // A fresh request loads them over the .env mailer.
        config(['mail.default' => 'array', 'gaiacp.mail.password' => null]);
        Settings::apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('mail.acme.gr', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('billing@acme.gr', config('mail.mailers.smtp.username'));
        $this->assertSame('s3cret', config('mail.mailers.smtp.password'));
    }

    public function test_a_blank_password_keeps_the_stored_one_and_is_never_sent_to_the_form(): void
    {
        $this->actingAs(User::factory()->create());
        Settings::save(['mail.password' => 'old-secret']);

        Livewire::test(SettingsPage::class)
            ->assertSet('data.mail.password', null)
            ->fillForm($this->form(['password' => '', 'encryption' => 'tls', 'port' => '587']))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('old-secret', Crypt::decryptString(Setting::query()->find('mail.password')->value));
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $this->assertTrue(config('mail.mailers.smtp.auto_tls'));
    }

    public function test_smtp_off_leaves_the_env_mailer(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SettingsPage::class)
            ->fillForm($this->form(['smtp' => false, 'host' => '']))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('array', config('mail.default'));
    }

    public function test_smtp_on_needs_a_host(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SettingsPage::class)
            ->fillForm($this->form(['host' => '']))
            ->call('save')
            ->assertHasFormErrors(['mail.host']);
    }

    public function test_client_emails_get_the_header_footer_and_logo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo.png', UploadedFile::fake()->image('logo.png', 200, 60)->getContent());
        Settings::save([
            'company.name' => 'Acme Hosting',
            'company.logo' => 'branding/logo.png',
            'mail.header' => 'Γεια σας {$client_first_name},',
            'mail.footer' => '**ACME** support: {$company_name}',
        ]);

        $html = ClientMail::sample(MailTemplate::HostingWelcome)->render();

        $this->assertStringContainsString('Γεια σας Γιώργος,', $html);
        $this->assertStringContainsString('>ACME</strong> support: Acme Hosting', $html);
        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertLessThan(strpos($html, 'support: Acme Hosting'), strpos($html, 'Γεια σας Γιώργος'));

        // Sent for real, the logo is embedded once (cid:), shared by the HTML and text parts.
        Mail::mailer('array')->to('client@example.gr')->send(ClientMail::sample(MailTemplate::HostingWelcome));
        /** @var Email $sent */
        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertCount(1, $sent->getAttachments());
        $this->assertStringContainsString('src="cid:', $sent->getHtmlBody());
    }

    public function test_without_a_logo_the_company_name_heads_the_email(): void
    {
        Settings::save(['company.name' => 'Acme Hosting']);

        $html = ClientMail::sample(MailTemplate::HostingWelcome)->render();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Acme Hosting', $html);
        $this->assertStringNotContainsString('laravel.com', $html);
    }

    public function test_send_test_email_uses_the_unsaved_form_values(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create(['email' => 'me@acme.gr']));

        Livewire::test(SettingsPage::class)
            ->fillForm($this->form(['host' => 'smtp.example.net', 'footer' => 'Unsaved footer']))
            ->callAction(TestAction::make('sendTestEmail')->schemaComponent('mailActions', 'form'), data: ['to' => 'me@acme.gr'])
            ->assertNotified('Test email sent to me@acme.gr');

        Mail::assertSent(TestMail::class, fn (TestMail $mail) => $mail->hasTo('me@acme.gr'));
        $this->assertSame('smtp.example.net', config('mail.mailers.smtp.host'));
        $this->assertSame(0, Setting::count(), 'testing saves nothing');
        $this->assertTrue(ActivityLog::query()->where('event', 'email.test')->exists());
        $this->assertStringContainsString('Unsaved footer', (new TestMail)->render());
    }

    public function test_a_failing_test_email_shows_the_error(): void
    {
        $this->actingAs(User::factory()->create());

        // Nothing listens on port 1 locally: the connection is refused straight away.
        Livewire::test(SettingsPage::class)
            ->fillForm($this->form(['host' => '127.0.0.1', 'port' => '1', 'encryption' => 'none']))
            ->callAction(TestAction::make('sendTestEmail')->schemaComponent('mailActions', 'form'), data: ['to' => 'me@acme.gr'])
            ->assertNotified('The test email was not sent');

        $this->assertTrue(ActivityLog::query()->where('event', 'email.failed')->exists());
    }
}
