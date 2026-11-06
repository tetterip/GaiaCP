<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Concerns\StoresTimesInUtc;
use App\Models\Invoice;
use App\Models\IpBan;
use App\Models\Service;
use App\Support\Region;
use App\Support\StoredTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use LogicException;
use Tests\TestCase;

/** Times are stored in UTC and shown in the Region's timezone; plain dates stay days of the calendar. */
class TimesInUtcTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gaiacp.region.timezone' => 'Europe/Athens']);
        Region::applyTimezone();
    }

    public function test_a_models_times_are_stored_in_utc_and_read_in_local_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-01 14:30:00', 'Europe/Athens')); // summer time, UTC+3

        $log = ActivityLog::record('test.done', 'Done');
        $this->assertSame('2026-07-01 11:30:00', DB::table('activity_logs')->where('id', $log->id)->value('created_at'));

        $fresh = ActivityLog::query()->find($log->id);
        $this->assertSame('2026-07-01 14:30', $fresh->created_at->format('Y-m-d H:i'));
        $this->assertSame('Europe/Athens', $fresh->created_at->getTimezone()->getName());
        $this->assertSame('2026-07-01T11:30:00.000000Z', $fresh->toArray()['created_at'], 'the API gives UTC');

        // A time typed in a form is local time; a cast datetime too
        $ban = IpBan::query()->create(['ip' => '192.0.2.1', 'attempts' => 3, 'expires_at' => '2026-12-01 14:00']); // winter time, UTC+2
        $this->assertSame('2026-12-01 12:00:00', DB::table('ip_bans')->where('id', $ban->id)->value('expires_at'));
        $this->assertSame('2026-12-01 14:00', $ban->fresh()->expires_at->format('Y-m-d H:i'));
        $this->assertFalse($ban->fresh()->isDirty(), 'reading does not change it');
    }

    public function test_times_given_to_a_query_are_converted_and_dates_stay(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-01 01:30:00', 'Europe/Athens')); // still the 30th in UTC
        $log = ActivityLog::record('test.done', 'Done');

        $this->assertTrue(ActivityLog::query()->whereKey($log->id)->where('created_at', '>=', today())->exists());
        $this->assertFalse(ActivityLog::query()->whereKey($log->id)->where('created_at', '<', today())->exists());

        DB::table('activity_logs')->where('id', $log->id)->update(['created_at' => now()->subHour()]);
        $this->assertSame('2026-06-30 21:30:00', DB::table('activity_logs')->where('id', $log->id)->value('created_at'));
        $this->assertSame('2026-07-01 00:30', StoredTime::parse(DB::table('activity_logs')->where('id', $log->id)->value('created_at'))->format('Y-m-d H:i'));

        // A plain date is a day of the calendar: not moved, and found by whereOnDate()
        $service = Service::factory()->create(['next_due_date' => '2026-07-01']);
        $this->assertStringStartsWith('2026-07-01', (string) DB::table('services')->where('id', $service->id)->value('next_due_date'));
        $this->assertSame('2026-07-01', $service->fresh()->next_due_date->toDateString());
        $this->assertTrue(Service::query()->whereKey($service->id)->whereOnDate('next_due_date', today())->exists());
    }

    public function test_the_tests_refuse_a_carbon_for_a_date_column(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('services.next_due_date');

        Service::query()->where('next_due_date', '<', today())->exists();
    }

    public function test_the_migration_converts_the_times_already_stored_and_back(): void
    {
        // Written as GaiaCP did before: in local time, through a MySQL session in the server's own timezone
        $serverZone = fn () => in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && DB::statement('SET time_zone = @@global.time_zone');
        $utc = fn () => in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && DB::statement("SET time_zone = '+00:00'");
        $invoice = Invoice::query()->create(['client_id' => Client::factory()->create()->id, 'number' => 'PF-2026-0001', 'status' => 'unpaid', 'date' => '2026-07-01', 'due_date' => '2026-07-01', 'currency' => 'EUR', 'subtotal_cents' => 1000, 'tax_cents' => 0, 'total_cents' => 1000]);
        $serverZone();
        $log = DB::table('activity_logs')->insertGetId(['event' => 'test.done', 'description' => 'Done', 'created_at' => '2026-07-01 14:30:00']);
        DB::table('settings')->insert(['key' => 'test.key', 'value' => '1', 'created_at' => '2026-07-01 14:30:00', 'updated_at' => null]);
        DB::table('invoices')->where('id', $invoice->id)->update(['emailed_at' => '2026-03-29 04:30:00', 'updated_at' => '2026-12-01 14:30:00']); // just after the change to summer time; winter
        $due = DB::table('invoices')->where('id', $invoice->id)->value('due_date');
        $utc();
        $migration = require database_path('migrations/2026_11_06_000300_store_times_in_utc.php');

        $migration->up();

        $this->assertSame('2026-07-01 11:30:00', DB::table('activity_logs')->where('id', $log)->value('created_at'));
        $this->assertSame('2026-12-01 12:30:00', DB::table('invoices')->where('id', $invoice->id)->value('updated_at'));
        $this->assertSame('2026-07-01 11:30:00', DB::table('settings')->where('key', 'test.key')->value('created_at'));
        $this->assertNull(DB::table('settings')->where('key', 'test.key')->value('updated_at'));
        $this->assertSame('2026-03-29 01:30:00', DB::table('invoices')->where('id', $invoice->id)->value('emailed_at'));
        $this->assertSame($due, DB::table('invoices')->where('id', $invoice->id)->value('due_date'), 'dates stay');

        $migration->down();
        $serverZone();

        $this->assertSame('2026-07-01 14:30:00', DB::table('activity_logs')->where('id', $log)->value('created_at'));
        $this->assertSame('2026-12-01 14:30:00', DB::table('invoices')->where('id', $invoice->id)->value('updated_at'));
        $this->assertSame('2026-03-29 04:30:00', DB::table('invoices')->where('id', $invoice->id)->value('emailed_at'));
        $utc();
    }

    public function test_every_model_stores_its_times_in_utc(): void
    {
        $missing = collect([...File::allFiles(app_path()), ...File::allFiles(base_path('plugins'))])
            ->filter(fn ($file) => $file->getExtension() === 'php' && preg_match('/^(?:final |abstract )?class \w+ extends \w*(?:Model|Authenticatable|Pivot|DatabaseNotification)\b/m', $file->getContents()))
            ->map(function ($file) {
                preg_match('/^namespace ([^;]+);/m', $file->getContents(), $m);

                return $m[1].'\\'.$file->getFilenameWithoutExtension();
            })
            ->filter(fn (string $class) => class_exists($class) && is_subclass_of($class, Model::class) && ! in_array(StoresTimesInUtc::class, class_uses_recursive($class), true))
            ->values()->all();

        $this->assertSame([], $missing, 'Add `use StoresTimesInUtc;` to these models');
    }
}
