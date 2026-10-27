<?php

namespace App\Filament\Resources\ClientMessages\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\ClientMessageRecipient;
use App\Providers\AppServiceProvider;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Who a sent message to clients went to, and when (or why it failed). */
class RecipientsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'recipients';

    protected static ?string $title = 'Recipients';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedUsers;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->isQueued();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('client'))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('client.last_name')
                    ->label('Client')
                    ->formatStateUsing(fn (ClientMessageRecipient $record) => $record->client?->name)
                    ->url(fn (ClientMessageRecipient $record) => $record->client_id ? ClientResource::getUrl('edit', ['record' => $record->client_id]) : null),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('status')
                    ->state(fn (ClientMessageRecipient $record) => match (true) {
                        $record->sent_at !== null => __('Sent'),
                        $record->failed_at !== null => __('Failed'),
                        default => __('Waiting'),
                    })
                    ->badge()
                    ->color(fn (ClientMessageRecipient $record) => match (true) {
                        $record->sent_at !== null => 'success',
                        $record->failed_at !== null => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (ClientMessageRecipient $record) => $record->error),
                TextColumn::make('sent_at')
                    ->label('Sent')
                    ->dateTime(AppServiceProvider::dateFormat().' H:i')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Status')
                    ->options(['sent' => __('Sent'), 'failed' => __('Failed'), 'waiting' => __('Waiting')])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'sent' => $query->whereNotNull('sent_at'),
                        'failed' => $query->whereNotNull('failed_at'),
                        'waiting' => $query->whereNull('sent_at')->whereNull('failed_at'),
                        default => $query,
                    }),
            ]);
    }
}
