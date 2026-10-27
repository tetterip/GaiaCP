<?php

namespace Plugins\BankTransfer;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\PaymentMethodPage;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class BankTransferSettings extends SettingsFormPage
{
    use PaymentMethodPage;
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 21;

    protected static ?string $title = 'Bank transfer';

    protected static ?string $slug = 'bank-transfer';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::BusinessSettings;
    }

    public static function settingsDescription(): string
    {
        return __('The bank details printed on every payment notice and reminder.');
    }

    public static function pluginId(): string
    {
        return 'bank_transfer';
    }

    public static function keys(): array
    {
        return (new BankTransferPlugin)->settingKeys();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->schema([
                        Textarea::make('payment_instructions')
                            ->label('Payment instructions')
                            ->helperText(__('Printed at the bottom of every payment notice and reminder, e.g. bank and IBAN.'))
                            ->rows(5),
                    ]),
            ]);
    }
}
