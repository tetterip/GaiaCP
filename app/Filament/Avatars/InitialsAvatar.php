<?php

namespace App\Filament\Avatars;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The user's initials on a dark teal tile, drawn here as an SVG data URL, in place of Filament's default
 * (ui-avatars.com), so the panel loads no picture from another site.
 */
class InitialsAvatar implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $words = preg_split('/\s+/u', trim(Filament::getNameForDefaultAvatar($record))) ?: [];
        $initials = mb_strtoupper(collect($words)->filter()->take(2)->map(fn (string $word) => mb_substr($word, 0, 1))->join(''));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="16" fill="#16302C"/>'
            .'<text x="32" y="33" text-anchor="middle" dominant-baseline="central" fill="#FFFFFF" '
            .'font-family="system-ui, sans-serif" font-size="24" font-weight="700">'.e($initials).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
