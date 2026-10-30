<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Invoicing\InvoiceNumbers;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Invoices (App\Invoicing): whether a paid payment notice gets an invoice, and how invoices are numbered. The next
 * number is not a setting: it is the series' counter, only ever moved forward.
 */
class InvoiceSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 23;

    protected static ?string $title = 'Invoices';

    protected static ?string $slug = 'invoices';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::BusinessSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Whether a paid payment notice gets an invoice with its own number, and how invoices are numbered.');
    }

    public static function keys(): array
    {
        return ['invoicing.enabled', 'invoicing.number_format'];
    }

    public function form(Schema $schema): Schema
    {
        $on = fn (Get $get) => (bool) $get('invoicing.enabled');

        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->description(__('Payment notices say what a client owes. With invoices on, each notice paid from then on also gets an invoice: a copy that is never changed or deleted, numbered in order without gaps. Notices paid before stay as they are. Check with your accountant what an invoice must show in your country.'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('invoicing.enabled')
                            ->label('Issue an invoice when a payment notice is paid')
                            ->helperText(__('Not for "Add funds" notices: the notices that credit pays get one.'))
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('invoicing.number_format')
                            ->label('Number format')
                            ->required()
                            ->maxLength(40)
                            ->regex('/\{NUMBER\}/')
                            ->validationMessages(['regex' => __('The format needs {NUMBER}.')])
                            ->helperText(__('Tags: {YEAR} {MONTH} {DAY} {NUMBER}. With {YEAR}, numbering starts again at 1 each year.'))
                            ->live(onBlur: true)
                            ->visible($on),
                        TextInput::make('invoicing.next_number')
                            ->label('Next number')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(99999999)
                            ->required()
                            ->helperText(fn (Get $get) => __('The next invoice will be :number. It can only go up.', [
                                'number' => InvoiceNumbers::build((int) $get('invoicing.next_number') ?: 1, today(), str_contains((string) $get('invoicing.number_format'), '{NUMBER}') ? $get('invoicing.number_format') : null),
                            ]))
                            ->live(onBlur: true)
                            ->visible($on),
                    ]),
            ]);
    }

    protected function forForm(array $values): array
    {
        $values['invoicing.enabled'] = (bool) ($values['invoicing.enabled'] ?? false);
        $values['invoicing.number_format'] ??= InvoiceNumbers::DEFAULT_FORMAT;
        $values['invoicing.next_number'] = InvoiceNumbers::upcoming(today());

        return $values;
    }

    protected function forStorage(array $values): array
    {
        $values['invoicing.enabled'] = (bool) ($values['invoicing.enabled'] ?? false);
        $values['invoicing.number_format'] ??= InvoiceNumbers::DEFAULT_FORMAT;

        return $values;
    }

    protected function afterSaving(array $values, array $previous): void
    {
        $next = (int) data_get($this->data, 'invoicing.next_number');

        if ($values['invoicing.enabled'] && $next > 0 && ! InvoiceNumbers::continueFrom($next, today())) {
            Notification::make()->warning()
                ->title(__('The next number stays :number', ['number' => InvoiceNumbers::upcoming(today())]))
                ->body(__('Invoice numbers can\'t go back: a number may have been given already.'))
                ->send();
        }

        data_set($this->data, 'invoicing.next_number', InvoiceNumbers::upcoming(today()));
    }
}
