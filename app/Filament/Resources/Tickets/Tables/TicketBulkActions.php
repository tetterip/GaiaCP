<?php

namespace App\Filament\Resources\Tickets\Tables;

use App\Actions\Tickets;
use App\Enums\AdminArea;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Actions\BulkOutcome;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/** Mass actions on the tickets picked in a list, as the ticket page's Close and Change do (logged per ticket). */
class TicketBulkActions
{
    public const MAX = 100;

    public static function group(): BulkActionGroup
    {
        return BulkActionGroup::make([
            self::close(),
            self::change('assign', 'Assign to', Heroicon::OutlinedUserPlus, Select::make('assigned_to')
                ->label('Assigned to')
                ->placeholder(__('Nobody'))
                ->options(fn () => User::query()->admins()->where('active', true)->orderBy('name')->get()
                    ->filter(fn (User $admin) => $admin->canUseArea(AdminArea::Support))
                    ->pluck('name', 'id'))),
            self::change('priority', 'Change priority', Heroicon::OutlinedFlag, Select::make('priority')
                ->options(TicketPriority::class)
                ->required()),
            self::change('department', 'Move to department', Heroicon::OutlinedInboxStack, Select::make('department_id')
                ->label('Department')
                ->placeholder(__('None'))
                ->options(fn () => TicketDepartment::query()->ordered()->pluck('name', 'id'))),
        ]);
    }

    public static function close(): BulkAction
    {
        $skip = fn (Ticket $ticket) => $ticket->status === TicketStatus::Closed ? __('already closed') : null;

        return BulkAction::make('close')
            ->label('Close')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->requiresConfirmation()
            ->modalHeading(__('Close tickets'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '.__('No email is sent. A client\'s reply opens a ticket again.'))
            ->modalSubmitActionLabel(__('Close them'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records,
                $skip,
                fn (Ticket $ticket) => app(Tickets::class)->close($ticket),
                fn (Ticket $ticket) => $ticket->number,
            )->notify(':count ticket closed|:count tickets closed'));
    }

    /** One field of Change set to the same value on every picked ticket. */
    private static function change(string $name, string $label, Heroicon $icon, Select $field): BulkAction
    {
        $column = $field->getName();

        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->modalHeading(__($label))
            ->schema([$field])
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data) => BulkOutcome::run(
                $records,
                fn () => null,
                fn (Ticket $ticket) => app(Tickets::class)->update($ticket, [$column => match ($column) {
                    'priority' => $data['priority'] instanceof TicketPriority ? $data['priority'] : TicketPriority::from($data['priority']),
                    default => ($data[$column] ?? null) ?: null,
                }]),
                fn (Ticket $ticket) => $ticket->number,
            )->notify(':count ticket changed|:count tickets changed'));
    }
}
