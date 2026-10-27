<?php

namespace Plugins\Blacklists;

use App\Models\ActivityLog;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Looks up each active server's IP (servers.ip_address, else its hostname's addresses) in every DNS blacklist of
 * Settings → IP blacklist check: <reversed IP>.<zone> answering 127.0.0.x means listed. A new listing is a
 * blacklist_listings row and logs blacklist.listed (bell, super admins); one gone logs blacklist.delisted. A list that
 * refuses the question (Spamhaus's 127.255.255.x through a public resolver) or answers outside 127.0.0.0/8 tells
 * nothing: an existing listing stays as it is. Safe to re-run.
 */
class BlacklistCheck
{
    public const LAST_RUN = 'blacklists.last_run';

    public function __construct(private DnsResolver $dns) {}

    /** @return list<string> the zones to ask, one per line of the setting */
    public static function zones(): array
    {
        return collect(preg_split('/[\s,]+/', (string) config('gaiacp.blacklists.zones')))
            ->map(fn (string $zone) => strtolower(trim($zone, " .\t")))
            ->filter(fn (string $zone) => preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $zone) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{servers: int, ips: int, listed: int, refused: list<string>} */
    public function run(): array
    {
        $zones = self::zones();
        $now = CarbonImmutable::now();
        $summary = ['servers' => 0, 'ips' => 0, 'listed' => 0, 'refused' => []];

        foreach (Server::query()->where('active', true)->orderBy('name')->get() as $server) {
            $ips = $this->ips($server);
            $summary['servers']++;
            $summary['ips'] += count($ips);

            foreach ($ips as $ip) {
                foreach ($zones as $zone) {
                    $answer = $this->ask($ip, $zone);

                    if ($answer === null) {
                        $summary['refused'][] = $zone;

                        continue;
                    }

                    $this->keep($server, $ip, $zone, $answer, $now);
                    $summary['listed'] += $answer === false ? 0 : 1;
                }
            }

            // IPs or zones no longer checked for this server: nothing listed there any more.
            BlacklistListing::query()->where('server_id', $server->id)
                ->where(fn ($q) => $q->whereNotIn('ip', $ips ?: [''])->orWhereNotIn('zone', $zones ?: ['']))
                ->get()->each(fn (BlacklistListing $l) => $this->delisted($l));
        }

        BlacklistListing::query()->whereDoesntHave('server', fn ($q) => $q->where('active', true))->delete();
        $summary['refused'] = array_values(array_unique($summary['refused']));
        Cache::forever(self::LAST_RUN, ['at' => $now->toIso8601String(), 'refused' => $summary['refused']]);

        return $summary;
    }

    /** @return list<string> */
    public function ips(Server $server): array
    {
        $ips = collect(preg_split('/[\s,]+/', (string) $server->ip_address))->filter(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP));

        if ($ips->isEmpty() && filled($server->hostname)) {
            $ips = collect($this->dns->addresses((string) $server->hostname));
        }

        // Private and reserved addresses are on no public list.
        return $ips->filter(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
            ->unique()->values()->all();
    }

    /** The answer when listed ("127.0.0.2"), false when not, null when the list did not say. */
    public function ask(string $ip, string $zone): string|false|null
    {
        $answers = $this->dns->a(self::reverse($ip).'.'.$zone);

        if ($answers === []) {
            return false;
        }

        $listed = array_filter($answers, fn (string $a) => str_starts_with($a, '127.') && ! str_starts_with($a, '127.255.255.'));

        return $listed === [] ? null : implode(', ', $listed);
    }

    /** 1.2.3.4 → 4.3.2.1; IPv6 → its 32 nibbles backwards. */
    public static function reverse(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode('.', array_reverse(str_split(bin2hex((string) inet_pton($ip)))));
        }

        return implode('.', array_reverse(explode('.', $ip)));
    }

    private function keep(Server $server, string $ip, string $zone, string|false $answer, CarbonImmutable $now): void
    {
        $listing = BlacklistListing::query()->where(['server_id' => $server->id, 'ip' => $ip, 'zone' => $zone])->first();

        if ($answer === false) {
            if ($listing) {
                $this->delisted($listing);
            }

            return;
        }

        if ($listing) {
            $listing->update(['answer' => $answer, 'checked_at' => $now]);

            return;
        }

        BlacklistListing::query()->create(['server_id' => $server->id, 'ip' => $ip, 'zone' => $zone, 'answer' => $answer, 'listed_at' => $now, 'checked_at' => $now]);
        ActivityLog::record('blacklist.listed', "{$server->name} ({$ip}) is listed on {$zone} ({$answer})", properties: ['server_id' => $server->id, 'ip' => $ip, 'zone' => $zone, 'answer' => $answer]);
    }

    private function delisted(BlacklistListing $listing): void
    {
        $listing->delete();
        $name = $listing->server?->name ?? '#'.$listing->server_id;
        ActivityLog::record('blacklist.delisted', "{$name} ({$listing->ip}) is no longer listed on {$listing->zone}", properties: ['server_id' => $listing->server_id, 'ip' => $listing->ip, 'zone' => $listing->zone]);
    }

    /** @return Collection<int, BlacklistListing> */
    public static function listings(): Collection
    {
        return BlacklistListing::query()->with('server')->orderBy('listed_at')->get();
    }
}
