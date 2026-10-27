<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Support\Currencies;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Client credit (App\Credit): whether clients can add funds, the limits, and whether credit pays notices by itself. */
class CreditSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 25;

    protected static ?string $title = 'Client credit';

    protected static ?string $slug = 'credit';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::BusinessSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Whether clients can add funds to a balance that pays their payment notices, and its limits.');
    }

    public static function keys(): array
    {
        return ['credit.enabled', 'credit.min_deposit', 'credit.max_deposit', 'credit.max_balance', 'credit.auto_apply'];
    }

    public function form(Schema $schema): Schema
    {
        $amount = fn (string $field) => TextInput::make($field)
            ->numeric()
            ->minValue(1)
            ->maxValue(100000)
            ->required()
            ->prefix(Currencies::base())
            ->visible(fn (Get $get) => (bool) $get('credit.enabled'));

        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->description(__('Clients add funds with an "Add funds" payment notice, paid like any other (by card or bank transfer). Credit then pays their payment notices, whole lines at a time. Admins can add or take off credit on the client page whether this is on or not.'))
                    ->columns(3)
                    ->schema([
                        Toggle::make('credit.enabled')
                            ->label('Clients can add funds')
                            ->helperText(__('Shows Add funds in the client area, for clients with at least one active service.'))
                            ->live()
                            ->columnSpanFull(),
                        $amount('credit.min_deposit')->label('Smallest amount'),
                        $amount('credit.max_deposit')->label('Largest amount')->gte('credit.min_deposit'),
                        $amount('credit.max_balance')->label('Largest balance')->gte('credit.max_deposit')
                            ->helperText(__('A client can\'t add funds above this.')),
                        Toggle::make('credit.auto_apply')
                            ->label('Pay payment notices from credit automatically')
                            ->helperText(__('In the daily run, when an order is placed and when funds are added. Off, clients and admins use "Pay with credit" on a notice.'))
                            ->visible(fn (Get $get) => (bool) $get('credit.enabled'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function forStorage(array $values): array
    {
        foreach (['credit.min_deposit', 'credit.max_deposit', 'credit.max_balance'] as $key) {
            $values[$key] = (float) ($values[$key] ?? config("gaiacp.{$key}"));
        }

        $values['credit.enabled'] = (bool) ($values['credit.enabled'] ?? false);
        $values['credit.auto_apply'] = (bool) ($values['credit.auto_apply'] ?? true);

        return $values;
    }
}
