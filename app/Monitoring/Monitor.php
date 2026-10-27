<?php

namespace App\Monitoring;

/** One monitor of a monitoring service, as last reported. */
final readonly class Monitor
{
    /**
     * @param  string  $ref  how the service names it, stored in servers.monitor_ref
     * @param  ?string  $host  the hostname or URL host it checks, to suggest it for a server with that hostname
     * @param  array<string, float>  $uptime  the service's own uptime ratio (0-1) by window: '1d', '30d', '365d'
     */
    public function __construct(
        public string $ref,
        public string $name,
        public MonitorStatus $status,
        public ?string $host = null,
        public ?int $responseMs = null,
        public array $uptime = [],
    ) {}

    /** @return array{ref: string, name: string, status: string, host: ?string, responseMs: ?int, uptime: array<string, float>} plain values for the cache, which keeps no objects */
    public function toArray(): array
    {
        return ['ref' => $this->ref, 'name' => $this->name, 'status' => $this->status->value, 'host' => $this->host, 'responseMs' => $this->responseMs, 'uptime' => $this->uptime];
    }

    /** @param  array{ref: string, name: string, status: string, host: ?string, responseMs: ?int, uptime?: array<string, float>}  $values */
    public static function fromArray(array $values): self
    {
        return new self((string) $values['ref'], $values['name'], MonitorStatus::tryFrom($values['status']) ?? MonitorStatus::Unknown, $values['host'], $values['responseMs'], $values['uptime'] ?? []);
    }
}
