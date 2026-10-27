<?php

namespace Tests\Feature;

use App\Filament\Pages\FailedJobs;
use App\Models\FailedJob;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Failed jobs: Laravel's failed_jobs, with Retry and Delete (App\Filament\Pages\FailedJobs). */
class FailedJobsTest extends TestCase
{
    use RefreshDatabase;

    private function failedJob(string $name = 'App\\Mail\\ClientMail'): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => $name, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'maxTries' => null, 'timeout' => null, 'data' => ['commandName' => FailedJobsTestJob::class, 'command' => serialize(new FailedJobsTestJob)]]),
            'exception' => "RuntimeException: The mail server said no in /app/x.php:12\nStack trace:\n#0 {main}",
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    public function test_super_admins_see_failed_jobs_and_retry_or_delete_them(): void
    {
        $this->actingAs(User::factory()->create());
        $retry = $this->failedJob();
        $delete = $this->failedJob('App\\Jobs\\Other');

        $this->get(FailedJobs::getUrl())->assertOk()
            ->assertSee('ClientMail')
            ->assertSee('RuntimeException: The mail server said no in /app/x.php:12')
            ->assertDontSee('Stack trace');

        Livewire::test(FailedJobs::class)->callAction(TestAction::make('retry')->table(FailedJob::query()->where('uuid', $retry)->sole()));
        Livewire::test(FailedJobs::class)->callAction(TestAction::make('delete')->table(FailedJob::query()->where('uuid', $delete)->sole()));

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count()); // back on its queue
    }

    public function test_delete_all_empties_the_list(): void
    {
        $this->actingAs(User::factory()->create());
        $this->failedJob();
        $this->failedJob();

        Livewire::test(FailedJobs::class)->callAction('deleteAll');

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }
}

class FailedJobsTestJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}
