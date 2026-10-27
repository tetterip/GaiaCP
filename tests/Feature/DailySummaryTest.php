<?php

namespace Tests\Feature;

use App\Actions\DomainLifecycle;
use App\Enums\InvoiceStatus;
use App\Filament\Clusters\Settings\Pages\AutomationSettings;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Mail\DailySummaryMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\FakesEasy;
use Tests\TestCase;

class DailySummaryTest extends TestCase
{
    use FakesEasy, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-10 09:00');
        $this->fakeEasy();
        Mail::fake();
    }

    private function notice(string $number, string $due, array $attributes = []): Invoice
    {
        return Invoice::create([
            'number' => $number,
            'client_id' => Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papadopoulou'])->id,
            'date' => '2026-10-01',
            'due_date' => $due,
            'currency' => 'EUR',
            'subtotal_cents' => 12000,
            'tax_cents' => 0,
            'total_cents' => 12000,
            ...$attributes,
        ]);
    }

    private function domain(string $name, array $details = []): Service
    {
        $service = Service::factory()->domain()->create(['domain' => $name]);
        $service->registration()->update($details);

        return $service->refresh();
    }

    public function test_it_reports_new_and_overdue_notices_and_domain_changes(): void
    {
        $admin = User::factory()->create(['email' => 'me@example.com']);
        User::factory()->create(['email' => 'quiet@example.com', 'system_emails' => false]);
        User::factory()->create(['email' => 'gone@example.com', 'active' => false]);

        $this->travelTo('2026-10-09 08:00');
        $this->notice('PF-2026-0001', '2026-10-24'); // created before the period

        $this->travelTo('2026-10-10 08:00');
        $this->notice('PF-2026-0002', '2026-10-24');
        $this->notice('PF-2026-0003', '2026-10-24', ['status' => InvoiceStatus::Cancelled]);
        $this->notice('PF-2026-0004', '2026-10-01');                                       // overdue
        $this->notice('PF-2026-0005', '2026-10-01', ['status' => InvoiceStatus::Paid]);

        $renewed = $this->domain('renewed.gr', ['expires_at' => '2026-11-05', 'nameservers' => ['ns1.x.gr', 'ns2.x.gr']]);
        $same = $this->domain('same.gr', ['expires_at' => '2027-01-01', 'nameservers' => ['NS1.X.GR', 'ns2.x.gr']]);
        $broken = $this->domain('broken.gr');
        $this->easyDomains = [
            'renewed.gr' => ['expires' => '2027-11-05', 'ns' => ['ns1.x.gr', 'ns2.x.gr']],
            'same.gr' => ['expires' => '2027-01-01', 'ns' => ['ns1.x.gr', 'ns2.x.gr']],
        ];
        foreach ([$renewed, $same, $broken] as $service) {
            rescue(fn () => app(DomainLifecycle::class)->sync($service), report: false);
        }

        $this->travelTo('2026-10-10 09:00');
        $this->artisan('gaiacp:daily-summary')->assertSuccessful();

        Mail::assertSent(DailySummaryMail::class, 1);
        Mail::assertSent(DailySummaryMail::class, fn (DailySummaryMail $mail) => $mail->hasTo('me@example.com'));

        $summary = Mail::sent(DailySummaryMail::class)->first()->summary;
        $this->assertSame(['PF-2026-0002', 'PF-2026-0004', 'PF-2026-0005'], $summary->newNotices->pluck('number')->all());
        $this->assertSame(['PF-2026-0004'], $summary->overdueNotices->pluck('number')->all());
        $this->assertSame(['renewed.gr'], $summary->domainChanges->map(fn ($log) => $log->service->domain)->all());
        $this->assertSame(['broken.gr'], $summary->domainFailures->map(fn ($log) => $log->service->domain)->all());
        $this->assertSame(1, $summary->domainsInSync);

        $html = (new DailySummaryMail($summary))->locale('en')->render();
        $this->assertStringContainsString('PF-2026-0004', $html);
        $this->assertStringContainsString('renewed.gr', $html);
        $this->assertStringContainsString('expiry 05/11/2026 → 05/11/2027', $html);
        $this->assertStringContainsString('broken.gr', $html);
        $this->assertStringContainsString('Domains in sync, nothing changed: 1', $html);
        $this->assertStringNotContainsString('same.gr', $html);

        $this->assertTrue(ActivityLog::where('event', 'admin_summary.sent')->exists());
    }

    public function test_it_is_sent_once_a_day_and_the_next_one_starts_where_it_ended(): void
    {
        User::factory()->create();
        $this->notice('PF-2026-0001', '2026-10-24');

        $this->artisan('gaiacp:daily-summary')->assertSuccessful();
        $this->artisan('gaiacp:daily-summary')->assertSuccessful();
        Mail::assertSent(DailySummaryMail::class, 1);

        $this->travelTo('2026-10-11 09:00');
        $this->notice('PF-2026-0002', '2026-10-25');
        $this->artisan('gaiacp:daily-summary')->assertSuccessful();

        Mail::assertSent(DailySummaryMail::class, 2);
        $this->assertSame(['PF-2026-0002'], Mail::sent(DailySummaryMail::class)->last()->summary->newNotices->pluck('number')->all());
    }

    public function test_nothing_to_report_sends_nothing(): void
    {
        User::factory()->create();
        $this->domain('same.gr', ['expires_at' => '2027-01-01']);
        $this->easyDomains = ['same.gr' => ['expires' => '2027-01-01']];
        $this->artisan('gaiacp:sync-domains')->assertSuccessful();

        $this->artisan('gaiacp:daily-summary')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertFalse(ActivityLog::where('event', 'admin_summary.sent')->exists());
    }

    public function test_the_switch_under_automation_turns_it_off_for_everyone(): void
    {
        $admin = User::factory()->create();
        $this->notice('PF-2026-0001', '2026-10-01');
        $this->actingAs($admin);

        Livewire::test(AutomationSettings::class)
            ->fillForm(['automation.daily_summary' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse(config('gaiacp.automation.daily_summary'));

        $this->artisan('gaiacp:daily-summary')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_a_dry_run_sends_nothing(): void
    {
        User::factory()->create();
        $this->notice('PF-2026-0001', '2026-10-01');

        $this->artisan('gaiacp:daily-summary', ['--dry-run' => true])->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_each_admin_turns_it_off_under_admin_users(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->fillForm(['system_emails' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($admin->refresh()->system_emails);
    }

    public function test_the_mail_renders_in_greek(): void
    {
        User::factory()->create(['locale' => 'el']);
        $this->notice('PF-2026-0001', '2026-10-01');

        $this->artisan('gaiacp:daily-summary')->assertSuccessful();

        $mail = Mail::sent(DailySummaryMail::class)->first();
        $this->assertSame('el', $mail->locale);
    }
}
