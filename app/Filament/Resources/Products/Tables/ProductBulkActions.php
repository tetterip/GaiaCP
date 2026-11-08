<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Actions\SimpleBulkAction;
use App\Models\Product;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Support\Icons\Heroicon;

/** Hide / Show and Retire / Bring back on the products picked in a list, as the product form's switches. */
class ProductBulkActions
{
    public const MAX = 100;

    public static function group(): BulkActionGroup
    {
        return BulkActionGroup::make([
            self::flag('hide', 'Hide', Heroicon::OutlinedEyeSlash, 'hidden', true, __('already hidden'), ':count product hidden|:count products hidden',
                __('Not offered in New order; still offered on a single new service.')),
            self::flag('show', 'Unhide', Heroicon::OutlinedEye, 'hidden', false, __('not hidden'), ':count product shown|:count products shown'),
            self::flag('retire', 'Retire', Heroicon::OutlinedArchiveBox, 'retired', true, __('already retired'), ':count product retired|:count products retired',
                __('Not offered anywhere any more. Services that already have it keep it.')),
            self::flag('unretire', 'Bring back', Heroicon::OutlinedArrowUturnLeft, 'retired', false, __('not retired'), ':count product brought back|:count products brought back'),
        ]);
    }

    private static function flag(string $name, string $label, Heroicon $icon, string $column, bool $value, string $already, string $done, ?string $text = null): BulkAction
    {
        return SimpleBulkAction::make(
            $name, $label, $icon,
            fn (Product $product) => (bool) $product->{$column} === $value ? $already : null,
            fn (Product $product) => $product->update([$column => $value]),
            fn (Product $product) => $product->name,
            $done,
            $text,
        );
    }
}
