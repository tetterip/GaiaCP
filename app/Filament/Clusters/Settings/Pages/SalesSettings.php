<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Actions\Leads;
use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Sales: where leads come from (Clients → Leads, App\Actions\Leads), and how long new quotes are valid and their text (App\Actions\Quotes). */
class SalesSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 27;

    protected static ?string $title = 'Sales';

    protected static ?string $slug = 'sales';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Sales;
    }

    public static function settingsDescription(): string
    {
        return __('Where your leads come from, and how long quotes are valid.');
    }

    public static function keys(): array
    {
        return ['sales.lead_sources', 'sales.quote_valid_days', 'sales.quote_footer'];
    }

    protected function forForm(array $values): array
    {
        $values['sales.lead_sources'] = Leads::sources();

        return $values;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Leads'))
                    ->schema([
                        TagsInput::make('sales.lead_sources')
                            ->label('Lead sources')
                            ->placeholder(__('Add a source'))
                            ->nestedRecursiveRules(['string', 'max:100'])
                            ->helperText(__('Offered on each lead; the Lead sources report counts the leads and clients each one brought. Renaming one leaves the leads that have the old name as they are.')),
                    ]),
                Section::make(__('Quotes'))
                    ->schema([
                        TextInput::make('sales.quote_valid_days')
                            ->label('A new quote is valid for')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(365)
                            ->required()
                            ->suffix(__('days'))
                            ->helperText(__('A sent quote not accepted by then expires (checked every morning); each quote\'s date can be changed.')),
                        Textarea::make('sales.quote_footer')
                            ->label('Text under a new quote')
                            ->rows(3)
                            ->maxLength(5000)
                            ->placeholder(__('Prices are per billing cycle. Hosting includes daily backups and support by email.'))
                            ->helperText(__('Filled in on each new quote, where it can be changed.')),
                    ]),
            ]);
    }

    protected function forStorage(array $values): array
    {
        $values['sales.quote_valid_days'] = max(1, (int) ($values['sales.quote_valid_days'] ?? 30));
        $values['sales.lead_sources'] = collect($values['sales.lead_sources'] ?? [])->map(fn ($source) => trim((string) $source))->filter()->unique()->values()->all() ?: null;

        return $values;
    }
}
