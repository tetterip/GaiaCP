<?php

namespace Tests\Feature;

use App\Actions\Tickets;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Filament\Clusters\Settings\Pages\LoggingSettings;
use App\Filament\Clusters\Settings\Pages\SupportSettings;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\ModuleLog;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LogCleanup;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Logging → Clean up: old log rows, files of closed tickets and the cache, by hand; ticket files every night. */
class LogCleanupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->admin = User::factory()->create(); // the first admin is a super admin
    }

    private function closedTicket(int $closedDaysAgo): Ticket
    {
        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
        $ticket = app(Tickets::class)->open($client, $user, 'Email not working', 'See the log.',
            files: [UploadedFile::fake()->create('error.log', 3)->store('tickets', 'local') => 'error.log']);
        $ticket->forceFill(['status' => TicketStatus::Closed, 'closed_at' => now()->subDays($closedDaysAgo)])->save();

        return $ticket;
    }

    public function test_a_super_admin_deletes_log_entries_older_than_a_day(): void
    {
        $this->actingAs($this->admin);
        ModuleLog::create(['module' => 'cpanel', 'action' => 'old', 'url' => 'x', 'created_at' => now()->subDays(20)]);
        ModuleLog::create(['module' => 'cpanel', 'action' => 'new', 'url' => 'x', 'created_at' => now()->subDays(2)]);
        EmailLog::create(['to' => 'a@example.com', 'status' => 'sent', 'created_at' => now()->subDays(20)]);

        Livewire::test(LoggingSettings::class)
            ->assertSee('Clean up')
            ->callAction(TestAction::make('clean_module')->schemaComponent('cleanupLogs', 'form'), ['before' => now()->subDays(10)->toDateString()])
            ->assertHasNoActionErrors();

        $this->assertSame(['new'], ModuleLog::query()->pluck('action')->all());
        $this->assertSame(1, EmailLog::count(), 'other logs untouched');
        $entry = ActivityLog::query()->where('event', 'log.cleaned')->sole();
        $this->assertSame(['log' => 'module', 'deleted' => 1], array_intersect_key($entry->properties, ['log' => 0, 'deleted' => 0]));
        $this->assertSame($this->admin->id, $entry->user_id);
    }

    public function test_the_activity_log_keeps_its_last_thirty_days_and_the_record_of_the_clean_up(): void
    {
        $this->actingAs($this->admin);
        $activity = fn (int $daysAgo) => ActivityLog::query()->forceCreate(['event' => 'test', 'description' => 'x', 'created_at' => now()->subDays($daysAgo)]);
        $activity(100);
        $activity(10);

        $this->expectException(InvalidArgumentException::class);
        try {
            app(LogCleanup::class)->deleteBefore('activity', now()->subDays(5));
        } finally {
            $this->assertSame(2, ActivityLog::query()->where('event', 'test')->count());
            $this->assertSame(1, app(LogCleanup::class)->deleteBefore('activity', now()->subDays(LogCleanup::ACTIVITY_MIN_DAYS)));
            $this->assertSame(1, ActivityLog::query()->where('event', 'test')->count());
            $this->assertTrue(ActivityLog::query()->where('event', 'log.cleaned')->exists());
        }
    }

    public function test_the_clean_up_is_only_for_super_admins(): void
    {
        $other = User::factory()->create();
        $this->assertFalse($other->super_admin);
        $this->actingAs($other);

        Livewire::test(LoggingSettings::class)->assertDontSee('Delete files of closed tickets');
    }

    public function test_files_of_closed_tickets_are_deleted_and_the_message_names_them(): void
    {
        $old = $this->closedTicket(100);
        $recent = $this->closedTicket(5);
        $open = $this->closedTicket(100);
        $open->forceFill(['status' => TicketStatus::Open])->save();
        $file = $old->messages()->sole()->attachments()->sole();

        $this->actingAs($this->admin);
        Livewire::test(LoggingSettings::class)
            ->callAction(TestAction::make('deleteTicketFiles')->schemaComponent('cleanupOther', 'form'), ['before' => now()->subDays(30)->toDateString()])
            ->assertHasNoActionErrors();

        $this->assertTrue($file->fresh()->removed());
        Storage::disk('local')->assertMissing($file->path);
        foreach ([$recent, $open] as $kept) {
            $keptFile = $kept->messages()->sole()->attachments()->sole();
            $this->assertFalse($keptFile->removed());
            Storage::disk('local')->assertExists($keptFile->path);
        }
        $this->assertSame(1, ActivityLog::query()->where('event', 'ticket.files_deleted')->sole()->properties['files']);

        Livewire::test(ViewTicket::class, ['record' => $old->getRouteKey()])
            ->assertSee('error.log')
            ->assertSee('file deleted');
        $this->get(route('filament.admin.ticket-file', ['attachment' => $file->id]))->assertNotFound();
    }

    public function test_the_nightly_run_deletes_ticket_files_only_when_set(): void
    {
        $file = $this->closedTicket(40)->messages()->sole()->attachments()->sole();

        $this->artisan('gaiacp:prune-logs')->assertSuccessful();
        $this->assertFalse($file->fresh()->removed(), 'empty keeps them');

        $this->actingAs($this->admin);
        Livewire::test(SupportSettings::class)
            ->set('data.support.attachment_days', 30)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(30, config('gaiacp.support.attachment_days'));

        $this->artisan('gaiacp:prune-logs')->assertSuccessful();
        $this->assertTrue($file->fresh()->removed());
        Storage::disk('local')->assertMissing($file->path);

        $this->artisan('gaiacp:prune-logs')->assertSuccessful();
        $this->assertSame(1, ActivityLog::query()->where('event', 'ticket.files_deleted')->count(), 'safe to re-run');
    }

    public function test_the_cache_is_emptied(): void
    {
        Cache::put('something', 'x', 600);
        $this->actingAs($this->admin);

        Livewire::test(LoggingSettings::class)
            ->callAction(TestAction::make('clearCache')->schemaComponent('cleanupOther', 'form'))
            ->assertHasNoActionErrors();

        $this->assertNull(Cache::get('something'));
        $this->assertTrue(ActivityLog::query()->where('event', 'cache.cleared')->exists());
    }
}
