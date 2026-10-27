<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\AdminArea;
use App\Filament\Forms\PasswordInput;
use App\Models\User;
use App\Support\Locales;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Admin user'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        PasswordInput::make()
                            ->required(fn (string $operation) => $operation === 'create')
                            ->minLength(8)
                            // Not loaded into the page; a blank field keeps the stored one.
                            ->formatStateUsing(fn () => null)
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->helperText(fn (string $operation) => $operation === 'edit'
                                ? __('Leave empty to keep the current password. A new one logs them out everywhere.')
                                : __('Give it to them in person; they can change it on their profile.')),
                        Select::make('locale')
                            ->label('Panel language')
                            ->options(Locales::available())
                            ->placeholder(fn () => __('Default (:language)', ['language' => Locales::available()[Locales::default()]]))
                            ->visible(Locales::multiple()),
                        Toggle::make('active')
                            ->label('Can log in')
                            ->default(true)
                            // Nobody can lock themselves out.
                            ->disabled(fn (?User $record) => $record?->is(auth()->user()))
                            ->helperText(fn (?User $record) => $record?->is(auth()->user())
                                ? __('You cannot deactivate yourself.')
                                : __('Off = they cannot log in, and are logged out on their next click.')),
                        Toggle::make('super_admin')
                            ->label('Super admin')
                            ->live()
                            // Nobody changes their own access: a super admin can't step down and leave none.
                            ->disabled(fn (?User $record) => $record?->is(auth()->user()))
                            ->helperText(fn (?User $record) => $record?->is(auth()->user())
                                ? __('You cannot change your own access.')
                                : __('Opens everything, including admin users, servers, registrars, plugins and the System settings.')),
                        CheckboxList::make('admin_areas')
                            ->label('Can open')
                            ->options(AdminArea::class)
                            ->descriptions(collect(AdminArea::cases())->mapWithKeys(fn (AdminArea $area) => [$area->value => $area->getDescription()])->all())
                            ->columns(2)
                            ->columnSpanFull()
                            ->bulkToggleable()
                            ->hidden(fn (Get $get) => (bool) $get('super_admin'))
                            ->disabled(fn (?User $record) => $record?->is(auth()->user()))
                            ->helperText(__('Everything else is hidden from their menu and refused if they open its address. The dashboard shows only what they can open.')),
                        Toggle::make('system_emails')
                            ->label('Receive system emails')
                            ->default(true)
                            ->helperText(__('Emails the system sends to admins, such as the daily summary (switched on or off for everyone under Settings → Automation).')),
                        Toggle::make('panel_notifications')
                            ->label('Notifications in the panel')
                            ->default(true)
                            ->helperText(__('The bell in the top bar. Each admin can also switch it in their profile.')),
                    ]),
            ]);
    }
}
