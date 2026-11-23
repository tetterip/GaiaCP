<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\EmailsRelationManager;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The client page's Emails tab: this client's Email log rows, with the text as sent. */
class ClientEmailsTabTest extends TestCase
{
    use RefreshDatabase;

    private function log(Client $client, array $attributes = []): EmailLog
    {
        return EmailLog::create($attributes + [
            'client_id' => $client->id,
            'to_client' => true,
            'to' => $client->email,
            'subject' => 'Payment notice PF-2026-0001',
            'body' => '<p>Hello</p>',
            'status' => 'sent',
        ]);
    }

    public function test_the_tab_lists_only_this_clients_emails_and_opens_one(): void
    {
        $client = Client::factory()->create();
        $mine = $this->log($client);
        $failed = $this->log($client, ['subject' => 'Overdue', 'status' => 'failed', 'error' => 'Mailbox full']);
        $other = $this->log(Client::factory()->create());
        $this->actingAs(User::factory()->create());

        $this->assertTrue(EmailsRelationManager::canViewForRecord($client, EditClient::class));
        $this->assertSame('1', EmailsRelationManager::getBadge($client, EditClient::class));

        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class])
            ->assertCanSeeTableRecords([$mine, $failed])
            ->assertCanNotSeeTableRecords([$other])
            ->mountAction(TestAction::make('view')->table($mine))
            ->assertSee('Payment notice PF-2026-0001');
    }

    public function test_the_tab_is_hidden_without_emails_or_without_access_to_the_email_log(): void
    {
        $client = Client::factory()->create();
        $this->actingAs(User::factory()->create());
        $this->assertFalse(EmailsRelationManager::canViewForRecord($client, EditClient::class));

        $this->log($client);
        $this->actingAs(User::factory()->limitedTo([AdminArea::Clients])->create());
        $this->assertFalse(EmailsRelationManager::canViewForRecord($client, EditClient::class));
    }
}
