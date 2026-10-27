<?php

namespace Plugins\Webhooks;

use App\Events\GaiaEvent;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Plugins\PluginManager;
use App\Providers\AppServiceProvider;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use UnitEnum;

/** Settings → Webhooks → Delivery history: each event sent to an endpoint, newest first, with Resend. */
class WebhookDeliveriesPage extends Page implements HasTable
{
    use InteractsWithTable;
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Email & notifications';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Webhook deliveries';

    protected static ?string $slug = 'webhooks/deliveries';

    /** The endpoint to show first (Deliveries on Settings → Webhooks). */
    #[Url]
    public ?int $endpoint = null;

    public function getBreadcrumbs(): array
    {
        return [
            ...SettingsCluster::unshiftClusterBreadcrumbs([]),
            WebhooksPage::getUrl() => __('Webhooks'),
            __('Delivery history'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(WebhookDelivery::query()->with('endpoint'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime(AppServiceProvider::dateFormat().' H:i:s')
                    ->sortable(),
                TextColumn::make('endpoint.name')
                    ->label('Webhook'),
                TextColumn::make('event')
                    ->searchable()
                    ->formatStateUsing(fn (string $state) => GaiaEvent::options()[$state] ?? $state)
                    ->description(fn (WebhookDelivery $record) => $record->event),
                TextColumn::make('status')
                    ->badge()
                    ->description(fn (WebhookDelivery $record) => collect([
                        $record->status === DeliveryStatus::Pending && $record->next_attempt_at?->isFuture()
                            ? __('Next try :time', ['time' => $record->next_attempt_at->diffForHumans()])
                            : null,
                        $record->status === DeliveryStatus::Sent ? __('Answer :code', ['code' => $record->response_code]) : $record->error,
                        $record->attempts > 1 ? __(':count tries', ['count' => $record->attempts]) : null,
                    ])->filter()->join(' · ')),
            ])
            ->filters([
                SelectFilter::make('webhook_endpoint_id')
                    ->label('Webhook')
                    ->options(fn () => WebhookEndpoint::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->default($this->endpoint),
                SelectFilter::make('status')
                    ->options(DeliveryStatus::class),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (WebhookDelivery $record) => $record->event)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn (WebhookDelivery $record) => self::details($record)),
                Action::make('resend')
                    ->label('Resend')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->visible(fn (WebhookDelivery $record) => $record->status !== DeliveryStatus::Pending && $record->endpoint?->active && app(PluginManager::class)->enabled('webhooks'))
                    ->action(function (WebhookDelivery $record) {
                        $delivery = app(WebhookSender::class)->resend($record);

                        $delivery->status === DeliveryStatus::Sent
                            ? Notification::make()->success()->title(__('The endpoint answered :code', ['code' => $delivery->response_code]))->send()
                            : Notification::make()->warning()->title(__('Not delivered, tried again later'))->body($delivery->error)->send();
                    }),
            ])
            ->emptyStateHeading(__('Nothing sent yet'));
    }

    /** The JSON sent and the endpoint's answer, as plain preformatted text. */
    private static function details(WebhookDelivery $record): HtmlString
    {
        $json = json_encode(json_decode($record->payload, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $record->payload;
        $pre = 'white-space: pre-wrap; word-break: break-all; font-size: 12px; max-height: 24rem; overflow: auto; padding: .75rem; border-radius: .5rem; background: rgba(127,127,127,.08)';

        $html = '<p style="font-weight: 600; margin-bottom: .25rem">'.e(__('Sent')).'</p><pre style="'.$pre.'">'.e($json).'</pre>';

        if (filled($record->response_body) || $record->response_code !== null) {
            $html .= '<p style="font-weight: 600; margin: .75rem 0 .25rem">'.e(__('Answer :code', ['code' => $record->response_code ?? '–'])).'</p><pre style="'.$pre.'">'.e((string) $record->response_body).'</pre>';
        }

        $html .= '<p style="font-size: 12px; opacity: .7; margin-top: .5rem">X-GaiaCP-Delivery: '.e($record->uuid).'</p>';

        return new HtmlString($html);
    }
}
