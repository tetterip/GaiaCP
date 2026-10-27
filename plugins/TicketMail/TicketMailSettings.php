<?php

namespace Plugins\TicketMail;

use App\Filament\Clusters\Settings\Pages\SupportSettings;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Models\TicketDepartment;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

/** The support mailbox (IMAP) and what becomes of its emails. Super admins only, like the other plugins' logins. */
class TicketMailSettings extends SettingsFormPage
{
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 54;

    protected static ?string $title = 'Email to ticket';

    protected static ?string $slug = 'email-to-ticket';

    public static function settingsDescription(): string
    {
        return __('Reads your support mailbox: answers to ticket emails join their ticket, new emails open one.');
    }

    public static function pluginId(): string
    {
        return 'ticket-mail';
    }

    public static function keys(): array
    {
        return (new TicketMailPlugin)->settingKeys();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(function () {
                    // The form's values, saved or not (a blank password = the saved one)
                    $state = data_get($this->form->getRawState(), 'ticket_mail', []);
                    foreach (['host', 'port', 'encryption', 'verify_tls', 'username', 'password', 'folder'] as $key) {
                        if (($state[$key] ?? null) !== null && $state[$key] !== '') {
                            config(["gaiacp.ticket_mail.{$key}" => $state[$key]]);
                        }
                    }

                    $connector = app(MailboxConnector::class);
                    if (! $connector->configured()) {
                        Notification::make()->warning()->title(__('Fill in the server and the username first'))->send();

                        return;
                    }

                    try {
                        $mailbox = $connector->mailbox();
                        $unread = $connector->folder($mailbox)->messages()->unseen()->count();
                        rescue(fn () => $mailbox->disconnect(), report: false);
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title(__('Connection failed'))->body($e->getMessage())->persistent()->send();

                        return;
                    }

                    Notification::make()->success()->title(__('Connected: :count unread email(s)', ['count' => $unread]))->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Mailbox'))
                    ->description(new HtmlString(e(__('A mailbox only for support, e.g. support@ on your mail server. GaiaCP reads its unread emails every minute over IMAP. Put its address under Settings → Support → Replies to ticket emails go to, so clients\' answers come back here.'))
                        .(SupportSettings::canAccess() ? ' <a href="'.e(SupportSettings::getUrl()).'">'.e(__('Open Support settings')).'</a>' : '')))
                    ->columns(2)
                    ->schema([
                        TextInput::make('ticket_mail.host')
                            ->label('IMAP server')
                            ->placeholder('mail.example.com')
                            ->maxLength(255),
                        TextInput::make('ticket_mail.port')
                            ->label('Port')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(65535)
                            ->placeholder('993'),
                        Select::make('ticket_mail.encryption')
                            ->label('Encryption')
                            ->options(['ssl' => 'SSL/TLS (993)', 'starttls' => 'STARTTLS (143)', 'none' => __('None')])
                            ->selectablePlaceholder(false),
                        Toggle::make('ticket_mail.verify_tls')
                            ->label('Verify the TLS certificate')
                            ->helperText(__('Off only for a server whose certificate is not valid for its name.')),
                        TextInput::make('ticket_mail.username')
                            ->label('Username')
                            ->placeholder('support@example.com')
                            ->autocomplete('off')
                            ->maxLength(255),
                        TextInput::make('ticket_mail.password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.ticket_mail.password')) ? '••••••••' : null)
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
                            ->maxLength(255),
                        TextInput::make('ticket_mail.folder')
                            ->label('Folder')
                            ->placeholder('INBOX')
                            ->maxLength(255),
                        Select::make('ticket_mail.after')
                            ->label('Once read')
                            ->options(['seen' => __('Mark as read'), 'delete' => __('Delete from the mailbox')])
                            ->selectablePlaceholder(false),
                    ]),
                Section::make(__('Receive by webhook'))
                    ->description(__('Instead of a mailbox (or as well), a mail provider such as Mailgun, SendGrid or Postmark can post each email it receives for your support address to GaiaCP. Set it to post the whole message (raw MIME): Mailgun a route that forwards to the address ending in /mime, SendGrid Inbound Parse with "POST the raw, full MIME message", Postmark inbound with "Include raw email content".'))
                    ->schema([
                        Text::make(fn () => filled(config('gaiacp.ticket_mail.inbound_token'))
                            ? __('On: emails posted to its secret address become tickets. The address is shown only when it is made; make a new one if you lost it.')
                            : __('Off: no webhook address yet.')),
                        Actions::make([
                            Action::make('newWebhookAddress')
                                ->label(fn () => filled(config('gaiacp.ticket_mail.inbound_token')) ? __('New webhook address') : __('Make a webhook address'))
                                ->icon(Heroicon::OutlinedKey)
                                ->color('gray')
                                ->requiresConfirmation(fn () => filled(config('gaiacp.ticket_mail.inbound_token')))
                                ->modalDescription(__('The old address stops working: change it at your mail provider too.'))
                                ->action(function () {
                                    $token = Str::random(40);
                                    Settings::save(['ticket_mail.inbound_token' => $token]);

                                    Notification::make()->success()->persistent()
                                        ->title(__('Webhook address made'))
                                        ->body(__('Copy it to your mail provider now; it is not shown again: :url', ['url' => route('webhooks.ticket-mail', $token)]))
                                        ->send();
                                }),
                            Action::make('turnOffWebhook')
                                ->label('Turn off')
                                ->icon(Heroicon::OutlinedNoSymbol)
                                ->color('danger')
                                ->link()
                                ->requiresConfirmation()
                                ->visible(fn () => filled(config('gaiacp.ticket_mail.inbound_token')))
                                ->action(function () {
                                    Settings::save(['ticket_mail.inbound_token' => null]);
                                    Notification::make()->success()->title(__('Webhook turned off'))->send();
                                }),
                        ])->key('webhookActions'),
                    ]),
                Section::make(__('Tickets'))
                    ->columns(2)
                    ->schema([
                        Select::make('ticket_mail.department_id')
                            ->label('Department')
                            ->placeholder(__('None'))
                            ->options(fn () => TicketDepartment::query()->ordered()->pluck('name', 'id'))
                            ->helperText(__('Of a new ticket sent to an address no department has as its email.')),
                        Select::make('ticket_mail.unknown_senders')
                            ->label('Email from someone who is not a client')
                            ->options(['open' => __('Opens a ticket without a client'), 'ignore' => __('Is ignored')])
                            ->selectablePlaceholder(false)
                            ->helperText(__('You link such a ticket to a client under Change.')),
                        TextInput::make('ticket_mail.max_per_hour')
                            ->label('New tickets per sender in an hour')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(1000)
                            ->required()
                            ->helperText(__('More from the same address are ignored, so a mail loop can\'t flood the tickets. Answers to a ticket don\'t count.')),
                    ]),
            ]);
    }

    protected function forStorage(array $values): array
    {
        $values['ticket_mail.host'] = filled($values['ticket_mail.host'] ?? null) ? trim($values['ticket_mail.host']) : null;
        $values['ticket_mail.port'] = filled($values['ticket_mail.port'] ?? null) ? (int) $values['ticket_mail.port'] : null;
        $values['ticket_mail.verify_tls'] = (bool) ($values['ticket_mail.verify_tls'] ?? false);
        $values['ticket_mail.username'] = filled($values['ticket_mail.username'] ?? null) ? trim($values['ticket_mail.username']) : null;
        $values['ticket_mail.folder'] = filled($values['ticket_mail.folder'] ?? null) ? trim($values['ticket_mail.folder']) : 'INBOX';
        $values['ticket_mail.department_id'] = filled($values['ticket_mail.department_id'] ?? null) ? (int) $values['ticket_mail.department_id'] : null;
        $values['ticket_mail.max_per_hour'] = (int) ($values['ticket_mail.max_per_hour'] ?? 10);

        return $values;
    }
}
