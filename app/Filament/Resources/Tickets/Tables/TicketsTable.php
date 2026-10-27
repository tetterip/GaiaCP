<?php

namespace App\Filament\Resources\Tickets\Tables;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Subject (number and client under it), department, priority, status, who has it and the last message. */
class TicketsTable
{
    public static function configure(Table $table, bool $showClient = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('client', 'department', 'assignee'))
            ->columns([
                TextColumn::make('subject')
                    ->description(fn (Ticket $record) => $showClient && $record->requesterName() !== '' ? "{$record->number} · {$record->requesterName()}" : $record->number)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                        ->where('subject', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->when(preg_match('/^(T-)?0*(\d+)$/i', trim($search), $m), fn (Builder $query) => $query->orWhere('id', (int) $m[2]))
                        ->orWhereHas('client', fn (Builder $client) => $client->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('company', 'like', "%{$search}%"))))
                    ->wrap(),
                TextColumn::make('department.name')
                    ->label('Department')
                    ->placeholder('–')
                    ->toggleable(),
                TextColumn::make('priority')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('assignee.name')
                    ->label('Assigned to')
                    ->placeholder('–')
                    ->toggleable(),
                TextColumn::make('last_reply_at')
                    ->label('Last message')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('last_reply_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(TicketStatus::class),
                SelectFilter::make('priority')->options(TicketPriority::class),
                SelectFilter::make('department_id')
                    ->label('Department')
                    ->options(fn () => TicketDepartment::query()->ordered()->pluck('name', 'id')),
            ])
            ->emptyStateHeading(__('No tickets'));
    }
}
