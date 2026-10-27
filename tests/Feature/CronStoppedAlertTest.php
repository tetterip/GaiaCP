<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Filament\Widgets\NeedsAttention;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Health\SchedulerMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CronStoppedAlertTest extends TestCase
{
    use RefreshDatabase;

    private function monitor(): SchedulerMonitor
    {
        return app(SchedulerMonitor::class);
    }

    public function test_a_running_cron_says_nothing(): void
    {
        $admin = User::factory()->create();
        $this->monitor()->heartbeat();
        $this->travel(30)->minutes();

        $this->actingAs($admin)->get('/admin')->assertOk();

        $this->assertNull($this->monitor()->stopped());
        $this->assertSame(0, $admin->notifications()->count());
        $this->assertNotContains('heroicon-o-clock', array_column(NeedsAttention::items(), 'icon'));
    }

    public function test_a_stopped_cron_rings_the_bell_once_and_shows_on_the_dashboard(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create(['locale' => 'el']);
        $billing = User::factory()->limitedTo([AdminArea::Billing])->create();
        $this->monitor()->heartbeat();
        $this->travel(2)->hours();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/admin')->assertOk();

        $this->assertSame(1, ActivityLog::query()->where('event', 'scheduler.stopped')->count());
        // The admin whose page load noticed it is told too
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertStringStartsWith('Cron has not run since ', $admin->notifications()->first()->data['title']);
        $this->assertStringEndsWith('/admin/settings/system-health', $admin->notifications()->first()->data['actions'][0]['url']);
        $this->assertSame(1, $other->notifications()->count());
        $this->assertSame(0, $billing->notifications()->count());

        $this->assertSame('Cron last ran 2 hours ago', NeedsAttention::items()[0]['title']);
        $this->actingAs($billing);
        $this->assertNotContains('heroicon-o-clock', array_column(NeedsAttention::items(), 'icon'));

        // Running again: one "running again", and the next stop rings again
        $this->monitor()->heartbeat();
        $this->assertContains('Cron is running again', $admin->notifications()->get()->pluck('data.title'));
        $this->assertNull($this->monitor()->stopped());

        $this->travel(2)->hours();
        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->assertSame(2, ActivityLog::query()->where('event', 'scheduler.stopped')->count());
    }

    public function test_an_empty_cache_waits_before_warning(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->assertNull($this->monitor()->stopped());

        $this->travel(61)->minutes();
        $this->actingAs($admin)->get('/admin')->assertOk();

        $this->assertSame('Cron has not run yet', $admin->notifications()->first()->data['title']);
        $this->assertSame('Cron has not run yet', NeedsAttention::items()[0]['title']);
    }
}
