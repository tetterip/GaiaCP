<?php

namespace App\Filament\Resources\Shared;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ActivityLogsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'activityLogs';

    protected static ?string $title = 'Activity';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClock;

    /** Only for admins who may open the Activity log itself. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ActivityLogResource::canAccess();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('event')
                    ->badge(),
                TextColumn::make('description')
                    ->wrap(),
                TextColumn::make('user.name')
                    ->label('By')
                    ->placeholder(__('Automation')),
            ])
            ->defaultSort('id', 'desc');
    }
}
