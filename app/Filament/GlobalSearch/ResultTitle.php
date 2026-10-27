<?php

namespace App\Filament\GlobalSearch;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/** A global search result title with a status badge on the right, like WHMCS. */
class ResultTitle
{
    public static function withStatus(string $title, (HasColor&HasLabel)|null $status): Htmlable
    {
        return new HtmlString(Blade::render(<<<'BLADE'
            <span style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;">
                <span>{{ $title }}</span>
                @if ($status)
                    <x-filament::badge :color="$status->getColor()" size="sm">{{ $status->getLabel() }}</x-filament::badge>
                @endif
            </span>
            BLADE, ['title' => $title, 'status' => $status]));
    }
}
