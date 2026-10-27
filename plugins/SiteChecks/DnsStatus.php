<?php

namespace Plugins\SiteChecks;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Where a site's domain points, against its server's addresses. */
enum DnsStatus: string implements HasColor, HasLabel
{
    case Ours = 'ours';
    // Proxied: Cloudflare's addresses, so where it ends up can't be seen
    case Cloudflare = 'cloudflare';
    case Elsewhere = 'elsewhere';
    case None = 'none';
    // The server has no address to compare with
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ours => __('Points to its server'),
            self::Cloudflare => __('Behind Cloudflare'),
            self::Elsewhere => __('Points elsewhere'),
            self::None => __('No DNS records'),
            self::Unknown => __('Server address unknown'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ours => 'success',
            self::Cloudflare, self::Unknown => 'gray',
            self::Elsewhere, self::None => 'danger',
        };
    }

    public function isProblem(): bool
    {
        return in_array($this, [self::Elsewhere, self::None], true);
    }
}
