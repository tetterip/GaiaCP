<?php

namespace Tests\Feature;

use App\Enums\ClientMessageKind;
use App\Enums\ServiceStatus;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Models\ClientMessage;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Services list → pick some → Suspend / Unsuspend / Send message / End at the end of the cycle / Sync domains. */
class ServiceBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->actingAs(User::factory()->create());
    }

    private function list()
    {
        return Livewire::test(ListServices::class)->set('activeTab', 'all');
    }

    public function test_suspend_and_unsuspend_the_ones_they_apply_to(): void
    {
        $active = Service::factory()->maintenance()->create();
        $suspended = Service::factory()->maintenance()->create(['status' => ServiceStatus::Suspended, 'suspension_reason' => 'x']);

        $this->list()
            ->callTableBulkAction('suspend', [$active, $suspended], ['reason_type' => 'other', 'reason' => 'Abuse report'])
            ->assertNotified('1 service suspended');
        $this->assertSame(ServiceStatus::Suspended, $active->fresh()->status);
        $this->assertSame('Abuse report', $active->fresh()->suspension_reason);

        $this->list()
            ->callTableBulkAction('unsuspend', [$active, $suspended])
            ->assertNotified('2 services unsuspended');
        $this->assertSame(ServiceStatus::Active, $active->fresh()->status);
        $this->assertSame(ServiceStatus::Active, $suspended->fresh()->status);
    }

    public function test_end_at_the_end_of_the_cycle_and_keep_renewing(): void
    {
        $service = Service::factory()->maintenance()->create();
        $domain = Service::factory()->domain()->create();
        $ended = Service::factory()->maintenance()->create(['status' => ServiceStatus::Terminated]);

        $this->list()
            ->callTableBulkAction('endAtCycleEnd', [$service, $domain, $ended])
            ->assertNotified('2 services end at the end of their cycle');
        $this->assertTrue($service->fresh()->auto_terminate);
        $this->assertFalse($domain->fresh()->auto_renew);
        $this->assertFalse($ended->fresh()->auto_terminate);

        $this->list()
            ->callTableBulkAction('keepRenewing', [$service, $domain])
            ->assertNotified('2 services renew again');
        $this->assertFalse($service->fresh()->auto_terminate);
    }

    public function test_send_message_and_sync_skips_what_is_not_a_domain(): void
    {
        $first = Service::factory()->maintenance()->create();
        $second = Service::factory()->maintenance()->create(['client_id' => $first->client_id]);

        $this->list()
            ->callTableBulkAction('sendMessage', [$first, $second], [
                'kind' => ClientMessageKind::Service->value, 'subject' => 'Maintenance tonight', 'body' => 'Hello',
            ])
            ->assertNotified('Sending to 1 client');
        $this->assertSame([$first->client_id], ClientMessage::query()->sole()->recipients()->pluck('client_id')->all());

        $this->list()
            ->callTableBulkAction('syncDomains', [$first])
            ->assertNotified('0 domains synced');
    }

    public function test_send_message_needs_the_clients_area(): void
    {
        Service::factory()->maintenance()->create();
        $this->actingAs(User::factory()->create(['super_admin' => false, 'admin_areas' => ['services']]));

        $this->list()->assertTableBulkActionHidden('sendMessage');

        $this->actingAs(User::factory()->create(['super_admin' => false, 'admin_areas' => ['services', 'clients']]));

        $this->list()->assertTableBulkActionVisible('sendMessage');
    }
}
