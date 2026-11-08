<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Actions\Leads;
use App\Enums\AdminArea;
use App\Enums\LeadStatus;
use App\Filament\Actions\BulkOutcome;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/** Mass actions on the leads picked in a list, each as the lead page's own action. */
class LeadBulkActions
{
    public const MAX = 100;

    public static function group(): BulkActionGroup
    {
        return BulkActionGroup::make([self::assign(), self::lost(), self::reopen(), self::delete()]);
    }

    /** Gives the open ones to one admin of Sales, or to nobody. */
    public static function assign(): BulkAction
    {
        $skip = fn (Lead $lead) => $lead->status->isOpen() ? null : __('won or lost');

        return BulkAction::make('assign')
            ->label('Assign to')
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading(__('Assign leads'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip))
            ->schema([
                Select::make('assigned_to')
                    ->label('Assigned to')
                    ->placeholder(__('Nobody'))
                    ->options(fn () => User::query()->admins()->where('active', true)->orderBy('name')->get()
                        ->filter(fn (User $admin) => $admin->canUseArea(AdminArea::Sales))
                        ->pluck('name', 'id')),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data) => BulkOutcome::run(
                $records,
                $skip,
                function (Lead $lead) use ($data) {
                    $lead->update(['assigned_to' => $data['assigned_to'] ?? null]);
                    ActivityLog::record('lead.assigned', "Lead {$lead->displayName()} assigned to ".($lead->assignee->name ?? 'nobody'), properties: ['lead_id' => $lead->id]);
                },
                fn (Lead $lead) => $lead->displayName(),
            )->notify(':count lead assigned|:count leads assigned'));
    }

    public static function lost(): BulkAction
    {
        $skip = fn (Lead $lead) => $lead->status->isOpen() ? null : __('won or lost');

        return BulkAction::make('lost')
            ->label('Mark as lost')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->modalHeading(__('Mark as lost'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip))
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->placeholder(__('Too expensive, went elsewhere, no answer…'))
                    ->rows(2)
                    ->maxLength(255),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data) => BulkOutcome::run(
                $records,
                $skip,
                fn (Lead $lead) => app(Leads::class)->markLost($lead, $data['reason'] ?? null),
                fn (Lead $lead) => $lead->displayName(),
            )->notify(':count lead marked as lost|:count leads marked as lost'));
    }

    public static function reopen(): BulkAction
    {
        $skip = fn (Lead $lead) => $lead->status === LeadStatus::Lost ? null : __('not lost');

        return BulkAction::make('reopen')
            ->label('Reopen')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Reopen leads'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records,
                $skip,
                fn (Lead $lead) => app(Leads::class)->reopen($lead),
                fn (Lead $lead) => $lead->displayName(),
            )->notify(':count lead reopened|:count leads reopened'));
    }

    /** As the lead page's Delete: never one that became a client. */
    public static function delete(): BulkAction
    {
        $skip = fn (Lead $lead) => $lead->client_id === null ? null : __('became a client');

        return BulkAction::make('delete')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Delete leads'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '.__('This cannot be undone.'))
            ->modalSubmitActionLabel(__('Delete them'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records,
                $skip,
                fn (Lead $lead) => $lead->delete(),
                fn (Lead $lead) => $lead->displayName(),
            )->notify(':count lead deleted|:count leads deleted'));
    }
}
