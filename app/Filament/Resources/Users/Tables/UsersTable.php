<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\AdminArea;
use App\Filament\Resources\Users\UserActions;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (User $record) => $record->is(auth()->user()) ? __('You') : null),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->description(fn (User $record) => $record->client ? __('Also the client area login of :client', ['client' => $record->client->name]) : null),
                TextColumn::make('access')
                    ->label('Access')
                    ->state(fn (User $record) => $record->super_admin
                        ? [__('Super admin')]
                        : collect($record->admin_areas ?? [])->map(fn (string $area) => AdminArea::tryFrom($area)?->getLabel())->filter()->values()->all())
                    ->badge()
                    ->color(fn (string $state) => $state === __('Super admin') ? 'primary' : 'gray')
                    ->placeholder(fn () => __('Dashboard only'))
                    ->wrap(),
                IconColumn::make('two_factor')
                    ->label('Two-factor')
                    ->state(fn (User $record) => $record->hasTwoFactor())
                    ->boolean(),
                TextColumn::make('last_login_at')
                    ->label('Last login')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder(fn () => __('Never'))
                    ->sortable(),
                IconColumn::make('active')
                    ->label('Can log in')
                    ->boolean(),
                IconColumn::make('system_emails')
                    ->label('System emails')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([
                UserActions::disableTwoFactor(),
                UserActions::removePasskeys(),
                EditAction::make(),
            ]);
    }
}
