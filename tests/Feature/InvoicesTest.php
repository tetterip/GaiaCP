<?php

namespace Tests\Feature;

use App\Actions\CompleteOrder;
use App\Actions\PlaceOrder;
use App\Actions\RecordInvoicePayment;
use App\Actions\RecordOrderPayment;
use App\Actions\RecordPayment;
use App\Actions\ReversePayment;
use App\Actions\ServiceLifecycle;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Clusters\Settings\Pages\TaxSettings as SettingsPage;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Invoices\GenerateInvoices;
use App\Invoices\SendInvoiceEmails;
use App\Mail\ClientMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Support\MailTemplates;
use App\Support\Tax;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\FakesWhm;
use Tests\TestCase;

class InvoicesTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWhm();
        config([
            'gaiacp.invoices.days_before' => 14,
            'gaiacp.reminders.before_days' => [14, 7, 1],
            'gaiacp.reminders.overdue_days' => [1, 7, 14],
            'gaiacp.reminders.bcc' => null,
            'gaiacp.tax' => ['enabled' => false, 'inclusive' => false, 'rules' => [['name' => 'ΦΠΑ', 'rate' => 17, 'country' => null]]],
        ]);
        Mail::fake();
    }

    private function service(string $due, array $attributes = [], ?Client $client = null): Service
    {
        $factory = Service::factory()->state(['start_date' => CarbonImmutable::parse($due)->subYear(), 'next_due_date' => $due] + $attributes);

        return ($client ? $factory->for($client) : $factory)->create();
    }

    private function generate(string $date): array
    {
        return app(GenerateInvoices::class)->handle(CarbonImmutable::parse($date));
    }

    private function run_(string $date): void
    {
        $this->artisan('gaiacp:send-reminders', ['--date' => $date])->assertSuccessful();
    }

    /** @return list<MailTemplate> templates sent, in order */
    private function sent(): array
    {
        return Mail::sent(ClientMail::class)->map(fn (ClientMail $m) => $m->template())->values()->all();
    }

    public function test_one_notice_per_client_and_due_date(): void
    {
        $client = Client::factory()->create();
        $hosting = $this->service('2026-10-20', ['label' => 'Hosting', 'domain' => 'acme.gr'], $client);
        $domain = $this->service('2026-10-20', ['label' => 'Domain', 'domain' => 'acme.gr', 'price_cents' => 1500], $client);
        $this->service('2026-10-21', [], $client); // another due date
        $this->service('2026-11-20', [], $client); // too far ahead
        $this->service('2026-10-20'); // another client

        $this->generate('2026-10-08');

        $this->assertSame(3, Invoice::count());
        $invoice = Invoice::query()->where('client_id', $client->id)->whereDate('due_date', '2026-10-20')->sole();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame('2026-10-08', $invoice->date->toDateString());
        $this->assertSame(7500, $invoice->total_cents);
        $this->assertSame([$hosting->id, $domain->id], $invoice->items->pluck('service_id')->all());
        $this->assertSame('2027-10-20', $invoice->items->first()->period_end->toDateString());
        $this->assertSame('Hosting (acme.gr) · Annually', $invoice->items->first()->description);
    }

    public function test_numbers_count_up_per_year(): void
    {
        $this->service('2026-10-10');
        $this->service('2026-10-11');

        $this->generate('2026-10-08');

        $this->assertSame(['PF-2026-0001', 'PF-2026-0002'], Invoice::query()->orderBy('id')->pluck('number')->all());
        $this->assertSame('PF-2027-0001', Invoice::nextNumber(CarbonImmutable::parse('2027-01-02')));
    }

    public function test_rerun_creates_nothing_twice_and_a_paid_cycle_gets_the_next_notice(): void
    {
        $service = $this->service('2026-10-10');

        $this->generate('2026-10-08');
        $this->generate('2026-10-09');
        $this->assertSame(1, Invoice::count());

        app(RecordPayment::class)->handle($service, 6000, CarbonImmutable::parse('2026-10-09'), 'bank_transfer');
        $this->generate('2027-09-30');

        $this->assertSame(2, Invoice::count());
        $this->assertSame('2027-10-10', Invoice::query()->latest('id')->first()->due_date->toDateString());
    }

    public function test_overdue_services_without_a_notice_get_one_and_inactive_ones_none(): void
    {
        $this->service('2026-09-01');
        $this->service('2026-10-10', ['status' => ServiceStatus::Terminated]);
        $this->service('2026-10-10', ['status' => ServiceStatus::Pending]);
        $this->service('2026-10-10', ['price_cents' => 0]);
        $this->service('2026-10-10', ['auto_terminate' => true]); // ends at its due date

        $this->generate('2026-10-08');

        $this->assertSame(['2026-09-01'], Invoice::query()->pluck('due_date')->map->toDateString()->all());
    }

    public function test_a_never_paid_service_owes_its_first_payment(): void
    {
        $this->service('2026-10-10', ['price_cents' => 1500, 'first_payment_cents' => 2000]);

        $this->generate('2026-10-08');

        $this->assertSame(2000, Invoice::sole()->total_cents);
    }

    public function test_a_free_service_with_a_setup_fee_owes_only_the_fee(): void
    {
        $service = $this->service('2026-10-10', ['price_cents' => 0, 'first_payment_cents' => 2000]);

        $this->generate('2026-10-08');
        $this->assertSame(2000, Invoice::sole()->total_cents);

        app(RecordInvoicePayment::class)->handle(Invoice::sole(), CarbonImmutable::parse('2026-10-09'), 'bank_transfer');
        $this->generate('2027-09-30');

        $this->assertSame(1, Invoice::count());
        $this->assertSame('2027-10-10', $service->fresh()->next_due_date->toDateString());
    }

    public function test_a_service_set_to_end_after_its_notice_gets_no_more_emails(): void
    {
        $service = $this->service('2026-10-10');
        $this->run_('2026-09-26');
        $this->assertSame([MailTemplate::InvoiceCreated], $this->sent());

        $this->travelTo('2026-09-30');
        $service->update(['auto_terminate' => true]);
        $this->run_('2026-10-03');
        $this->run_('2026-10-11');

        $this->assertSame(InvoiceStatus::Cancelled, Invoice::sole()->status);
        $this->assertSame([MailTemplate::InvoiceCreated], $this->sent());
    }

    public function test_a_service_set_to_end_leaves_a_shared_notice_but_an_overdue_cycle_stays_owed(): void
    {
        $client = Client::factory()->create();
        $kept = $this->service('2026-10-10', ['price_cents' => 6000], $client);
        $ending = $this->service('2026-10-10', ['price_cents' => 1500], $client);
        $overdue = $this->service('2026-09-01');
        $this->generate('2026-10-08');

        $this->travelTo('2026-10-08');
        $ending->update(['auto_terminate' => true]);
        $overdue->update(['auto_terminate' => true]);

        $invoice = $kept->invoiceItems()->sole()->invoice;
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(6000, $invoice->total_cents);
        $this->assertSame([$kept->id], $invoice->items()->pluck('service_id')->all());
        $this->assertSame(InvoiceStatus::Unpaid, $overdue->invoiceItems()->sole()->invoice->status);
    }

    public function test_terminating_a_service_takes_all_its_unpaid_lines_off_its_notices(): void
    {
        $client = Client::factory()->create();
        $kept = $this->service('2026-10-10', ['price_cents' => 6000], $client);
        $ending = $this->service('2026-10-10', ['price_cents' => 1500], $client);
        $overdue = $this->service('2026-09-01');
        $this->generate('2026-10-08');

        $this->travelTo('2026-10-08');
        app(ServiceLifecycle::class)->terminate($ending);
        app(ServiceLifecycle::class)->terminate($overdue);

        $shared = $kept->invoiceItems()->sole()->invoice;
        $this->assertSame(6000, $shared->total_cents);
        $this->assertSame([$kept->id], $shared->items()->pluck('service_id')->all());
        // A terminated service owes no overdue cycle either: its notice is cancelled and gets no more reminders
        $this->assertSame(0, $overdue->invoiceItems()->count());
        $this->assertSame(1, Invoice::query()->where('status', InvoiceStatus::Cancelled)->count());
        $this->run_('2026-10-12');
        $this->assertNotContains(MailTemplate::InvoiceFirstOverdue, $this->sent());
    }

    public function test_cancelling_a_service_by_its_status_cancels_its_notice(): void
    {
        $service = $this->service('2026-10-10');
        $this->generate('2026-10-08');

        $service->update(['status' => ServiceStatus::Cancelled]);

        $this->assertSame(InvoiceStatus::Cancelled, Invoice::sole()->status);
    }

    public function test_a_service_that_ends_through_auto_terminate_still_owes_its_overdue_cycle(): void
    {
        $service = $this->service('2026-09-01');
        $this->generate('2026-10-08');

        $this->travelTo('2026-10-08');
        $service->update(['auto_terminate' => true]);
        $service->update(['status' => ServiceStatus::Terminated]); // gaiacp:terminate-scheduled

        $this->assertSame(InvoiceStatus::Unpaid, Invoice::sole()->status);
        $this->assertSame(1, $service->invoiceItems()->count());
    }

    public function test_the_notice_lines_show_each_services_type(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();
        $hosting = $this->service('2026-10-10', [], $client);
        $domain = $this->service('2026-10-10', ['type' => ServiceType::Domain, 'domain' => 'maria.gr'], $client);
        $this->generate('2026-10-08');
        $invoice = Invoice::sole();

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class])
            ->assertTableColumnStateSet('type', ServiceType::Hosting, $hosting->invoiceItems()->sole())
            ->assertTableColumnStateSet('type', ServiceType::Domain, $domain->invoiceItems()->sole());
    }

    public function test_a_cancelled_notice_is_created_again(): void
    {
        $this->service('2026-10-10');
        $this->generate('2026-10-08');
        Invoice::sole()->update(['status' => InvoiceStatus::Cancelled]);

        $this->generate('2026-10-09');

        $this->assertSame(2, Invoice::count());
    }

    public function test_exclusive_tax_is_added(): void
    {
        config(['gaiacp.tax.enabled' => true]);
        $client = Client::factory()->create();
        $this->service('2026-10-10', ['price_cents' => 8000], $client);
        $this->service('2026-10-10', ['price_cents' => 1500], $client);

        $this->generate('2026-10-08');

        $invoice = Invoice::sole();
        $this->assertSame(9500, $invoice->subtotal_cents);
        $this->assertSame(1360 + 255, $invoice->tax_cents);
        $this->assertSame(9500 + 1615, $invoice->total_cents);
        $this->assertSame('ΦΠΑ 17%', $invoice->taxLabel());
        $this->assertFalse($invoice->tax_inclusive);
    }

    public function test_inclusive_tax_is_taken_out_of_the_price(): void
    {
        config(['gaiacp.tax.enabled' => true, 'gaiacp.tax.inclusive' => true]);
        $this->service('2026-10-10', ['price_cents' => 11700]);

        $this->generate('2026-10-08');

        $invoice = Invoice::sole();
        $this->assertSame(11700, $invoice->total_cents);
        $this->assertSame(1700, $invoice->tax_cents);
        $this->assertSame(10000, $invoice->subtotal_cents);
    }

    public function test_country_rule_beats_the_general_one_and_exempt_clients_pay_none(): void
    {
        config(['gaiacp.tax' => ['enabled' => true, 'inclusive' => false, 'rules' => [
            ['name' => 'ΦΠΑ', 'rate' => 24, 'country' => null],
            ['name' => 'VAT', 'rate' => 19, 'country' => 'CY'],
        ]]]);

        $this->assertSame(['name' => 'ΦΠΑ', 'rate' => 24.0], Tax::ruleFor(Client::factory()->make(['country' => 'GR'])));
        $this->assertSame(['name' => 'VAT', 'rate' => 19.0], Tax::ruleFor(Client::factory()->make(['country' => 'CY'])));
        $this->assertNull(Tax::ruleFor(Client::factory()->make(['country' => 'GR', 'tax_exempt' => true])));

        config(['gaiacp.tax.enabled' => false]);
        $this->assertNull(Tax::ruleFor(Client::factory()->make()));
    }

    public function test_a_notice_keeps_its_tax_when_the_rate_changes(): void
    {
        config(['gaiacp.tax.enabled' => true]);
        $this->service('2026-10-10', ['price_cents' => 10000]);
        $this->generate('2026-10-08');

        config(['gaiacp.tax.rules' => [['name' => 'ΦΠΑ', 'rate' => 24, 'country' => null]]]);

        $this->assertSame(11700, Invoice::sole()->fresh()->total_cents);
    }

    public function test_paying_the_notice_pays_every_line_and_moves_due_dates(): void
    {
        config(['gaiacp.tax.enabled' => true]);
        $client = Client::factory()->create();
        $a = $this->service('2026-10-20', ['price_cents' => 8000], $client);
        $b = $this->service('2026-10-20', ['price_cents' => 2000], $client);
        $this->generate('2026-10-08');
        $invoice = Invoice::sole();

        $payments = app(RecordInvoicePayment::class)->handle($invoice, CarbonImmutable::parse('2026-10-09'), 'bank_transfer', 'REF');

        $this->assertSame([9360, 2340], collect($payments)->pluck('amount_cents')->all());
        $this->assertSame('2027-10-20', $a->fresh()->next_due_date->toDateString());
        $this->assertSame('2027-10-20', $b->fresh()->next_due_date->toDateString());
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame('2026-10-09', $invoice->paid_on->toDateString());
        $this->assertSame(0, $invoice->items()->whereNull('payment_id')->count());
    }

    public function test_paying_each_service_marks_its_line_and_the_last_one_the_notice(): void
    {
        $client = Client::factory()->create();
        $a = $this->service('2026-10-20', [], $client);
        $b = $this->service('2026-10-20', [], $client);
        $this->generate('2026-10-08');
        $invoice = Invoice::sole();

        app(RecordPayment::class)->handle($a, 6000, CarbonImmutable::parse('2026-10-09'), 'cash');
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);

        app(RecordPayment::class)->handle($b, 6000, CarbonImmutable::parse('2026-10-10'), 'cash');
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('2026-10-10', $invoice->fresh()->paid_on->toDateString());
    }

    public function test_reversing_a_payment_reopens_the_notice(): void
    {
        $service = $this->service('2026-10-20');
        $this->generate('2026-10-08');
        $payment = app(RecordPayment::class)->handle($service, 6000, CarbonImmutable::parse('2026-10-09'), 'cash');

        app(ReversePayment::class)->handle($payment);

        $invoice = Invoice::sole();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertNull($invoice->paid_on);
        $this->assertNull($invoice->items->first()->payment_id);
    }

    public function test_a_line_whose_service_moved_on_is_not_paid_through_the_notice(): void
    {
        $service = $this->service('2026-10-20');
        $this->generate('2026-10-08');
        $service->update(['next_due_date' => '2027-10-20']);

        $this->expectException(RuntimeException::class);

        app(RecordInvoicePayment::class)->handle(Invoice::sole(), CarbonImmutable::parse('2026-10-09'), 'cash');
    }

    public function test_emails_created_then_reminder_then_first_and_second_overdue(): void
    {
        $service = $this->service('2026-10-22');

        foreach (['2026-10-08', '2026-10-09', '2026-10-15', '2026-10-16', '2026-10-21', '2026-10-23', '2026-10-24', '2026-10-29', '2026-11-05', '2026-11-06'] as $day) {
            $this->run_($day);
        }

        $this->assertSame([
            MailTemplate::InvoiceCreated,          // 08/10, 14 days before: no separate 14-day reminder
            MailTemplate::InvoicePaymentReminder,  // 15/10, 7 days before
            MailTemplate::InvoicePaymentReminder,  // 21/10, 1 day before
            MailTemplate::InvoiceFirstOverdue,     // 23/10, 1 day late
            MailTemplate::InvoiceSecondOverdue,    // 29/10, 7 days late
            MailTemplate::InvoiceSecondOverdue,    // 05/11, 14 days late
        ], $this->sent());
        $this->assertSame(['before:7', 'before:1', 'overdue:1', 'overdue:7', 'overdue:14'], Invoice::sole()->reminders()->orderBy('id')->pluck('kind')->all());
        $this->assertNotNull(Invoice::sole()->emailed_at);
        $this->assertSame(1, $service->client->invoices()->count());
    }

    public function test_paid_notices_get_no_more_emails(): void
    {
        $service = $this->service('2026-10-22');
        $this->run_('2026-10-08');
        app(RecordPayment::class)->handle($service, 6000, CarbonImmutable::parse('2026-10-09'), 'cash');

        $this->run_('2026-10-15');
        $this->run_('2026-10-23');

        $this->assertSame([MailTemplate::InvoiceCreated], $this->sent());
    }

    public function test_a_switched_off_template_is_skipped_and_resumes_when_switched_on(): void
    {
        MailTemplates::save(MailTemplate::InvoiceCreated, null, null, false);
        $this->service('2026-10-22');

        $this->run_('2026-10-08');
        $this->assertSame([], $this->sent());
        $this->assertNull(Invoice::sole()->emailed_at);

        MailTemplates::save(MailTemplate::InvoiceCreated, null, null, true);
        $this->run_('2026-10-09');
        $this->assertSame([MailTemplate::InvoiceCreated], $this->sent());
    }

    public function test_failed_send_is_retried_next_run(): void
    {
        $this->service('2026-10-22');
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP down'));

        $this->artisan('gaiacp:send-reminders', ['--date' => '2026-10-08'])->assertFailed();

        $this->assertNull(Invoice::sole()->emailed_at);
        $this->assertDatabaseHas('activity_logs', ['event' => 'email.failed']);
    }

    public function test_email_shows_lines_tax_and_total(): void
    {
        config(['gaiacp.tax.enabled' => true, 'gaiacp.payment_instructions' => 'IBAN GR00 0000']);
        $this->service('2026-10-20', ['label' => 'Pro hosting', 'domain' => 'example.gr', 'price_cents' => 8000]);
        $this->generate('2026-10-08');
        $invoice = Invoice::sole()->load('client', 'items');

        $values = SendInvoiceEmails::values($invoice, CarbonImmutable::parse('2026-10-27'));
        $html = (new ClientMail(MailTemplate::InvoiceFirstOverdue, $invoice->client, $values))->render();

        $this->assertStringContainsString('PF-2026-0001', $html);
        $this->assertStringContainsString('Pro hosting (example.gr)', $html);
        $this->assertStringContainsString('20/10/2026 - 19/10/2027', $html);
        $this->assertStringContainsString('ΦΠΑ 17%', $html);
        $this->assertStringContainsString('93.60', $html);
        $this->assertStringContainsString('IBAN GR00 0000', $html);
        $this->assertSame('7', $values['days_overdue']);
    }

    public function test_admin_pages_list_view_pay_and_cancel(): void
    {
        $this->actingAs(User::factory()->create());
        $paid = $this->service('2026-10-20');
        $other = $this->service('2026-10-21');
        $this->generate('2026-10-08');
        [$first, $second] = Invoice::query()->orderBy('id')->get();

        $this->get('/admin/invoices')->assertOk()->assertSee('PF-2026-0001');
        $this->get("/admin/clients/{$paid->client_id}/edit")->assertOk();

        Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$first, $second]);

        Livewire::test(ViewInvoice::class, ['record' => $first->id])
            ->callAction('recordInvoicePayment', ['paid_on' => '2026-10-09', 'method' => 'bank_transfer'])
            ->assertNotified('Payment recorded');
        $this->assertSame(InvoiceStatus::Paid, $first->fresh()->status);
        $this->assertSame('2027-10-20', $paid->fresh()->next_due_date->toDateString());

        Livewire::test(ViewInvoice::class, ['record' => $second->id])
            ->callAction('sendInvoiceEmail', ['template' => MailTemplate::InvoiceModified->value])
            ->assertNotified('Email sent');
        $this->assertSame([MailTemplate::InvoiceModified], $this->sent());

        Livewire::test(ViewInvoice::class, ['record' => $second->id])
            ->callAction('cancelInvoice')
            ->assertNotified('Payment notice cancelled');
        $this->assertSame(InvoiceStatus::Cancelled, $second->fresh()->status);
        $this->assertSame('2026-10-21', $other->fresh()->next_due_date->toDateString());

        // Every notice by default; the chips narrow it
        $list = Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$first, $second]);
        $list->call('applyQuickFilter', 'unpaid')->assertCanNotSeeTableRecords([$first, $second]);
        $list->call('applyQuickFilter', 'paid')->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
        $list->call('applyQuickFilter', 'cancelled')->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);
        $list->call('applyQuickFilter', 'all')->assertCanSeeTableRecords([$first, $second]);
    }

    public function test_the_list_is_newest_first_and_the_chips_count_each_status(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();
        $notice = fn (string $date, string $due, InvoiceStatus $status) => Invoice::create([
            'number' => Invoice::nextNumber(now()), 'client_id' => $client->id, 'date' => $date, 'due_date' => $due, 'status' => $status,
            'currency' => 'EUR', 'subtotal_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);
        $old = $notice(today()->subDays(40)->toDateString(), today()->subDays(30)->toDateString(), InvoiceStatus::Unpaid);
        $paid = $notice(today()->subDays(20)->toDateString(), today()->subDays(10)->toDateString(), InvoiceStatus::Paid);
        $new = $notice(today()->toDateString(), today()->addDays(14)->toDateString(), InvoiceStatus::Unpaid);
        $cancelled = $notice(today()->subDays(5)->toDateString(), today()->addDays(5)->toDateString(), InvoiceStatus::Cancelled);

        $list = Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$new, $cancelled, $paid, $old], inOrder: true);

        $chips = $list->instance()->statusChips();
        $this->assertSame([4, 2, 1, 1, 1], array_column(array_values($chips), 'count'));
        $this->assertTrue($chips['all']['active']);

        $list->call('applyQuickFilter', 'overdue')->assertCanSeeTableRecords([$old])->assertCanNotSeeTableRecords([$new, $paid, $cancelled]);
        $this->assertTrue($list->instance()->statusChips()['overdue']['active']);
        $this->assertFalse($list->instance()->statusChips()['all']['active']);

        // The same chips on the client page's tab, counting only that client's notices
        Invoice::create([
            'number' => Invoice::nextNumber(now()), 'client_id' => Client::factory()->create()->id, 'date' => today(), 'due_date' => today(),
            'currency' => 'EUR', 'subtotal_cents' => 100, 'tax_cents' => 0, 'total_cents' => 100,
        ]);
        $tab = Livewire::test(InvoicesRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class])
            ->assertCanSeeTableRecords([$new, $cancelled, $paid, $old], inOrder: true)
            ->assertSee(__('Overdue'));
        $this->assertSame(4, $tab->instance()->statusChips()['all']['count']);
        $tab->call('applyQuickFilter', 'paid')->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$new, $old, $cancelled]);
    }

    public function test_the_list_shows_the_company_under_the_client_name(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papa', 'company' => 'Acme ΙΚΕ']);
        $this->service('2026-10-20', client: $client);
        $this->generate('2026-10-08');

        Livewire::test(ListInvoices::class)
            ->assertTableColumnFormattedStateSet('client.last_name', 'Maria Papa', Invoice::query()->sole())
            ->assertSee('Acme ΙΚΕ')
            ->assertDontSee('Maria Papa (Acme ΙΚΕ)');
    }

    public function test_tax_settings_are_saved_from_the_settings_page(): void
    {
        $this->actingAs(User::factory()->create());
        $undo = Repeater::fake();

        Livewire::test(SettingsPage::class)
            ->fillForm([
                'tax' => ['enabled' => true, 'inclusive' => '1', 'rules' => [['name' => 'ΦΠΑ', 'rate' => '17', 'country' => null], ['name' => 'VAT', 'rate' => '19', 'country' => 'CY']]],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $undo();

        $this->assertTrue(config('gaiacp.tax.enabled'));
        $this->assertTrue(config('gaiacp.tax.inclusive'));
        $this->assertSame([['name' => 'ΦΠΑ', 'rate' => 17.0, 'country' => null], ['name' => 'VAT', 'rate' => 19.0, 'country' => 'CY']], config('gaiacp.tax.rules'));
        $this->get(SettingsPage::getUrl())->assertOk()->assertSee('Tax rules');
    }

    private function placeOrder(?Client $client = null): Order
    {
        $this->travelTo('2026-10-09 10:00');

        return app(PlaceOrder::class)->handle($client ?? Client::factory()->create(), [
            ['type' => 'maintenance', 'label' => 'Maintenance', 'price_cents' => 12000, 'billing_cycle' => BillingCycle::Annually],
            ['type' => 'other', 'label' => 'Setup', 'price_cents' => 5000, 'first_payment_cents' => 7000, 'billing_cycle' => BillingCycle::Annually],
        ]);
    }

    public function test_placing_an_order_creates_and_emails_its_payment_notice(): void
    {
        $order = $this->placeOrder();

        $invoice = Invoice::sole();
        $this->assertSame($order->id, $invoice->order_id);
        $this->assertSame('2026-10-09', $invoice->due_date->toDateString());
        $this->assertSame(12000 + 7000, $invoice->total_cents); // first payments
        $this->assertNotNull($invoice->emailed_at);
        Mail::assertSent(ClientMail::class, fn (ClientMail $m) => $m->template() === MailTemplate::InvoiceCreated);

        // The daily run neither repeats it nor, once the order is completed unpaid, adds another.
        $this->run_('2026-10-09');
        app(CompleteOrder::class)->handle($order);
        $this->run_('2026-10-10');
        $this->assertSame(1, Invoice::count());
        $this->assertSame([MailTemplate::InvoiceCreated, MailTemplate::InvoiceFirstOverdue], $this->sent());
    }

    public function test_an_order_notice_stays_apart_from_renewals_due_the_same_day(): void
    {
        $client = Client::factory()->create();
        $this->service('2026-10-09', [], $client);

        $this->placeOrder($client);
        $this->generate('2026-10-09');

        $this->assertSame(2, Invoice::count());
        $this->assertSame(1, Invoice::query()->whereNotNull('order_id')->count());
    }

    public function test_paying_the_order_pays_its_notice(): void
    {
        $order = $this->placeOrder();

        app(RecordOrderPayment::class)->handle($order, CarbonImmutable::parse('2026-10-09'), 'bank_transfer');

        $this->assertSame(InvoiceStatus::Paid, Invoice::sole()->status);
    }

    public function test_paying_the_notice_pays_the_order(): void
    {
        $order = $this->placeOrder();

        app(RecordInvoicePayment::class)->handle(Invoice::sole(), CarbonImmutable::parse('2026-10-09'), 'bank_transfer');

        $this->assertSame(InvoiceStatus::Paid, Invoice::sole()->status);
        $this->assertSame([], app(RecordOrderPayment::class)->unpaid($order)->all());
    }

    public function test_cancelling_the_order_cancels_its_notice(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->placeOrder();

        Livewire::test(ViewOrder::class, ['record' => $order->id])->callAction('cancelOrder');
        $this->generate('2026-10-10');

        $this->assertSame(InvoiceStatus::Cancelled, Invoice::sole()->status);
    }

    public function test_the_daily_run_catches_up_orders_placed_before_notices_existed(): void
    {
        $order = $this->placeOrder();
        Invoice::query()->delete();

        $this->generate('2026-10-09');

        $this->assertSame($order->id, Invoice::sole()->order_id);
    }
}
