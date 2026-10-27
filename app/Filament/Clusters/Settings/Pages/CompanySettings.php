<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Models\ActivityLog;
use App\Support\Branding;
use App\Support\Demo;
use App\Support\SvgLogo;
use BackedEnum;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

class CompanySettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|UnitEnum|null $navigationGroup = 'General';

    protected static ?int $navigationSort = 12;

    protected static ?string $title = 'Company';

    protected static ?string $slug = 'company';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::BusinessSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Name, email, address, VAT number and tax office that client emails are sent from and signed with, and the logo.');
    }

    public static function keys(): array
    {
        return ['company.name', 'company.email', 'company.phone', 'company.website', 'company.address', 'company.vat_number', 'company.tax_office', 'company.logo', 'company.logo_dark', 'company.favicon'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->description(__('Reminder emails are sent from this name and address and signed with these details.'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('company.name')->label('Name')->required()->maxLength(255),
                        TextInput::make('company.email')->label('Email')->email()->required()->maxLength(255),
                        TextInput::make('company.phone')->label('Phone')->tel()->maxLength(50),
                        TextInput::make('company.website')->label('Website')->url()->maxLength(255),
                        TextInput::make('company.vat_number')->label('VAT number')->maxLength(20),
                        TextInput::make('company.tax_office')->label('Tax office')->maxLength(100),
                        Textarea::make('company.address')->label('Address')->rows(3)->columnSpanFull(),
                    ]),
                Section::make(__('Logo'))
                    ->description(__("Shown on the client area's login page and menu, on payment notices and at the top of every client email. Without a logo the company name is shown. The admin panel keeps the GaiaCP brand."))
                    ->columns(2)
                    ->schema([
                        $this->upload('company.logo')
                            ->label('Logo')
                            ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/jpeg'])
                            ->helperText(fn () => collect([
                                __('SVG, PNG or JPG, up to 1 MB, wide rather than tall (e.g. 400 × 100). SVG stays sharp at any size; a PNG with a transparent background also works well.'),
                                SvgLogo::canConvert()
                                    ? __('Emails get a PNG copy of an SVG, since most email apps can\'t show SVG.')
                                    : __('Emails get a PNG copy of an SVG, since most email apps can\'t show SVG: your browser makes it on this page, as this server can\'t (the PHP imagick extension is missing or has no SVG support).'),
                            ])->implode(' ')),
                        $this->upload('company.logo_dark')
                            ->label('Logo for dark mode')
                            ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/jpeg'])
                            ->helperText(__('Optional: a light version for the client area in dark mode. Without one the logo above is used.')),
                        $this->upload('company.favicon')
                            ->label('Browser tab icon')
                            ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/x-icon', 'image/vnd.microsoft.icon'])
                            ->helperText(__('Optional: a square SVG, PNG or ICO (e.g. 64 × 64), shown in the browser tab of the client area.')),
                    ]),
            ]);
    }

    /** Where the server can't make the PNG copy of an SVG logo for emails, the browser draws it (`settings.email-logo`). */
    protected function beforeForm(): array
    {
        return [
            View::make('filament.settings.email-logo')
                ->visible(fn () => $this->emailLogoMissing())
                ->viewData(fn () => [
                    'url' => Branding::url('logo'),
                    'height' => SvgLogo::EMAIL_HEIGHT,
                    'maxWidth' => SvgLogo::EMAIL_MAX_WIDTH,
                ]),
        ];
    }

    /** Called by `settings.email-logo` with the PNG the browser drew of the SVG logo. */
    public function storeEmailLogo(string $dataUrl): void
    {
        $path = Branding::path('logo');

        if ($this->emailLogoMissing() && SvgLogo::storePng($path, $dataUrl)) {
            ActivityLog::record('settings.email_logo', 'PNG copy of the SVG logo for emails made in the browser');
        }
    }

    private function emailLogoMissing(): bool
    {
        $path = Branding::path('logo');

        return $path !== null && SvgLogo::isSvg($path) && Branding::emailLogoPath() === null;
    }

    private function upload(string $key): FileUpload
    {
        $asset = array_search(str($key)->after('company.')->toString(), Branding::ASSETS, true);

        return FileUpload::make($key)
            ->image()
            ->disk('public')
            ->directory('branding')
            ->disabled(Demo::enabled()) // a logo uploaded to the demo would show to every visitor
            ->visibility('public')
            ->maxSize(1024)
            ->imagePreviewHeight('120')
            // An SVG must be safe to show and say its proportions (SvgLogo), and is stored cleaned
            ->rules([
                fn () => function (string $attribute, mixed $file, Closure $fail): void {
                    if ($file instanceof TemporaryUploadedFile && self::isSvgUpload($file) && SvgLogo::clean((string) $file->get()) === null) {
                        $fail(__('This SVG can\'t be used: it needs a viewBox, or a width and height in pixels, so its proportions are known. Save it again from your design app (e.g. "Export as SVG").'));
                    }
                },
            ])
            ->saveUploadedFileUsing(function (FileUpload $component, TemporaryUploadedFile $file): ?string {
                if (! self::isSvgUpload($file)) {
                    return $component->saveUploadedFile($file);
                }

                $path = $component->getDirectory().'/'.Str::random(40).'.svg';
                Storage::disk('public')->put($path, (string) SvgLogo::clean((string) $file->get()));

                return $path;
            })
            // The saved file's preview comes through the branding route too, so it shows without storage:link
            ->getUploadedFileUsing(function (FileUpload $component, string $file, string|array|null $storedFileNames) use ($asset, $key): ?array {
                $uploaded = $component->getUploadedFile($file, $storedFileNames);

                if ($uploaded !== null && $file === config("gaiacp.{$key}")) {
                    $uploaded['url'] = Branding::url($asset) ?? $uploaded['url'];
                }

                return $uploaded;
            });
    }

    private static function isSvgUpload(TemporaryUploadedFile $file): bool
    {
        return $file->getMimeType() === 'image/svg+xml' || SvgLogo::isSvg($file->getClientOriginalName());
    }

    protected function afterSaving(array $values, array $previous): void
    {
        foreach (Branding::ASSETS as $key) {
            $old = $previous["company.{$key}"] ?? null;

            if (filled($old) && $old !== ($values["company.{$key}"] ?? null)) {
                Branding::delete($old);
            }
        }
    }
}
