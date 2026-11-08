<?php

namespace App\Filament\Resources\Quotes\Tables;

use App\Actions\Quotes;
use App\Enums\QuoteStatus;
use App\Filament\Actions\BulkOutcome;
use App\Models\Quote;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/** Mass actions on the quotes picked in a list, each as the quote page's own action. */
class QuoteBulkActions
{
    public const MAX = 100;

    public static function group(): BulkActionGroup
    {
        return BulkActionGroup::make([self::send(), self::decline(), self::delete()]);
    }

    /** As Send (or Send again) on each draft or sent one; the ones Send refuses are named with why. */
    public static function send(): BulkAction
    {
        $skip = fn (Quote $quote) => $quote->status->isOpen() ? null : __('accepted, declined or expired');

        return BulkAction::make('send')
            ->label('Send')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->requiresConfirmation()
            ->modalHeading(__('Send quotes'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '
                .__('Each one is emailed to its lead or client with the link to its page; a sent one is sent again.'))
            ->modalSubmitActionLabel(__('Send'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records,
                $skip,
                fn (Quote $quote) => app(Quotes::class)->send($quote),
                fn (Quote $quote) => $quote->number,
            )->notify(':count quote sent|:count quotes sent'));
    }

    public static function decline(): BulkAction
    {
        $skip = fn (Quote $quote) => $quote->status === QuoteStatus::Sent ? null : __('not sent');

        return BulkAction::make('decline')
            ->label('Mark as declined')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->modalHeading(__('Mark as declined'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip))
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->rows(2)
                    ->maxLength(255),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data) => BulkOutcome::run(
                $records,
                $skip,
                fn (Quote $quote) => app(Quotes::class)->decline($quote, $data['reason'] ?? null),
                fn (Quote $quote) => $quote->number,
            )->notify(':count quote marked as declined|:count quotes marked as declined'));
    }

    /** As the quote page's Delete: never an accepted one (it became an order). */
    public static function delete(): BulkAction
    {
        $skip = fn (Quote $quote) => $quote->status === QuoteStatus::Accepted ? __('accepted') : null;

        return BulkAction::make('delete')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Delete quotes'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '.__('Their links stop working. This cannot be undone.'))
            ->modalSubmitActionLabel(__('Delete them'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records,
                $skip,
                fn (Quote $quote) => $quote->delete(),
                fn (Quote $quote) => $quote->number,
            )->notify(':count quote deleted|:count quotes deleted'));
    }
}
