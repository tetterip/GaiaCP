<?php

namespace App\Provisioning;

/**
 * How busy a server's processors are, as its panel reports it (ReportsLoad): the load average of the last minute, the
 * number of processor cores when the panel gives it, and the share of CPU time in use (0-100) when the panel measures
 * that itself (Proxmox, Virtualmin). Hosting panels mostly give only the load average: percent() turns it into a share
 * of the cores, the usual reading (a load of 2 on 4 cores is 50%).
 */
final readonly class ServerLoad
{
    public function __construct(
        public ?float $load,
        public ?int $cores = null,
        public ?float $percent = null,
    ) {}

    /**
     * The share in use, 0-100, or null when it can't be told (a load average without the number of cores). $cores is
     * the server's own setting, used when the panel gives none.
     */
    public function percent(?int $cores = null): ?float
    {
        if ($this->percent !== null) {
            return round(max(0.0, min(100.0, $this->percent)), 1);
        }

        $cores = $this->cores ?: $cores;
        if ($this->load === null || ! $cores) {
            return null;
        }

        // A load above the number of cores means processes waiting: shown as full.
        return round(max(0.0, min(100.0, $this->load / $cores * 100)), 1);
    }

    /** "0.15 / 0.10 / 0.05" style load text, or a number: the first number in it. */
    public static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && preg_match('/\d+(?:[.,]\d+)?/', $value, $m) ? (float) str_replace(',', '.', $m[0]) : null;
    }
}
