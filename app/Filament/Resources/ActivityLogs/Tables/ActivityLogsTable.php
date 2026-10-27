<?php

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Filament\Resources\Services\ServiceResource;
use App\Models\ActivityLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ActivityLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('event')
                    ->badge(),
                TextColumn::make('client.last_name')
                    ->label('Client')
                    ->formatStateUsing(fn (ActivityLog $record) => $record->client?->name)
                    ->placeholder('—')
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('service.label')
                    ->placeholder('—'),
                TextColumn::make('description')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('By')
                    ->state(fn (ActivityLog $record) => $record->user?->name
                        ?? ($record->apiKey ? __('API: :key', ['key' => $record->apiKey->name]) : null))
                    ->placeholder(__('Automation')),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('event')
                    ->options(fn () => ActivityLog::query()->distinct()->orderBy('event')->pluck('event', 'event')->all()),
            ])
            ->recordUrl(fn (ActivityLog $record) => $record->service_id ? ServiceResource::getUrl('edit', ['record' => $record->service_id]) : null);
    }
}
