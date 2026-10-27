<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\Intl\Countries;
use UnitEnum;

class TaxSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 24;

    protected static ?string $title = 'Tax';

    protected static ?string $slug = 'tax';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::BusinessSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Whether payment notices charge tax, prices with or without it, and the rate per country.');
    }

    public static function keys(): array
    {
        return ['tax.enabled', 'tax.inclusive', 'tax.rules'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->description(__('Tax on payment notices. A notice keeps the rate it was created with.'))
                    ->schema([
                        Toggle::make('tax.enabled')
                            ->label('Charge tax')
                            ->helperText(__('Clients marked "Tax exempt" are never charged.')),
                        Radio::make('tax.inclusive')
                            ->label('Prices are entered')
                            ->options([
                                '0' => __('Without tax (exclusive): tax is added on top'),
                                '1' => __('With tax (inclusive): the price already contains it'),
                            ])
                            ->required(),
                        Repeater::make('tax.rules')
                            ->label('Tax rules')
                            ->helperText(__('A rule for the client\'s country beats one for all countries.'))
                            ->columns(3)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel(__('Add rule'))
                            ->schema([
                                TextInput::make('name')->required()->maxLength(50)->placeholder(__('VAT')),
                                TextInput::make('rate')->required()->numeric()->minValue(0)->maxValue(100)->step(0.001)->suffix('%'),
                                Select::make('country')
                                    ->options(fn () => Countries::getNames('en'))
                                    ->searchable()
                                    ->placeholder(__('All countries')),
                            ]),
                    ]),
            ]);
    }

    protected function forForm(array $values): array
    {
        $values['tax.enabled'] = (bool) $values['tax.enabled'];
        $values['tax.inclusive'] = $values['tax.inclusive'] ? '1' : '0';
        $values['tax.rules'] = array_values((array) $values['tax.rules']);

        return $values;
    }

    protected function forStorage(array $values): array
    {
        $values['tax.enabled'] = (bool) $values['tax.enabled'];
        $values['tax.inclusive'] = (bool) (int) $values['tax.inclusive'];
        $values['tax.rules'] = collect($values['tax.rules'] ?? [])->map(fn (array $rule) => [
            'name' => trim((string) $rule['name']),
            'rate' => round((float) $rule['rate'], 3),
            'country' => ($rule['country'] ?? null) ?: null,
        ])->values()->all();

        return $values;
    }
}
