<?php

namespace Tests\Feature;

use App\Actions\ModuleQueue;
use App\Actions\ServiceLifecycle;
use App\Actions\SetUpService;
use App\Enums\ServiceStatus;
use App\Filament\Resources\ModuleQueue\Pages\ListModuleQueue;
use App\Mail\DailySummaryMail;
use App\Models\ActivityLog;
use App\Models\ModuleQueueItem;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\ProvisioningException;
use App\Reports\DailySummary;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\FakesWhm;
use Tests\TestCase;

class ModuleQueueTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeWhm();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
        $this->admin = User::factory()->create();
    }

    private function pendingHosting(): Service
    {
        return Service::factory()->create(['status' => ServiceStatus::Pending, 'domain' => 'acme.gr', 'username' => 'acme']);
    }

    /** Set it up while the server says no; the failure is what the queue keeps. */
    private function failSetUp(Service $service, string $reason = 'Access denied'): void
    {
        $this->whmFailures['createacct'] = $reason;

        try {
            app(SetUpService::class)->handle($service);
            $this->fail('The set up should have failed.');
        } catch (ProvisioningException) {
        }
    }

    public function test_a_failed_set_up_waits_in_the_queue_and_rings_the_bell(): void
    {
        $service = $this->pendingHosting();
        $this->failSetUp($service);

        $item = ModuleQueueItem::query()->sole();
        $this->assertSame(ModuleQueueItem::SETUP, $item->action);
        $this->assertSame($service->id, $item->service_id);
        $this->assertStringContainsString('Access denied', $item->error);
        $this->assertSame($service->server->name, $item->module);
        $this->assertSame(1, $item->attempts);
        $this->assertSame(1, $this->admin->notifications()->count());

        // The same failure again (Complete order pressed twice) updates the same item
        $this->failSetUp($service, 'Server busy');
        $item->refresh();
        $this->assertSame(1, ModuleQueueItem::query()->count());
        $this->assertSame(2, $item->attempts);
        $this->assertStringContainsString('Server busy', $item->error);
    }

    public function test_retry_sets_it_up_and_closes_the_item(): void
    {
        $service = $this->pendingHosting();
        $this->failSetUp($service);
        unset($this->whmFailures['createacct']);

        $this->actingAs($this->admin);
        $this->assertTrue(app(ModuleQueue::class)->retry(ModuleQueueItem::query()->sole()));

        $item = ModuleQueueItem::query()->sole();
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame(ModuleQueueItem::RETRIED, $item->resolution);
        $this->assertSame($this->admin->id, $item->resolved_by);
        $this->assertNotNull($item->resolved_at);
    }

    public function test_a_set_up_done_another_way_closes_the_item(): void
    {
        $service = $this->pendingHosting();
        $this->failSetUp($service);
        unset($this->whmFailures['createacct']);

        app(SetUpService::class)->handle($service->refresh());

        $this->assertSame(ModuleQueueItem::DONE, ModuleQueueItem::query()->sole()->resolution);
    }

    public function test_the_cron_retries_then_rings_the_bell_once(): void
    {
        config(['gaiacp.automation.module_retries' => 2]);
        $service = $this->pendingHosting();
        $this->failSetUp($service);
        $queue = app(ModuleQueue::class);

        // Not before RETRY_MINUTES
        $this->assertSame(0, $queue->runDue(2)['retried']);

        $this->travel(ModuleQueue::RETRY_MINUTES)->minutes();
        $this->artisan('gaiacp:retry-module-queue')->assertSuccessful();
        $this->travel(ModuleQueue::RETRY_MINUTES)->minutes();
        $this->artisan('gaiacp:retry-module-queue')->assertSuccessful();
        $this->travel(ModuleQueue::RETRY_MINUTES)->minutes();
        $this->assertSame(0, $queue->runDue(2)['retried'], 'no more automatic tries');

        $item = ModuleQueueItem::query()->sole();
        $this->assertSame(2, $item->auto_attempts);
        $this->assertSame(3, $item->attempts);
        $this->assertNotNull($item->gave_up_at);
        $this->assertNull($item->resolved_at);
        $this->assertSame(1, ActivityLog::query()->where('event', 'module_queue.gave_up')->count());
        // the first failure and the gave-up, not every retry
        $this->assertSame(2, $this->admin->notifications()->count());
        $this->assertSame(2, ActivityLog::query()->where('event', 'provisioning.failed')->where('properties->retry', true)->count());
    }

    public function test_the_cron_fixes_it_once_the_server_is_back(): void
    {
        $service = $this->pendingHosting();
        $this->failSetUp($service);
        unset($this->whmFailures['createacct']);

        $this->travel(ModuleQueue::RETRY_MINUTES)->minutes();
        $this->artisan('gaiacp:retry-module-queue')->assertSuccessful();

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame(ModuleQueueItem::RETRIED, ModuleQueueItem::query()->sole()->resolution);
    }

    public function test_a_failed_suspension_for_non_payment_is_dropped_once_paid(): void
    {
        $service = Service::factory()->create(['next_due_date' => '2026-10-01', 'start_date' => '2025-10-01']);
        $this->whmAccounts[$service->username] = false;
        $this->whmFailures['suspendacct'] = 'Server busy';

        try {
            app(ServiceLifecycle::class)->suspend($service, Service::SUSPENDED_OVERDUE);
        } catch (ProvisioningException) {
        }

        $item = ModuleQueueItem::query()->sole();
        $this->assertSame(ModuleQueueItem::SUSPEND, $item->action);
        $this->assertSame(Service::SUSPENDED_OVERDUE, $item->reason);
        $this->assertTrue(ModuleQueue::retriesByItself($item));

        $service->update(['next_due_date' => '2027-10-01']); // paid
        $this->assertSame(1, app(ModuleQueue::class)->sweep());
        $this->assertSame(ModuleQueueItem::NOT_NEEDED, $item->refresh()->resolution);
    }

    public function test_terminate_waits_for_an_admin(): void
    {
        $service = Service::factory()->create();
        $this->whmAccounts[$service->username] = false;
        $this->whmFailures['removeacct'] = 'Access denied';

        try {
            app(ServiceLifecycle::class)->terminate($service);
        } catch (ProvisioningException) {
        }

        $item = ModuleQueueItem::query()->sole();
        $this->assertSame(ModuleQueueItem::TERMINATE, $item->action);
        $this->travel(ModuleQueue::RETRY_MINUTES)->minutes();
        $this->assertSame([], app(ModuleQueue::class)->due(3));

        unset($this->whmFailures['removeacct']);
        $this->actingAs($this->admin);
        Livewire::test(ListModuleQueue::class)
            ->assertCanSeeTableRecords([$item])
            ->callAction(TestAction::make('retry')->table($item))
            ->assertNotified(__('Done'));

        $this->assertSame(ServiceStatus::Terminated, $service->refresh()->status);
        $this->assertSame(ModuleQueueItem::RETRIED, $item->refresh()->resolution);
    }

    public function test_calls_an_admin_watched_fail_are_not_queued(): void
    {
        $service = Service::factory()->create();
        $this->whmAccounts[$service->username] = false;
        $this->whmFailures['passwd'] = 'Weak password';

        try {
            app(ServiceLifecycle::class)->changePassword($service, 'secret');
        } catch (ProvisioningException) {
        }

        $this->assertTrue(ActivityLog::query()->where('event', 'provisioning.failed')->exists());
        $this->assertSame(0, ModuleQueueItem::query()->count());
    }

    public function test_mark_resolved_and_retry_all_on_the_page(): void
    {
        $first = $this->pendingHosting();
        $this->failSetUp($first);
        $second = Service::factory()->create(['status' => ServiceStatus::Pending, 'domain' => 'beta.gr', 'username' => 'beta']);
        $this->failSetUp($second);
        [$a, $b] = ModuleQueueItem::query()->orderBy('id')->get()->all();

        $this->actingAs($this->admin);
        Livewire::test(ListModuleQueue::class)
            ->assertCanSeeTableRecords([$a, $b])
            ->assertSee('Access denied')
            ->callAction(TestAction::make('resolve')->table($a))
            ->assertNotified(__('Marked resolved'));

        $this->assertSame(ModuleQueueItem::MARKED, $a->refresh()->resolution);
        $this->assertSame(ServiceStatus::Pending, $first->refresh()->status, 'nothing sent to the server');

        unset($this->whmFailures['createacct']);
        Livewire::test(ListModuleQueue::class)
            ->callAction('retryAll')
            ->assertNotified(__(':fixed done, :failed still failing', ['fixed' => 1, 'failed' => 0]));

        $this->assertSame(ServiceStatus::Active, $second->refresh()->status);
        $this->assertSame(0, ModuleQueueItem::query()->open()->count());
    }

    public function test_open_items_are_in_the_daily_summary(): void
    {
        $this->failSetUp($this->pendingHosting());

        $summary = DailySummary::between(now()->subDay()->toImmutable(), now()->toImmutable());

        $this->assertFalse($summary->isEmpty());
        $this->assertCount(1, $summary->moduleQueue);
        $this->assertStringContainsString('acme.gr', (new DailySummaryMail($summary))->render());
    }

    public function test_an_empty_queue_says_all_is_well(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListModuleQueue::class)
            ->assertSee(__('All good'))
            ->assertSee(__('No panel or registrar action is waiting to be retried.'));
    }
}
