<?php

namespace Plugins\SiteChecks;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** The certificate a site's domain answers with on port 443. */
enum SslStatus: string implements HasColor, HasLabel
{
    case Valid = 'valid';
    // Fewer days left than Settings → Site checks → Warn before expiry
    case Expiring = 'expiring';
    case Expired = 'expired';
    case WrongName = 'wrong_name';
    // Self-signed or an incomplete chain
    case Untrusted = 'untrusted';
    // Nothing answers HTTPS
    case None = 'none';
    // Not checked: the domain doesn't point to its server, so the answer would be another server's
    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Valid => __('Valid'),
            self::Expiring => __('Expires soon'),
            self::Expired => __('Expired'),
            self::WrongName => __('For another name'),
            self::Untrusted => __('Not trusted'),
            self::None => __('No HTTPS'),
            self::Skipped => __('Not checked'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Valid => 'success',
            self::Expiring => 'warning',
            self::Skipped => 'gray',
            default => 'danger',
        };
    }

    public function isProblem(): bool
    {
        return ! in_array($this, [self::Valid, self::Skipped], true);
    }
}
