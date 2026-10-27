<?php

namespace App\Monitoring;

use App\Models\Server;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The uptime bar: a server's statuses (server_monitor_checks, one per check) grouped into dots, oldest first. Each dot
 * takes its worst status (down, then pending / maintenance, then up) and the share of checks that were not down;
 * a dot with no checks is null. The percentage next to the bar is the monitoring service's own ratio for that
 * window when it gives one (Kuma 2.x), else the share over the bar.
 */
class UptimeHistory
{
    /** @var array<string, array{dots: int, window: string}> range => dots and the service's matching ratio window */
    public const RANGES = [
        '24h' => ['dots' => 24, 'window' => '1d'],
        '30d' => ['dots' => 30, 'window' => '30d'],
    ];

    /** Checks are kept this long (gaiacp:prune-logs), enough for the longest range. */
    public const KEEP_DAYS = 31;

    private const WORST = ['unknown' => 0, 'up' => 1, 'maintenance' => 2, 'pending' => 3, 'down' => 4];

    /** @return array{dots: list<array{label: string, status: ?MonitorStatus, uptime: ?float}>, uptime: ?float} */
    public static function for(Server $server, string $range = '24h'): array
    {
        $range = isset(self::RANGES[$range]) ? $range : '24h';
        $slots = self::slots($range);

        $checks = DB::table('server_monitor_checks')
            ->where('server_id', $server->id)
            ->where('checked_at', '>=', $slots[0]['from']->utc())
            ->orderBy('checked_at')
            ->get(['status', 'checked_at']);

        $tally = array_fill(0, count($slots), ['worst' => null, 'up' => 0, 'down' => 0]);
        foreach ($checks as $check) {
            $at = CarbonImmutable::parse($check->checked_at, 'UTC')->setTimezone(config('app.timezone'));
            $index = self::index($slots, $at);
            if ($index === null) {
                continue;
            }

            $worst = $tally[$index]['worst'];
            if ($worst === null || (self::WORST[$check->status] ?? 0) > (self::WORST[$worst] ?? 0)) {
                $tally[$index]['worst'] = $check->status;
            }
            if ($check->status === MonitorStatus::Down->value) {
                $tally[$index]['down']++;
            } elseif ($check->status !== MonitorStatus::Unknown->value) {
                $tally[$index]['up']++;
            }
        }

        $dots = [];
        foreach ($slots as $i => $slot) {
            $counted = $tally[$i]['up'] + $tally[$i]['down'];
            $dots[] = [
                'label' => $slot['label'],
                'status' => $tally[$i]['worst'] ? MonitorStatus::from($tally[$i]['worst']) : null,
                'uptime' => $counted ? $tally[$i]['up'] / $counted : null,
            ];
        }

        $up = array_sum(array_column($tally, 'up'));
        $counted = $up + array_sum(array_column($tally, 'down'));
        $service = $server->monitor_uptime[self::RANGES[$range]['window']] ?? null;

        return ['dots' => $dots, 'uptime' => $service !== null ? (float) $service : ($counted ? $up / $counted : null)];
    }

    /** "99.98%" (two decimals below 100%). */
    public static function percent(?float $ratio): ?string
    {
        if ($ratio === null) {
            return null;
        }

        // Rounded down, so 99.999% never reads as 100%.
        return $ratio >= 1 ? '100%' : rtrim(rtrim(number_format(floor($ratio * 10000) / 100, 2, '.', ''), '0'), '.').'%';
    }

    /** @return list<array{from: CarbonImmutable, to: CarbonImmutable, label: string}> */
    private static function slots(string $range): array
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $slots = [];

        if ($range === '30d') {
            for ($day = $now->startOfDay()->subDays(self::RANGES['30d']['dots'] - 1); $day <= $now; $day = $day->addDay()) {
                $slots[] = ['from' => $day, 'to' => $day->addDay(), 'label' => $day->format(AppServiceProvider::dateFormat())];
            }

            return $slots;
        }

        // Hours ending with the current one.
        for ($i = self::RANGES['24h']['dots'] - 1; $i >= 0; $i--) {
            $from = $now->startOfHour()->subHours($i);
            $slots[] = ['from' => $from, 'to' => $from->addHour(), 'label' => $from->format('d/m H:i').'–'.$from->addHour()->format('H:i')];
        }

        return $slots;
    }

    private static function index(array $slots, CarbonImmutable $at): ?int
    {
        foreach ($slots as $i => $slot) {
            if ($at >= $slot['from'] && $at < $slot['to']) {
                return $i;
            }
        }

        return null;
    }
}
