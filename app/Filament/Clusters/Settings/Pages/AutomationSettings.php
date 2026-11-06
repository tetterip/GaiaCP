<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Actions\ModuleQueue;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Providers\AppServiceProvider;
use App\Support\Health\Heartbeat;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class AutomationSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 26;

    protected static ?string $title = 'Automation';

    protected static ?string $navigationLabel = 'Automation';

    protected static ?string $slug = 'automation';

    public static function settingsDescription(): string
    {
        return __('Reminder and overdue days, the time of the daily run, suspension of unpaid hosting and the ping to an outside monitor.');
    }

    public static function keys(): array
    {
        return ['reminders.send_at', 'reminders.before_days', 'reminders.overdue_days', 'reminders.bcc', 'automation.suspend_after_days', 'automation.cancel_unpaid_orders_days', 'automation.price_change_notice_days', 'automation.usage_warning_percent', 'automation.module_retries', 'automation.client_messages_per_minute', 'automation.compare_servers', 'automation.daily_summary', 'automation.change_request_email', 'automation.cancellation_request_email', 'automation.upgrade_request_email', 'automation.new_order_email', 'automation.new_client_email', 'automation.exchange_rates', 'automation.ping_url', 'automation.ping_fail_url'];
    }

    public function form(Schema $schema): Schema
    {
        $days = fn (string $field) => TagsInput::make($field)
            ->placeholder(__('Add a number of days'))
            ->nestedRecursiveRules(['integer', 'min:1', 'max:365'])
            ->reorderable(false);

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Reminders'))
                    ->columns(2)
                    ->schema([
                        $days('reminders.before_days')
                            ->label('Days before the due date')
                            ->helperText(__('Payment notice reminders (the days after the notice was emailed) and the Upcoming Domain Renewal Notice. A missed day sends only the most urgent one.')),
                        $days('reminders.overdue_days')
                            ->label('Days after the due date (overdue notices)')
                            ->helperText(__('For unpaid payment notices: the first day sends the first overdue notice, every later day the second.')),
                        TextInput::make('reminders.send_at')
                            ->label('Daily run at')
                            ->required()
                            ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                            ->placeholder('09:00')
                            ->helperText(__('24-hour time, :timezone. Reminders and auto-suspension both run then.', ['timezone' => config('app.timezone')])),
                        TextInput::make('reminders.bcc')
                            ->label('BCC every client email to')
                            ->email(),
                    ]),
                Section::make(__('Automation'))
                    ->schema([
                        TextInput::make('automation.suspend_after_days')
                            ->label('Suspend hosting after')
                            ->suffix(__('days overdue'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(365)
                            ->helperText(__('Leave empty to never suspend automatically. Recording the payment unsuspends.')),
                        TextInput::make('automation.cancel_unpaid_orders_days')
                            ->label('Cancel unpaid orders after')
                            ->suffix(__('days'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(365)
                            ->helperText(__('A pending order with nothing paid or set up is cancelled this many days after it was placed, with its items and payment notice. Leave empty to never cancel automatically.')),
                        TextInput::make('automation.price_change_notice_days')
                            ->label('Price change notice')
                            ->suffix(__('days'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(365)
                            ->required()
                            ->helperText(__('Change price can start a new price no sooner than this many days from today, so clients hear about it in time.')),
                        TextInput::make('automation.usage_warning_percent')
                            ->label('Usage warning')
                            ->suffix('%')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100)
                            ->helperText(__('At 06:00, reads the disk space and bandwidth of every active hosting account; one that reaches this share of its package\'s limit gets the Usage Warning email (Email templates), and the bell rings, once a month for each. Leave empty to not check.')),
                        TextInput::make('automation.module_retries')
                            ->label('Retry failed actions')
                            ->suffix(__('times'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(20)
                            ->required()
                            ->helperText(__('A failed set up, unsuspend or suspension for non-payment (Services → Module queue) is tried again every :minutes minutes, this many times; then the bell rings once. Terminations and domain renewals are only retried by hand. 0 = only by hand.', ['minutes' => ModuleQueue::RETRY_MINUTES])),
                        TextInput::make('automation.client_messages_per_minute')
                            ->label('Messages per minute')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(1000)
                            ->required()
                            ->helperText(__('Emails of a message to clients (Clients → Messages to clients) sent each minute by the cron. Lower it if your mail server limits how many you send.')),
                        Toggle::make('automation.compare_servers')
                            ->label('Compare servers nightly')
                            ->helperText(__('At 04:30, compares each active server\'s accounts with GaiaCP (Servers → Compare with the server) and rings the bell of the super admins only when something new differs.')),
                        Toggle::make('automation.daily_summary')
                            ->label('Daily summary email')
                            ->helperText(__('After the daily run: new and overdue payment notices, and domains whose sync changed something or failed, to the admins with Receive system emails on (Settings → Admin users). Not sent when there is nothing to report.')),
                        Toggle::make('automation.change_request_email')
                            ->label('Change request email')
                            ->helperText(__('When a client asks to change their name, company, tax details or address in the client area, to the admins with Receive system emails on who open clients.')),
                        Toggle::make('automation.cancellation_request_email')
                            ->label('Cancellation request email')
                            ->helperText(__('When a client asks to cancel a service in the client area, to the admins with Receive system emails on who open services.')),
                        Toggle::make('automation.upgrade_request_email')
                            ->label('Upgrade request email')
                            ->helperText(__('When a client asks to move a service to another package in the client area, to the admins with Receive system emails on who open services.')),
                        Toggle::make('automation.new_order_email')
                            ->label('New order email')
                            ->helperText(__('When a client places an order in the client area, to the admins with Receive system emails on who open clients and orders.')),
                        Toggle::make('automation.new_client_email')
                            ->label('New client email')
                            ->helperText(__('When someone signs up in the client area (Settings → Client area), to the admins with Receive system emails on who open clients.')),
                        Toggle::make('automation.exchange_rates')
                            ->label('Update exchange rates daily')
                            ->helperText(__('Reads the rates of Settings → Currencies from the European Central Bank every day at 17:00. Prices set in a currency stay as they are; the ones left empty follow the rate.')),
                    ]),
                Section::make(__('Monitoring from outside'))
                    ->description(__('A stopped cron or server cannot warn you by itself. Give a monitoring service a heartbeat (Uptime Kuma push monitor, Healthchecks.io, Better Stack heartbeat, Cronitor…): GaiaCP calls its URL every 5 minutes while all is well, so it alerts you when the calls stop.'))
                    ->schema([
                        TextInput::make('automation.ping_url')
                            ->label('Ping URL')
                            ->url()
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->maxLength(1000)
                            ->placeholder(fn () => config('gaiacp.automation.ping_url') ? __('Set (leave blank to keep it)') : 'https://hc-ping.com/…')
                            ->helperText(__('Called while cron runs and the daily commands and the last backup succeeded. Stored encrypted: the URL is what lets anyone ping your monitor.')),
                        TextInput::make('automation.ping_fail_url')
                            ->label('Ping URL on failure')
                            ->url()
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->maxLength(1000)
                            ->placeholder(fn () => config('gaiacp.automation.ping_fail_url') ? __('Set (leave blank to keep it)') : 'https://hc-ping.com/…/fail')
                            ->helperText(__('Called instead when a daily command or the last backup failed; {message} in it becomes what failed (Uptime Kuma: …?status=down&msg={message}). Leave empty to send no ping then, so the monitor alerts as for a stopped cron.')),
                        Text::make(fn () => $this->lastPing()),
                        Actions::make([
                            Action::make('sendPing')
                                ->label('Send a ping now')
                                ->icon(Heroicon::OutlinedSignal)
                                ->color('gray')
                                ->visible(fn () => Heartbeat::configured())
                                ->action(fn () => $this->sendPing()),
                        ])->key('pingActions'),
                    ]),
            ]);
    }

    private function lastPing(): string
    {
        $last = Heartbeat::last();

        if (! Heartbeat::configured()) {
            return __('No ping is sent until a Ping URL is set.');
        }

        if (! $last) {
            return __('No ping sent yet: the first goes out within 5 minutes once cron runs.');
        }

        $at = CarbonImmutable::parse($last['at'])->format(AppServiceProvider::dateFormat().' H:i');

        return match (true) {
            $last['error'] !== null => __('Last ping :date failed: :error', ['date' => $at, 'error' => $last['error']]),
            ! $last['sent'] => __('Last check :date: :problems, and no Ping URL on failure is set, so no ping was sent.', ['date' => $at, 'problems' => implode('; ', $last['problems'])]),
            $last['failure'] => __('Last ping :date reported a failure: :problems.', ['date' => $at, 'problems' => implode('; ', $last['problems'])]),
            default => __('Last ping :date: all is well.', ['date' => $at]),
        };
    }

    /** Uses the saved URLs, like the cron run. */
    private function sendPing(): void
    {
        $result = app(Heartbeat::class)->send();

        $notification = match (true) {
            $result['error'] !== null => Notification::make()->danger()->title(__('The ping failed'))->body($result['error'])->persistent(),
            ! $result['sent'] => Notification::make()->warning()->title(__('No ping sent'))->body(implode('; ', $result['problems'])),
            $result['problems'] !== [] => Notification::make()->warning()->title(__('Failure ping sent'))->body(implode('; ', $result['problems'])),
            default => Notification::make()->success()->title(__('Ping sent')),
        };

        $notification->send();
    }

    protected function forStorage(array $values): array
    {
        foreach (['reminders.before_days', 'reminders.overdue_days'] as $key) {
            $values[$key] = collect($values[$key] ?? [])->map(fn ($day) => (int) $day)->unique()->sort()->values()->all();
        }

        $values['reminders.before_days'] = array_reverse($values['reminders.before_days']);

        $values['automation.price_change_notice_days'] = (int) ($values['automation.price_change_notice_days'] ?? 30);
        $values['automation.module_retries'] = max(0, (int) ($values['automation.module_retries'] ?? 3));
        $values['automation.client_messages_per_minute'] = max(1, (int) ($values['automation.client_messages_per_minute'] ?? 30));

        foreach (['automation.suspend_after_days', 'automation.cancel_unpaid_orders_days', 'automation.usage_warning_percent'] as $key) {
            $days = $values[$key] ?? null;
            $values[$key] = $days === null || $days === '' ? null : (int) $days;
        }

        return $values;
    }
}
