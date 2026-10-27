<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Services\Pages\EditService;
use App\Invoices\GenerateInvoices;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\FakesWhm;
use Tests\TestCase;

/** A set-up service changes client only through Move to another client (App\Actions\TransferService). */
class TransferServiceTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    private Client $from;

    private Client $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWhm();

        $this->actingAs(User::factory()->create());
        $this->travelTo(CarbonImmutable::parse('2026-10-08'));
        config(['gaiacp.invoices.days_before' => 14]);
        $this->from = Client::factory()->create(['first_name' => 'Old', 'last_name' => 'Owner']);
        $this->to = Client::factory()->create(['first_name' => 'New', 'last_name' => 'Owner']);
    }

    private function service(array $attributes = []): Service
    {
        return Service::factory()->for($this->from)->create($attributes + [
            'domain' => 'maria.gr', 'start_date' => '2025-10-15', 'next_due_date' => '2026-10-15',
        ]);
    }

    public function test_the_client_field_locks_once_the_service_is_set_up(): void
    {
        Livewire::test(EditService::class, ['record' => $this->service(['status' => ServiceStatus::Pending])->id])
            ->assertFormFieldEnabled('client_id')
            ->assertActionHidden('transferService');

        Livewire::test(EditService::class, ['record' => $this->service()->id])
            ->assertFormFieldDisabled('client_id')
            ->assertActionVisible('transferService');
    }

    public function test_moving_a_hosting_account_with_its_domain(): void
    {
        $hosting = $this->service();
        $domain = $this->service(['type' => ServiceType::Domain, 'label' => null]);
        $old = Payment::factory()->for($hosting)->create(['paid_on' => '2025-10-15']);
        app(GenerateInvoices::class)->handle(now());
        $notice = Invoice::sole();

        Livewire::test(EditService::class, ['record' => $hosting->id])
            ->callAction('transferService', ['client_id' => $this->to->id, 'with_domain' => true])
            ->assertNotified(__('Moved to :client, with its domain', ['client' => $this->to->name]));

        $this->assertSame($this->to->id, $hosting->fresh()->client_id);
        $this->assertSame($this->to->id, $domain->fresh()->client_id);
        $this->assertSame(InvoiceStatus::Cancelled, $notice->fresh()->status);

        // The payment stays with who made it
        $this->assertSame($this->from->id, $old->fresh()->client_id);
        $this->assertSame(1, $this->from->payments()->count());
        $this->assertSame(0, $this->to->payments()->count());

        $this->assertSame(2, ActivityLog::query()->where('event', 'service.transferred')->where('client_id', $this->from->id)->count());
        $this->assertSame(2, ActivityLog::query()->where('event', 'service.transferred')->where('client_id', $this->to->id)->count());

        // The next run bills the new client
        app(GenerateInvoices::class)->handle(now());
        $this->assertSame($this->to->id, Invoice::query()->unpaid()->sole()->client_id);

        // New payments are the new client's
        $this->assertSame($this->to->id, Payment::factory()->for($hosting->fresh())->create()->client_id);
    }

    public function test_the_domain_stays_unless_asked(): void
    {
        $hosting = $this->service();
        $domain = $this->service(['type' => ServiceType::Domain, 'label' => null]);

        Livewire::test(EditService::class, ['record' => $hosting->id])
            ->callAction('transferService', ['client_id' => $this->to->id, 'with_domain' => false])
            ->assertNotified(__('Moved to :client', ['client' => $this->to->name]));

        $this->assertSame($this->to->id, $hosting->fresh()->client_id);
        $this->assertSame($this->from->id, $domain->fresh()->client_id);
    }

    public function test_a_client_with_payments_only_keeps_its_delete_hidden(): void
    {
        $hosting = $this->service();
        Payment::factory()->for($hosting)->create();
        $hosting->update(['client_id' => $this->to->id]);

        Livewire::test(EditClient::class, ['record' => $this->from->id])
            ->assertActionHidden('delete');
    }

    public function test_the_migration_fills_in_who_paid(): void
    {
        $payment = Payment::factory()->for($this->service())->create();
        DB::table('payments')->update(['client_id' => null]);

        $migration = require database_path('migrations/2026_10_22_000020_add_client_id_to_payments_table.php');
        $migration->down();
        $migration->up();

        $this->assertSame($this->from->id, $payment->fresh()->client_id);
    }
}
