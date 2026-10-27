<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\PaymentNotices\Pages\ViewPaymentNotice;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** A payment notice keeps the client's details from the day it was issued (invoices.bill_to). */
class BillToTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create([
            'first_name' => 'Maria', 'last_name' => 'Papadopoulou', 'company' => 'Acme ΙΚΕ',
            'address1' => 'Ermou 1', 'city' => 'Athens', 'postcode' => '10563', 'vat_number' => '123456789', 'tax_office' => 'Α Αθηνών',
        ]);
    }

    private function notice(InvoiceStatus $status = InvoiceStatus::Unpaid): Invoice
    {
        return Invoice::create([
            'number' => Invoice::nextNumber(now()),
            'client_id' => $this->client->id,
            'status' => $status,
            'date' => now(),
            'due_date' => now()->addDays(14),
            'currency' => 'EUR',
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
        ]);
    }

    public function test_a_notice_keeps_the_details_it_was_issued_with(): void
    {
        $notice = $this->notice();
        $this->assertSame('Ermou 1', $notice->bill_to['address1']);
        $this->assertFalse($notice->billToChanged());

        $this->client->update(['address1' => 'Stadiou 5', 'company' => null]);
        $notice = $notice->fresh();

        $this->assertSame('Ermou 1', $notice->billTo()->address1);
        $this->assertSame('Acme ΙΚΕ', $notice->billTo()->company);
        $this->assertTrue($notice->billToChanged());

        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]), 'client');
        Filament::setCurrentPanel('client');
        $this->get(ViewPaymentNotice::getUrl(['record' => $notice->getRouteKey()], panel: 'client'))
            ->assertOk()
            ->assertSee('Ermou 1')
            ->assertSee('Acme ΙΚΕ')
            ->assertDontSee('Stadiou 5');
    }

    public function test_the_admin_updates_an_open_notice_to_the_current_details(): void
    {
        $this->actingAs(User::factory()->create());
        $notice = $this->notice();

        Livewire::test(ViewInvoice::class, ['record' => $notice->id])
            ->assertActionHidden('updateBillTo')
            ->assertSee('Ermou 1');

        $this->client->update(['address1' => 'Stadiou 5']);

        Livewire::test(ViewInvoice::class, ['record' => $notice->id])
            ->assertSee(__('The client\'s details have changed since'))
            ->callAction('updateBillTo')
            ->assertNotified(__('Client details updated'))
            ->assertActionHidden('updateBillTo');

        $this->assertSame('Stadiou 5', $notice->fresh()->billTo()->address1);
        $this->assertSame('invoice.bill_to_updated', ActivityLog::query()->latest('id')->value('event'));
    }

    public function test_a_paid_notice_cannot_be_updated(): void
    {
        $this->actingAs(User::factory()->create());
        $notice = $this->notice(InvoiceStatus::Paid);
        $this->client->update(['address1' => 'Stadiou 5']);

        Livewire::test(ViewInvoice::class, ['record' => $notice->id])->assertActionHidden('updateBillTo');
        $this->assertSame('Ermou 1', $notice->fresh()->billTo()->address1);
    }
}
