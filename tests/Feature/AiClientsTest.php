<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Enums\InvoiceStatus;
use App\Enums\MailTemplate;
use App\Enums\ServiceType;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Mail\ClientMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** The AI assistant about a client: a personal payment reminder and suggestions on the client's page, without who they are. */
class AiClientsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('claude'));
        config(['gaiacp.claude.api_key' => 'sk-ant-secret']);

        $this->actingAs(User::factory()->create());
        $this->client = Client::factory()->create(['first_name' => 'Xanthippi', 'last_name' => 'Karali', 'email' => 'xk@mail.example', 'vat_number' => '099999999', 'created_at' => '2024-03-01']);
    }

    private function claudeAnswers(string $text): void
    {
        Http::fake(['api.anthropic.com/v1/messages' => Http::response([
            'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => 700, 'output_tokens' => 150],
        ])]);
    }

    private function sent(): string
    {
        $request = Http::recorded(fn (Request $request) => str_contains($request->url(), 'anthropic'))->sole()[0];

        return $request['system']."\n".$request['messages'][0]['content'];
    }

    private function notice(int $cents, string $due, ?string $paidOn, string $number): Invoice
    {
        return Invoice::create([
            'number' => $number, 'client_id' => $this->client->id, 'date' => $due, 'due_date' => $due, 'currency' => 'EUR',
            'subtotal_cents' => $cents, 'tax_cents' => 0, 'total_cents' => $cents,
            'status' => $paidOn ? InvoiceStatus::Paid : InvoiceStatus::Unpaid, 'paid_on' => $paidOn,
        ]);
    }

    public function test_a_personal_reminder_is_written_from_how_the_client_pays_and_sent_as_edited(): void
    {
        $this->notice(5000, today()->subMonths(6)->toDateString(), today()->subMonths(6)->addDays(20)->toDateString(), 'PF-2026-0001');
        $this->notice(5000, today()->subMonths(3)->toDateString(), today()->subMonths(3)->toDateString(), 'PF-2026-0002');
        $notice = $this->notice(9500, today()->subDays(12)->toDateString(), null, 'PF-2026-0003');
        $this->claudeAnswers('{"subject": "A reminder about {$invoice_number}", "body": "Dear {$client_first_name},\n\nWe have not yet received {$invoice_total}."}');

        Livewire::test(ViewInvoice::class, ['record' => $notice->getRouteKey()])
            ->mountAction('aiReminder')
            ->assertSchemaStateSet(['subject' => 'A reminder about {$invoice_number}'], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertNotified(__('Email sent'));

        Mail::assertSent(ClientMail::class, function (ClientMail $mail) {
            $text = $mail->render();

            return $mail->mailTemplate === MailTemplate::InvoiceFirstOverdue && $mail->envelope()->subject === 'A reminder about PF-2026-0003'
                && str_contains($text, 'Dear Xanthippi') && str_contains($text, '€95.00');
        });
        $this->assertNotNull($notice->refresh()->emailed_at);

        $sent = $this->sent();
        $this->assertStringContainsString('€95.00, due '.today()->subDays(12)->toDateString().', 12 days overdue.', $sent);
        $this->assertStringContainsString('Paid 2 payment notices in the last 24 months: 1 of them late, on average 20 days late', $sent);
        $this->assertStringContainsString('{$client_first_name}', $sent, 'the template text with its placeholders');
        foreach (['Xanthippi', 'Karali', 'xk@mail.example', '099999999', 'PF-2026-0001'] as $private) {
            $this->assertStringNotContainsString($private, $sent);
        }
    }

    public function test_suggestions_come_from_services_and_products_without_names_or_domains(): void
    {
        $product = Product::factory()->priced(1000)->create(['name' => 'Starter', 'type' => ServiceType::Hosting]);
        Product::factory()->priced(2500)->create(['name' => 'Pro', 'type' => ServiceType::Hosting]);
        Service::factory()->create(['client_id' => $this->client->id, 'type' => ServiceType::Hosting, 'product_id' => $product->id, 'domain' => 'karali-bakery.gr']);
        Service::factory()->create(['client_id' => $this->client->id, 'type' => ServiceType::Domain, 'domain' => 'karali-bakery.gr']);
        Service::factory()->create(['client_id' => $this->client->id, 'type' => ServiceType::Domain, 'domain' => 'karali-shop.gr']);
        $this->claudeAnswers('{"suggestions": [{"kind": "offer", "text": "Offer hosting for site 2."}, {"kind": "nonsense", "text": "Check in with them."}, {"kind": "risk", "text": ""}]}');

        Livewire::test(EditClient::class, ['record' => $this->client->getRouteKey()])
            ->mountAction('aiClientHints')
            ->assertSchemaStateSet(['hints' => [['kind' => 'offer', 'text' => 'Offer hosting for site 2.'], ['kind' => 'care', 'text' => 'Check in with them.']]], 'mountedActionSchema0');

        $sent = $this->sent();
        $this->assertStringContainsString('Web hosting "Starter" for site 1', $sent);
        $this->assertStringContainsString('Domain name for site 1', $sent);
        $this->assertStringContainsString('Domain name for site 2', $sent);
        $this->assertStringContainsString('Web hosting "Pro"', $sent, 'the products on offer');
        foreach (['Xanthippi', 'Karali', 'karali', 'xk@mail.example'] as $private) {
            $this->assertStringNotContainsString($private, $sent);
        }
    }

    public function test_the_buttons_need_their_switches_and_suggestions_need_billing(): void
    {
        $notice = $this->notice(9500, today()->subDays(3)->toDateString(), null, 'PF-2026-0009');
        config(['gaiacp.ai.features.reminders' => false]);
        Livewire::test(ViewInvoice::class, ['record' => $notice->getRouteKey()])->assertActionHidden('aiReminder');

        Livewire::test(EditClient::class, ['record' => $this->client->getRouteKey()])->assertActionVisible('aiClientHints');
        $this->actingAs(User::factory()->limitedTo([AdminArea::Clients])->create());
        Livewire::test(EditClient::class, ['record' => $this->client->getRouteKey()])->assertActionHidden('aiClientHints');
        Http::assertNothingSent();
    }
}
