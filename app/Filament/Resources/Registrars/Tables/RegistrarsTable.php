<?php

namespace App\Filament\Resources\Registrars\Tables;

use App\Plugins\PluginManager;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RegistrarsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Active registrars always come first; the chosen sort orders each group.
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByDesc('active'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('module')
                    ->formatStateUsing(fn (string $state) => app(PluginManager::class)->registrarModule($state)?->label() ?? $state)
                    ->badge(),
                TextColumn::make('tlds_count')
                    ->counts('tlds')
                    ->label('TLDs'),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                IconColumn::make('active')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
