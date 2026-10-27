<?php

namespace App\Provisioning;

use Carbon\CarbonImmutable;

/**
 * A hosting account's usage as the panel reports it. Disk space and this month's bandwidth in bytes; $counts holds the
 * rest by key (COUNTS): [used, limit], numbers of things except database_disk (bytes). A null limit is unlimited; a
 * null used value is one the panel did not report, and a key the panel does not know is left out. $readAt is when it
 * was read (set by ServiceLifecycle).
 */
final readonly class AccountUsage
{
    /** What $counts can hold, in the order shown. */
    public const COUNTS = ['email_accounts', 'databases', 'database_disk', 'sites', 'addon_domains', 'parked_domains', 'subdomains'];

    /** @param  array<string, array{0: ?int, 1: ?int}>  $counts */
    public function __construct(
        public ?int $diskUsed,
        public ?int $diskLimit,
        public ?int $bandwidthUsed,
        public ?int $bandwidthLimit,
        public array $counts = [],
        public ?CarbonImmutable $readAt = null,
    ) {}

    public function readAt(CarbonImmutable $at): self
    {
        return new self($this->diskUsed, $this->diskLimit, $this->bandwidthUsed, $this->bandwidthLimit, $this->counts, $at);
    }

    /** For the cache, which unserializes no objects. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'disk_used' => $this->diskUsed,
            'disk_limit' => $this->diskLimit,
            'bandwidth_used' => $this->bandwidthUsed,
            'bandwidth_limit' => $this->bandwidthLimit,
            'counts' => $this->counts,
            'read_at' => $this->readAt?->getTimestamp(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['disk_used'] ?? null,
            $data['disk_limit'] ?? null,
            $data['bandwidth_used'] ?? null,
            $data['bandwidth_limit'] ?? null,
            $data['counts'] ?? [],
            isset($data['read_at']) ? CarbonImmutable::createFromTimestamp($data['read_at'], config('app.timezone')) : null,
        );
    }

    /** Share of the limit used, 0-100 (above 100 when over it), or null without a limit. */
    public static function percent(?int $used, ?int $limit): ?int
    {
        return $used === null || $limit === null || $limit <= 0 ? null : (int) round($used / $limit * 100);
    }
}
