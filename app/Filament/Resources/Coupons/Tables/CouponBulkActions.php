<?php

namespace App\Filament\Resources\Coupons\Tables;

use App\Filament\Actions\BulkOutcome;
use App\Models\Coupon;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/** Mass actions on the coupons picked in a list: turn them on or off, delete the ones no order used. */
class CouponBulkActions
{
    public const MAX = 100;

    public static function group(): BulkActionGroup
    {
        return BulkActionGroup::make([self::switch(true), self::switch(false), self::delete()]);
    }

    public static function switch(bool $active): BulkAction
    {
        $skip = fn (Coupon $coupon) => $coupon->active === $active ? ($active ? __('already active') : __('already inactive')) : null;

        return BulkAction::make($active ? 'activate' : 'deactivate')
            ->label($active ? __('Activate') : __('Deactivate'))
            ->icon($active ? Heroicon::OutlinedPlay : Heroicon::OutlinedPause)
            ->color($active ? 'success' : 'warning')
            ->requiresConfirmation()
            ->modalHeading($active ? __('Activate coupons') : __('Deactivate coupons'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).($active ? '' : ' '
                .__('Clients can no longer enter them; orders that used them keep their discount.')))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records,
                $skip,
                fn (Coupon $coupon) => $coupon->update(['active' => $active]),
                fn (Coupon $coupon) => $coupon->code,
            )->notify($active ? ':count coupon activated|:count coupons activated' : ':count coupon deactivated|:count coupons deactivated'));
    }

    /** As Delete on the list: never one an order used (orders keep which coupon they used). */
    public static function delete(): BulkAction
    {
        $skip = fn (Coupon $coupon) => $coupon->orders()->exists() ? __('used by an order: deactivate it instead') : null;

        return BulkAction::make('deleteMany')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Delete coupons'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '.__('This cannot be undone.'))
            ->modalSubmitActionLabel(__('Delete them'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records,
                $skip,
                fn (Coupon $coupon) => $coupon->delete(),
                fn (Coupon $coupon) => $coupon->code,
            )->notify(':count coupon deleted|:count coupons deleted'));
    }
}
