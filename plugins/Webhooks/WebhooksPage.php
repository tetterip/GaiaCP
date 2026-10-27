<?php

namespace Plugins\Webhooks;

use App\Events\GaiaEvent;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Settings → Webhooks: the URLs that get GaiaCP's events (WebhookEndpoint), with Test, a new secret and the delivery
 * history. Super admins only: the events carry client details. A secret is shown once, when it is made. Open while
 * the plugin is off too, with its On switch on top; nothing is queued or sent then.
 */
class WebhooksPage extends Page implements HasTable, SettingsCard
{
    use InteractsWithTable;
    use LimitedToAdminArea;
    use PluginSwitch;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Email & notifications';

    protected static ?int $navigationSort = 65;

    protected static ?string $title = 'Webhooks';

    protected static ?string $slug = 'webhooks';

    public static function pluginId(): string
    {
        return 'webhooks';
    }

    public static function settingsDescription(): string
    {
        return __('Send GaiaCP\'s events as signed JSON to your own scripts, Zapier, Make or n8n.');
    }

    public function getSubheading(): ?string
    {
        return __('Each event is sent as a JSON POST within a minute, signed with the endpoint\'s secret (X-GaiaCP-Signature). A failed one is tried again after 1, 5 and 30 minutes, 2 and 12 hours.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([...$this->beforeForm(), EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('history')
                ->label('Delivery history')
                ->icon(Heroicon::OutlinedQueueList)
                ->color('gray')
                ->url(fn () => WebhookDeliveriesPage::getUrl()),
            Action::make('create')
                ->label('New webhook')
                ->visible(fn () => $this->pluginEnabled)
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading(__('New webhook'))
                ->schema(self::formFields())
                ->fillForm(['all_events' => true, 'active' => true])
                ->action(function (array $data) {
                    $secret = WebhookEndpoint::newSecret();
                    WebhookEndpoint::query()->create(self::fromForm($data) + ['secret' => $secret]);
                    self::showSecret($secret);
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(WebhookEndpoint::query())
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (WebhookEndpoint $record) => $record->url),
                TextColumn::make('events')
                    ->label('Events')
                    ->state(fn (WebhookEndpoint $record) => $record->eventsLabel())
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('failures')
                    ->label('Last delivery')
                    ->badge()
                    ->state(fn (WebhookEndpoint $record) => match (true) {
                        $record->failures > 0 => __(':count failed in a row', ['count' => $record->failures]),
                        $record->last_success_at !== null => __('Sent'),
                        default => __('Nothing sent yet'),
                    })
                    ->color(fn (WebhookEndpoint $record) => match (true) {
                        $record->failures > 0 => 'danger',
                        $record->last_success_at !== null => 'success',
                        default => 'gray',
                    })
                    ->description(fn (WebhookEndpoint $record) => ($at = $record->failures > 0 ? $record->last_failure_at : $record->last_success_at)?->diffForHumans()),
                ToggleColumn::make('active')
                    ->label('On')
                    ->afterStateUpdated(function (WebhookEndpoint $record, bool $state) {
                        // Switched back on: counted afresh, so the bell can tell again
                        if ($state) {
                            $record->update(['failures' => 0, 'alerted_at' => null]);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('test')
                    ->label('Test')
                    ->visible(fn () => $this->pluginEnabled)
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('gray')
                    ->action(function (WebhookEndpoint $record) {
                        $delivery = app(WebhookSender::class)->test($record);

                        $delivery->status === DeliveryStatus::Sent
                            ? Notification::make()->success()->title(__('The endpoint answered :code', ['code' => $delivery->response_code]))->send()
                            : Notification::make()->danger()->title(__('The test was not delivered'))->body($delivery->error)->send();
                    }),
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->modalHeading(fn (WebhookEndpoint $record) => $record->name)
                    ->schema(self::formFields())
                    ->fillForm(fn (WebhookEndpoint $record) => [
                        'name' => $record->name,
                        'url' => $record->url,
                        'all_events' => in_array('*', $record->events ?? [], true),
                        'events' => array_values(array_diff($record->events ?? [], ['*'])),
                        'active' => $record->active,
                    ])
                    ->action(function (WebhookEndpoint $record, array $data) {
                        $values = self::fromForm($data);
                        if ($values['active'] && ! $record->active) {
                            $values += ['failures' => 0, 'alerted_at' => null];
                        }
                        $record->update($values);
                        Notification::make()->success()->title(__('Saved'))->send();
                    }),
                Action::make('deliveries')
                    ->label('Deliveries')
                    ->icon(Heroicon::OutlinedQueueList)
                    ->color('gray')
                    ->url(fn (WebhookEndpoint $record) => WebhookDeliveriesPage::getUrl(['endpoint' => $record->id])),
                Action::make('secret')
                    ->label('New secret')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(__('The current secret stops working at once, so the receiver must check signatures with the new one.'))
                    ->action(function (WebhookEndpoint $record) {
                        $secret = WebhookEndpoint::newSecret();
                        $record->update(['secret' => $secret]);
                        self::showSecret($secret);
                    }),
                DeleteAction::make()
                    ->modalDescription(__('Its delivery history is deleted too.')),
            ])
            ->emptyStateHeading(__('No webhooks yet'))
            ->emptyStateDescription(__('Add the URL of a script or an automation tool and pick the events it should get.'));
    }

    /** @return list<mixed> */
    private static function formFields(): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(100)
                ->placeholder('n8n'),
            TextInput::make('url')
                ->label('URL')
                ->required()
                ->url()
                ->maxLength(2048)
                ->rule('regex:/^https?:\/\//i')
                ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                    if (is_string($value) && WebhookAddress::inspect(trim($value))['error'] !== null) {
                        $fail(__('Webhooks are not sent to this address: it is on a local or private network, or its host cannot be found.'));
                    }
                })
                ->placeholder('https://example.com/webhooks/gaiacp'),
            Toggle::make('all_events')
                ->label('All events')
                ->helperText(__('Also the events added in later versions.'))
                ->live(),
            CheckboxList::make('events')
                ->label('Events')
                ->options(fn () => GaiaEvent::options())
                ->columns(2)
                ->bulkToggleable()
                ->hidden(fn (Get $get) => (bool) $get('all_events'))
                ->required(fn (Get $get) => ! $get('all_events')),
            Toggle::make('active')
                ->label('On'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function fromForm(array $data): array
    {
        return [
            'name' => trim((string) $data['name']),
            'url' => trim((string) $data['url']),
            'events' => ($data['all_events'] ?? false) ? ['*'] : array_values(array_intersect($data['events'] ?? [], GaiaEvent::NAMES)),
            'active' => (bool) ($data['active'] ?? true),
        ];
    }

    private static function showSecret(string $secret): void
    {
        Notification::make()
            ->success()
            ->persistent()
            ->title(__('Copy the signing secret now'))
            ->body(__('It is shown only this once: :secret', ['secret' => $secret]))
            ->send();
    }
}
