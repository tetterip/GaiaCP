<?php

namespace App\Provisioning;

use Carbon\CarbonImmutable;

/**
 * A VPS as its panel reports it (DescribesMachine): whether it runs, its processors, memory and disk (bytes; null when
 * not reported), the addresses it can be reached on, and whether a guest agent answers inside it (a new password then
 * reaches the machine at once). $readAt is when it was read (set by ServiceLifecycle).
 */
final readonly class MachineDetails
{
    /** @param  list<string>  $addresses  public addresses first, without loopback or link-local ones */
    public function __construct(
        public bool $running,
        public ?int $cores,
        public ?int $memory,
        public ?int $disk,
        public array $addresses = [],
        public bool $agent = false,
        public ?CarbonImmutable $readAt = null,
    ) {}

    public function readAt(CarbonImmutable $at): self
    {
        return new self($this->running, $this->cores, $this->memory, $this->disk, $this->addresses, $this->agent, $at);
    }

    /** For the cache, which unserializes no objects. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'running' => $this->running,
            'cores' => $this->cores,
            'memory' => $this->memory,
            'disk' => $this->disk,
            'addresses' => $this->addresses,
            'agent' => $this->agent,
            'read_at' => $this->readAt?->getTimestamp(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['running'] ?? false),
            $data['cores'] ?? null,
            $data['memory'] ?? null,
            $data['disk'] ?? null,
            array_values((array) ($data['addresses'] ?? [])),
            (bool) ($data['agent'] ?? false),
            isset($data['read_at']) ? CarbonImmutable::createFromTimestamp($data['read_at'], config('app.timezone')) : null,
        );
    }
}
