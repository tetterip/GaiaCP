<?php

namespace Tests\Feature;

use App\Enums\MailTemplate;
use App\Mail\ClientMail;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\FakesWhm;
use Tests\TestCase;

/** Settings → Automation → Usage warning: gaiacp:check-usage emails the client and rings the bell once a month. */
class UsageWarningTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fakeWhm();
        $this->travelTo('2026-10-23');

        $this->service = Service::factory()->create(['username' => 'maria', 'domain' => 'maria.gr', 'package' => 'basic']);
        $this->whmAccounts['maria'] = false;
        // Disk 1.8 of 2 GB (90%); bandwidth 3 of 10 GB.
        $this->whmUsage['maria'] = ['diskused' => '1843M', 'disklimit' => '2G', 'totalbytes' => 3_221_225_472, 'limit' => 10_737_418_240];
    }

    public function test_an_account_near_its_limit_is_warned_once_a_month(): void
    {
        $admin = User::factory()->create(['panel_notifications' => true]);

        $this->artisan('gaiacp:check-usage')->assertSuccessful();

        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::UsageWarning
            && str_contains((string) $mail->values['usage'], '90%')
            && ! str_contains((string) $mail->values['usage'], '30%'));
        $this->assertSame(1, $admin->notifications()->count(), 'the bell rings');

        // Again the same month: nothing new.
        $this->artisan('gaiacp:check-usage')->assertSuccessful();
        Mail::assertSentCount(1);

        // Bandwidth reaches it too: warned for that.
        $this->whmUsage['maria']['totalbytes'] = 9_663_676_416;
        $this->artisan('gaiacp:check-usage')->assertSuccessful();
        Mail::assertSentCount(2);

        // A new month warns again.
        $this->travelTo('2026-11-02');
        $this->artisan('gaiacp:check-usage')->assertSuccessful();
        Mail::assertSentCount(3);
    }

    public function test_below_the_share_or_switched_off_nothing_is_sent(): void
    {
        config(['gaiacp.automation.usage_warning_percent' => 95]);
        $this->artisan('gaiacp:check-usage')->assertSuccessful();

        config(['gaiacp.automation.usage_warning_percent' => null]);
        $this->artisan('gaiacp:check-usage')->expectsOutputToContain('off')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_the_dry_run_lists_without_sending(): void
    {
        $this->artisan('gaiacp:check-usage --dry-run')->expectsOutputToContain('maria.gr: disk 90%')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertDatabaseCount('usage_warnings', 0);
    }
}
