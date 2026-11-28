<?php

namespace App\Monitoring;

use App\Models\Server;
use App\Provisioning\ServerLoad;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The CPU bar: a server's CPU readings (server_load_readings, every 5 minutes) over the last 24 hours, a square per
 * hour with its average and highest share, oldest first, and the latest reading as "now" (null when the last one is
 * older than STALE_MINUTES, so a panel that stopped answering shows "—"). A reading kept without a share (a load
 * average read before the server's processor cores were entered) gets one from the cores the server has now.
 */
class LoadHistory
{
    public const HOURS = 24;

    public const STALE_MINUTES = 15;

    /** @return array{now: ?float, load: ?float, cores: ?int, hours: list<array{label: string, average: ?float, highest: ?float}>} */
    public static function for(Server $server): array
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $first = $now->startOfHour()->subHours(self::HOURS - 1);

        $readings = DB::table('server_load_readings')
            ->where('server_id', $server->id)
            ->where('checked_at', '>=', $first->utc())
            ->orderBy('checked_at')
            ->get(['percent', 'load', 'checked_at']);

        $tally = array_fill(0, self::HOURS, []);
        $latest = null;
        foreach ($readings as $reading) {
            $at = CarbonImmutable::parse($reading->checked_at, 'UTC')->setTimezone(config('app.timezone'));
            $load = $reading->load === null ? null : (float) $reading->load;
            $percent = $reading->percent === null ? (new ServerLoad($load))->percent($server->cpu_cores) : (float) $reading->percent;
            $latest = ['at' => $at, 'percent' => $percent, 'load' => $load];

            $index = (int) floor($first->diffInHours($at, false));
            if ($index >= 0 && $index < self::HOURS && $percent !== null) {
                $tally[$index][] = $percent;
            }
        }

        $hours = [];
        foreach ($tally as $i => $values) {
            $from = $first->addHours($i);
            $hours[] = [
                'label' => $from->format('d/m H:i').'–'.$from->addHour()->format('H:i'),
                'average' => $values === [] ? null : round(array_sum($values) / count($values), 1),
                'highest' => $values === [] ? null : max($values),
            ];
        }

        $fresh = $latest !== null && $latest['at'] >= $now->subMinutes(self::STALE_MINUTES);

        return [
            'now' => $fresh ? $latest['percent'] : null,
            'load' => $fresh ? $latest['load'] : null,
            'cores' => $server->cpu_cores ?: null,
            'hours' => $hours,
        ];
    }

    /** "35%" (no decimals: a load is never that exact). */
    public static function percent(?float $percent): ?string
    {
        return $percent === null ? null : ((int) round($percent)).'%';
    }

    /** The colour of a share: green, amber from WARM, red from HOT (or the CPU warning setting, when lower). */
    public static function color(?float $percent): ?string
    {
        if ($percent === null) {
            return null;
        }

        $hot = (int) (config('gaiacp.automation.cpu_warning_percent') ?: 90);

        return match (true) {
            $percent >= $hot => 'var(--danger-500)',
            $percent >= min(70, $hot) => 'var(--warning-500)',
            default => 'var(--success-500)',
        };
    }
}
