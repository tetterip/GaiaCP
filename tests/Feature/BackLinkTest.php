<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The "‹ list" link a phone shows over a page title, where Filament hides the breadcrumbs (App\Support\BackLink). */
class BackLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_a_payment_notice_links_back_to_the_payment_notices(): void
    {
        $notice = Invoice::create([
            'number' => 'PF-2026-0009', 'client_id' => Client::factory()->create()->id, 'date' => today()->toDateString(),
            'due_date' => today()->toDateString(), 'currency' => 'EUR', 'subtotal_cents' => 1000, 'tax_cents' => 0,
            'total_cents' => 1000, 'status' => InvoiceStatus::Unpaid,
        ]);

        $this->get(InvoiceResource::getUrl('view', ['record' => $notice]))
            ->assertOk()
            ->assertSee('href="'.InvoiceResource::getUrl('index').'" wire:navigate.hover class="fi-back-link"', false);
    }

    public function test_a_service_links_back_to_its_client_tab(): void
    {
        $service = Service::factory()->maintenance()->create();

        $this->get(ServiceResource::getUrl('edit', ['record' => $service]))
            ->assertOk()
            ->assertSee('href="'.e((string) ServiceResource::clientUrl($service)).'" wire:navigate.hover class="fi-back-link"', false);
    }

    public function test_a_list_page_and_the_dashboard_have_none(): void
    {
        $this->get(InvoiceResource::getUrl('index'))->assertOk()->assertDontSee('class="fi-back-link"', false);
        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('class="fi-back-link"', false);
    }
}
