<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Mail\TestMail;
use App\Models\ActivityLog;
use App\Support\Settings as SettingsStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Mail;
use Throwable;
use UnitEnum;

class MailSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Email & notifications';

    protected static ?int $navigationSort = 60;

    protected static ?string $title = 'Mail';

    protected static ?string $slug = 'mail';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::EmailSettings;
    }

    public static function settingsDescription(): string
    {
        return __('The SMTP server client emails go out through, and the header and footer around them.');
    }

    public static function keys(): array
    {
        return ['mail.smtp', 'mail.host', 'mail.port', 'mail.encryption', 'mail.username', 'mail.password', 'mail.header', 'mail.footer'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Sending'))
                    ->description(__('Emails go out from the company name and email (Settings → Company).'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('mail.smtp')
                            ->label('Send through an SMTP server')
                            ->helperText(__('Off: the mailer set in .env (MAIL_MAILER) is used.'))
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('mail.host')
                            ->label('SMTP host')
                            ->placeholder('mail.example.com')
                            ->required(fn (Get $get) => (bool) $get('mail.smtp'))
                            ->visible(fn (Get $get) => (bool) $get('mail.smtp'))
                            ->maxLength(255),
                        TextInput::make('mail.port')
                            ->label('Port')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(65535)
                            ->required(fn (Get $get) => (bool) $get('mail.smtp'))
                            ->visible(fn (Get $get) => (bool) $get('mail.smtp')),
                        Select::make('mail.encryption')
                            ->label('Encryption')
                            ->options([
                                'tls' => __('STARTTLS (usually port 587)'),
                                'ssl' => __('SSL/TLS (usually port 465)'),
                                'none' => __('None'),
                            ])
                            ->selectablePlaceholder(false)
                            ->visible(fn (Get $get) => (bool) $get('mail.smtp')),
                        TextInput::make('mail.username')
                            ->label('Username')
                            ->autocomplete('off')
                            ->visible(fn (Get $get) => (bool) $get('mail.smtp'))
                            ->maxLength(255),
                        TextInput::make('mail.password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.mail.password')) ? '••••••••' : null)
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
                            ->visible(fn (Get $get) => (bool) $get('mail.smtp'))
                            ->maxLength(255),
                    ]),
                Section::make(__('Layout'))
                    ->description(__('Wraps every client email, around the text of its template. On top: the company logo from Settings → Company, or the company name without one.'))
                    ->schema([
                        MarkdownEditor::make('mail.header')
                            ->label('Header text')
                            ->helperText(__('Above the text of every email. Markdown or HTML; placeholders such as {$client_first_name} and {$company_name} work.')),
                        MarkdownEditor::make('mail.footer')
                            ->label('Footer text')
                            ->helperText(__('Below the text of every email, e.g. contact details or a legal note. Markdown or HTML, with placeholders.')),
                        Actions::make([
                            Action::make('sendTestEmail')
                                ->label('Send test email')
                                ->icon(Heroicon::OutlinedPaperAirplane)
                                ->color('gray')
                                ->modalDescription(__('Uses the settings on this page as they are now, even before you save them.'))
                                ->schema([
                                    TextInput::make('to')
                                        ->label('Send to')
                                        ->email()
                                        ->required()
                                        ->default(fn () => auth()->user()?->email ?? config('gaiacp.company.email')),
                                ])
                                ->modalSubmitActionLabel(__('Send'))
                                ->action(fn (array $data) => $this->sendTestEmail($data['to'])),
                        ])->key('mailActions'),
                    ]),
            ]);
    }

    protected function forForm(array $values): array
    {
        $values['mail.smtp'] = (bool) $values['mail.smtp'];

        return $values;
    }

    protected function forStorage(array $values): array
    {
        $values['mail.smtp'] = (bool) $values['mail.smtp'];
        $values['mail.port'] = filled($values['mail.port']) ? (int) $values['mail.port'] : null;

        return $values;
    }

    /** Send TestMail with the Mail tab's current (unsaved) values; config changes last only for this request. */
    public function sendTestEmail(string $to): void
    {
        $form = (array) ($this->data['mail'] ?? []);
        $mail = [
            'smtp' => (bool) ($form['smtp'] ?? false),
            'host' => trim((string) ($form['host'] ?? '')),
            'port' => $form['port'] ?? null,
            'encryption' => $form['encryption'] ?? 'tls',
            'username' => trim((string) ($form['username'] ?? '')) ?: null,
            'password' => filled($form['password'] ?? null) ? $form['password'] : config('gaiacp.mail.password'),
        ];

        config([
            'gaiacp.mail.header' => $form['header'] ?? null,
            'gaiacp.mail.footer' => $form['footer'] ?? null,
        ]);
        SettingsStore::applyMailer($mail);

        $via = $mail['smtp'] && $mail['host'] !== '' ? "SMTP {$mail['host']}" : 'the default mailer';

        try {
            Mail::to($to)->send(new TestMail);
        } catch (Throwable $e) {
            ActivityLog::record('email.failed', "Test email to {$to} through {$via} failed: {$e->getMessage()}");
            Notification::make()->danger()->title(__('The test email was not sent'))->body($e->getMessage())->persistent()->send();

            return;
        }

        ActivityLog::record('email.test', "Test email sent to {$to} through {$via}");
        Notification::make()->success()->title(__('Test email sent to :to', ['to' => $to]))->body(__('Through :via. Check the inbox (and spam folder).', ['via' => $via]))->send();
    }
}
