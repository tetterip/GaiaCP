<?php

namespace Plugins\Iris;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\PaymentMethodPage;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Support\Demo;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/** The IRIS details clients pay to: payee, IRIS number or alias, the bank's QR code and a note. */
class IrisSettings extends SettingsFormPage
{
    use PaymentMethodPage;
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 24;

    protected static ?string $title = 'IRIS';

    protected static ?string $slug = 'iris';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::BusinessSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Payments by IRIS from the client\'s bank app: your IRIS number and your bank\'s QR code on unpaid payment notices.');
    }

    public static function pluginId(): string
    {
        return 'iris';
    }

    public static function keys(): array
    {
        return (new IrisPlugin)->settingKeys();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->description(__('Clients see "Pay with IRIS" on their unpaid payment notices in the client area once there is an IRIS number or a QR code. It shows them the amount, these details and the notice number to write as the reason. IRIS has no connection for shops, so you record each payment by hand (method IRIS) when it reaches your account.'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('iris.payee')
                            ->label('Payee')
                            ->placeholder(fn () => config('gaiacp.company.name'))
                            ->helperText(__('The name clients see in their bank\'s app. Empty: your company name.'))
                            ->maxLength(120),
                        TextInput::make('iris.number')
                            ->label('IRIS number or alias')
                            ->helperText(__('The VAT number, mobile number or alias your bank linked to IRIS.'))
                            ->maxLength(60),
                        FileUpload::make('iris.qr')
                            ->label('IRIS QR code')
                            ->helperText(__('The fixed QR code your bank gave you for IRIS payments (PNG, JPG or WebP, up to 1 MB).'))
                            ->image()
                            ->disabled(Demo::enabled()) // an image uploaded to the demo would show to every visitor
                            ->acceptedFileTypes(Iris::QR_TYPES)
                            ->disk(Iris::DISK)
                            ->directory(Iris::DIRECTORY)
                            ->visibility('private')
                            ->maxSize(1024)
                            ->imagePreviewHeight('200')
                            ->columnSpanFull(),
                        Textarea::make('iris.note')
                            ->label('Note for clients')
                            ->helperText(__('Optional, shown under the details, e.g. which banks the QR code works with.'))
                            ->rows(3)
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function afterSaving(array $values, array $previous): void
    {
        $old = $previous['iris.qr'] ?? null;

        if (is_string($old) && $old !== '' && $old !== ($values['iris.qr'] ?? null)) {
            Storage::disk(Iris::DISK)->delete($old);
        }
    }
}
