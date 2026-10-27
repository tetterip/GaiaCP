<?php

namespace Tests\Feature;

use App\Mail\NewClientMail;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The admins' system emails carry the GaiaCP logo, as /admin does; client emails keep the company's (MailLayout). */
class AdminMailLogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_email_shows_the_gaiacp_logo(): void
    {
        config(['gaiacp.company.name' => 'Acme Hosting']);

        $html = (new NewClientMail(Client::factory()->create(['first_name' => 'Maria'])))->render();

        $this->assertStringContainsString('alt="GaiaCP"', $html);
        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringContainsString('Maria', $html);
    }

    public function test_a_sent_admin_email_embeds_the_logo_and_its_text_part_names_it(): void
    {
        config(['mail.default' => 'array']);

        Mail::to('admin@example.com')->send(new NewClientMail(Client::factory()->create()));

        $email = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertStringContainsString('src="cid:', $email->getHtmlBody());
        $this->assertCount(1, $email->getAttachments());
        $this->assertStringNotContainsString('<img', $email->getTextBody());
        $this->assertStringContainsString('GaiaCP', $email->getTextBody());
    }

    public function test_every_admin_email_view_uses_the_admin_layout(): void
    {
        foreach (glob(resource_path('views/mail/*.blade.php')) as $view) {
            if (basename($view) === 'template.blade.php') {
                continue; // client emails: MailLayout, with the company's logo
            }

            $this->assertStringContainsString('<x-admin-mail', (string) file_get_contents($view), basename($view));
            $this->assertStringNotContainsString('<x-mail::message>', (string) file_get_contents($view), basename($view));
        }
    }
}
