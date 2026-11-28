<?php

namespace App\Provisioning;

use Illuminate\Support\Number;

/**
 * A package's disk space and monthly bandwidth in bytes, as its panel reports them (DescribesPackages). Null is
 * unlimited; $known says whether the panel gave that limit at all (a value it did not report is left out of the label).
 */
final readonly class PackageLimits
{
    public function __construct(
        public ?int $disk,
        public ?int $bandwidth,
        public bool $diskKnown = true,
        public bool $bandwidthKnown = true,
    ) {}

    /** From megabytes, as most panels give them: a number, or "unlimited" / 0 / empty for no limit. */
    public static function fromMegabytes(mixed $disk, mixed $bandwidth): self
    {
        return new self(self::megabytes($disk), self::megabytes($bandwidth), $disk !== null, $bandwidth !== null);
    }

    /** "5 GB disk · 50 GB bandwidth", translated; null when the panel gave neither limit. */
    public function label(): ?string
    {
        $size = fn (?int $bytes) => $bytes === null ? __('unlimited') : Number::fileSize($bytes, maxPrecision: 1);
        $parts = array_filter([
            $this->diskKnown ? __(':size disk', ['size' => $size($this->disk)]) : null,
            $this->bandwidthKnown ? __(':size bandwidth', ['size' => $size($this->bandwidth)]) : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** For the cache, which unserializes no objects. @return array{disk: ?int, bandwidth: ?int, disk_known: bool, bandwidth_known: bool} */
    public function toArray(): array
    {
        return ['disk' => $this->disk, 'bandwidth' => $this->bandwidth, 'disk_known' => $this->diskKnown, 'bandwidth_known' => $this->bandwidthKnown];
    }

    /** @param  array{disk: ?int, bandwidth: ?int, disk_known?: bool, bandwidth_known?: bool}  $data */
    public static function fromArray(array $data): self
    {
        return new self($data['disk'], $data['bandwidth'], $data['disk_known'] ?? true, $data['bandwidth_known'] ?? true);
    }

    private static function megabytes(mixed $value): ?int
    {
        return is_numeric($value) && (float) $value > 0 ? (int) round((float) $value * 1024 * 1024) : null;
    }
}
