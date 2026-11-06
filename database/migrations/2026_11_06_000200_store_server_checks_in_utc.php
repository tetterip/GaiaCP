<?php

use App\Support\Region;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Server checks were written in local time but read back as UTC (UptimeHistory), so the uptime bars were off by the
 * timezone's offset. New checks are written in UTC; this converts the ones already kept, hour by hour of the summer
 * time change included. Nothing to do on an install in UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->convert(Region::timezone(), 'UTC');
    }

    public function down(): void
    {
        $this->convert('UTC', Region::timezone());
    }

    private function convert(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        DB::table('server_monitor_checks')->orderBy('id')->select(['id', 'checked_at'])
            ->chunkById(1000, function ($checks) use ($from, $to) {
                DB::transaction(function () use ($checks, $from, $to) {
                    foreach ($checks as $check) {
                        DB::table('server_monitor_checks')->where('id', $check->id)->update([
                            'checked_at' => CarbonImmutable::parse($check->checked_at, $from)->setTimezone($to)->format('Y-m-d H:i:s'),
                        ]);
                    }
                });
            });
    }
};
