<?php

namespace Tests\Feature;

use App\Ai\DataQuestions;
use App\Enums\AdminArea;
use App\Enums\ServiceType;
use App\Filament\Clusters\Reports\Pages\AskData;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Reports → Ask in plain words: the AI service picks a ready-made question; GaiaCP answers it without sending data. */
class AskDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('claude'));
        config(['gaiacp.claude.api_key' => 'sk-ant-secret']);
    }

    private function claudeAnswers(string $text): void
    {
        Http::fake(['api.anthropic.com/v1/messages' => Http::response([
            'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => 400, 'output_tokens' => 30],
        ])]);
    }

    private function notice(Client $client, int $cents, int $daysAgo, string $number): Invoice
    {
        return Invoice::create([
            'number' => $number, 'client_id' => $client->id, 'date' => today()->subDays($daysAgo), 'due_date' => today()->subDays($daysAgo),
            'currency' => 'EUR', 'subtotal_cents' => $cents, 'tax_cents' => 0, 'total_cents' => $cents,
        ]);
    }

    public function test_who_owes_more_than_100_is_answered_from_the_data_which_is_never_sent(): void
    {
        $this->actingAs(User::factory()->create());
        $maria = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papadopoulou']);
        $nikos = Client::factory()->create(['first_name' => 'Nikos', 'last_name' => 'Small']);
        $this->notice($maria, 9000, 10, 'PF-2026-0001');
        $this->notice($maria, 6000, 2, 'PF-2026-0002');
        $this->notice($nikos, 5000, 40, 'PF-2026-0003');
        $this->claudeAnswers("```json\n{\"question\": \"clients_owing\", \"params\": {\"min_amount\": 100}}\n```");

        $page = Livewire::test(AskData::class)
            ->set('question', 'Who owes more than €100?')
            ->call('ask')
            ->assertHasNoErrors()
            ->assertSee(__('Read as: :question', ['question' => __('Clients who owe at least :amount', ['amount' => '€100.00'])]))
            ->assertSee('Maria Papadopoulou')
            ->assertSee('€150.00')
            ->assertDontSee('Nikos Small');
        $this->assertCount(1, $page->get('answer')['rows']);

        Http::assertSent(function (Request $request) {
            $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($sent, 'Who owes more than €100?') && str_contains($sent, 'clients_owing') && str_contains($sent, 'Today is '.now()->toDateString())
                && ! str_contains($sent, 'Maria') && ! str_contains($sent, 'Papadopoulou') && ! str_contains($sent, 'PF-2026') && ! str_contains($sent, '150');
        });
    }

    public function test_payments_of_a_period_and_a_type_are_totalled(): void
    {
        $this->actingAs(User::factory()->create());
        $hosting = Service::factory()->create(['type' => ServiceType::Hosting]);
        Payment::factory()->create(['service_id' => $hosting->id, 'client_id' => $hosting->client_id, 'amount_cents' => 12000, 'currency' => 'EUR', 'paid_on' => '2026-09-10']);
        Payment::factory()->create(['service_id' => $hosting->id, 'client_id' => $hosting->client_id, 'amount_cents' => 3000, 'currency' => 'EUR', 'paid_on' => '2026-10-02']);
        $this->claudeAnswers('{"question": "payments_total", "params": {"from": "2026-09-01", "to": "2026-09-30", "type": "hosting"}}');

        $page = Livewire::test(AskData::class)->call('ask', 'How much hosting did we get in September?');

        $this->assertSame([['currency' => 'EUR', 'count' => 1, 'total' => '€120.00']], $page->get('answer')['rows']);
    }

    public function test_a_question_it_cant_answer_gets_the_reply_and_bad_parameters_are_tamed(): void
    {
        $this->actingAs(User::factory()->create());
        $this->claudeAnswers('{"question": null, "reply": "Μπορώ να απαντήσω για οφειλές, πληρωμές, υπηρεσίες, domains, πελάτες και tickets."}');

        Livewire::test(AskData::class)->call('ask', 'Τι καιρό θα κάνει αύριο;')
            ->assertSet('answer', null)
            ->assertSee('Μπορώ να απαντήσω για οφειλές');

        // Days out of range, a date that is not one: still a safe query
        $answer = app(DataQuestions::class)->run('domains_expiring', ['days' => 99999], auth()->user());
        $this->assertSame(__('Domains expiring in the next :days days', ['days' => 365]), $answer['title']);
        $this->assertSame([], app(DataQuestions::class)->run('new_clients', ['from' => 'DROP TABLE clients', 'to' => '2026-13-45'], auth()->user())['rows']);

        // Every question runs with no parameters at all
        Service::factory()->create(['type' => ServiceType::Domain, 'domain' => 'soon.gr', 'next_due_date' => today()->addDays(5)]);
        foreach (array_keys(DataQuestions::catalogue()) as $question) {
            $this->assertIsArray(app(DataQuestions::class)->run($question, [], auth()->user())['rows'], $question);
        }
        $this->assertSame('soon.gr', app(DataQuestions::class)->run('domains_expiring', [], auth()->user())['rows'][0]['domain']);
    }

    public function test_an_admin_is_offered_only_the_questions_of_their_areas(): void
    {
        User::factory()->create(); // the super admin
        $this->actingAs(User::factory()->limitedTo([AdminArea::Reports, AdminArea::Services])->create());
        $this->claudeAnswers('{"question": "clients_owing", "params": {}}');

        Livewire::test(AskData::class)->call('ask', 'Who owes money?')
            ->assertSet('answer', null)
            ->assertSee(__('The assistant can\'t answer that from your data yet. Try one of the examples.'));

        Http::assertSent(fn (Request $request) => str_contains($request['system'], 'services_due') && ! str_contains($request['system'], 'clients_owing'));
        $this->assertSame(['services_due', 'services_count', 'domains_expiring'], DataQuestions::allowed(auth()->user()));
    }

    public function test_the_page_needs_the_switch_and_the_reports_area(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertTrue(AskData::canAccess());

        config(['gaiacp.ai.features.questions' => false]);
        $this->assertFalse(AskData::canAccess());

        config(['gaiacp.ai.features.questions' => true]);
        $this->actingAs(User::factory()->limitedTo([AdminArea::Billing])->create());
        $this->assertFalse(AskData::canAccess());
    }
}
