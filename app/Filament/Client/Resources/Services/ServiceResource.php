<?php

namespace App\Filament\Client\Resources\Services;

use App\Enums\CancellationType;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Certificates\CertificateEntries;
use App\Filament\Client\Actions\HostingSelfService;
use App\Filament\Client\Actions\OrderAddon;
use App\Filament\Client\Actions\RequestCancellation;
use App\Filament\Client\Actions\RequestUpgrade;
use App\Filament\Client\Concerns\ScopedToClient;
use App\Filament\Client\Pages\Order;
use App\Filament\Client\Resources\Services\Pages\ListServices;
use App\Filament\Client\Resources\Services\Pages\ViewService;
use App\Livewire\HostingUsage;
use App\Models\CancellationRequest;
use App\Models\Service;
use App\Monitoring\MonitorStatus;
use App\Monitoring\UptimeHistory;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use App\Support\ProductOptions;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

use function Filament\Support\original_request;

/** The client's hosting and other services (not domains), only those shown in the client area. Read-only. */
class ServiceResource extends Resource
{
    use ScopedToClient;

    protected static ?string $model = Service::class;

    protected static ?string $slug = 'services';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'label';

    public static function getNavigationGroup(): string
    {
        return __('Services');
    }

    /** Hosting and Other services in the sidebar, like the admin's; Domains (DomainResource) sits between them. */
    public static function getNavigationItems(): array
    {
        $items = [
            'hosting' => [__('Hosting'), Heroicon::OutlinedServerStack, 1],
            'other' => [__('Other services'), Heroicon::OutlinedWrenchScrewdriver, 3],
        ];

        return collect($items)->map(fn (array $item, string $kind) => NavigationItem::make($item[0])
            ->key(static::class.'.'.$kind)
            ->icon($item[1])
            ->group(static::getNavigationGroup())
            ->sort($item[2])
            ->isActiveWhen(fn (): bool => self::activeKind() === $kind)
            ->url(static::getUrl('index', ['kind' => $kind])))
            ->values()
            ->all();
    }

    /** The sidebar entry of the current services page: its ?kind, or the type of the service being viewed. */
    private static function activeKind(): ?string
    {
        $request = original_request();

        if (! $request->routeIs(static::getRouteBaseName().'.*')) {
            return null;
        }

        if ($request->route('record') === null) {
            return in_array($request->query('kind'), ['hosting', 'other'], true) ? $request->query('kind') : null;
        }

        $type = static::getEloquentQuery()->whereKey($request->route('record'))->value('type');

        return $type === null ? null : (ServiceType::from($type instanceof ServiceType ? $type->value : $type) === ServiceType::Hosting ? 'hosting' : 'other');
    }

    protected static function scopeToClient(Builder $query): Builder
    {
        return $query->where('client_id', static::clientId())
            ->clientVisible()
            ->where('type', '!=', ServiceType::Domain)
            ->where('status', '!=', ServiceStatus::Cancelled);
    }

    /** "€120.00 / Annually" */
    public static function price(Service $service): string
    {
        return Money::format($service->price_cents, $service->currency).' / '.$service->billing_cycle->getLabel();
    }

    /** "From 01/12/2026: €66.00" while a price change (Change price) is scheduled. */
    public static function newPrice(Service $service): ?string
    {
        return $service->new_price_from && ! $service->auto_terminate
            ? __('From :date: :price', ['date' => $service->new_price_from->format(AppServiceProvider::dateFormat()), 'price' => Money::format((int) $service->new_price_cents, $service->currency)])
            : null;
    }

    /** The product's name, which reads better than the server's plan name; the plan for a service without one. */
    public static function package(Service $service): ?string
    {
        // An SSL service's package is the provider's product code, which means nothing to the client.
        return $service->product?->name ?? ($service->type === ServiceType::Ssl ? $service->label : $service->package);
    }

    public static function table(Table $table): Table
    {
        return $table
            // ?kind=hosting / other: the sidebar's Hosting and Other services (ListServices::$kind)
            ->modifyQueryUsing(fn (Builder $query, $livewire) => $query->with(['product', 'server'])
                ->when(($livewire->kind ?? null) === 'hosting', fn (Builder $query) => $query->where('type', ServiceType::Hosting))
                ->when(($livewire->kind ?? null) === 'other', fn (Builder $query) => $query->where('type', '!=', ServiceType::Hosting)))
            ->columns([
                TextColumn::make('label')
                    ->label('Service')
                    ->description(fn (Service $record) => static::domainWithState($record))
                    ->searchable(['label', 'domain'])
                    ->sortable(),
                TextColumn::make('package')
                    ->state(fn (Service $record) => static::package($record))
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('price_cents')
                    ->label('Price')
                    ->state(fn (Service $record) => static::price($record))
                    ->description(fn (Service $record) => static::newPrice($record)),
                TextColumn::make('next_due_date')
                    ->label('Next due date')
                    ->date(AppServiceProvider::dateFormat())
                    ->color(fn (Service $record) => $record->isOverdue() ? 'danger' : null)
                    ->sortable(),
            ])
            ->defaultSort('next_due_date')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ServiceStatus::cases())->reject(fn ($s) => $s === ServiceStatus::Cancelled)
                        ->mapWithKeys(fn (ServiceStatus $s) => [$s->value => $s->getLabel()])),
            ])
            // The chips over the table (ListServices) set it and show which one is on
            ->filtersLayout(FiltersLayout::Hidden)
            ->hiddenFilterIndicators()
            ->recordActions([])
            ->emptyStateHeading(__('No services yet'))
            ->emptyStateActions([Order::callToAction()]);
    }

    /**
     * The domain under the service's name, with a dot before it for a hosting account whose server's uptime is shown
     * (Settings → Client area → Show the server's uptime): green and pulsing while the server is up, red while down,
     * amber in maintenance; hovering it names the state and the last 30 days' uptime.
     */
    public static function domainWithState(Service $service): HtmlString|string|null
    {
        $state = static::serverState($service);
        if ($state === null) {
            return $service->domain;
        }

        return new HtmlString(sprintf(
            '<span class="gcp-live-wrap"><span class="gcp-live gcp-live-%s" title="%s" aria-label="%2$s" role="img"></span>%s</span>',
            $state['status']->value,
            e($state['text']),
            e((string) $service->domain),
        ));
    }

    /** @return array{status: MonitorStatus, text: string}|null the server's current state, when its uptime is shown and known */
    public static function serverState(Service $service): ?array
    {
        $server = $service->server;
        $status = $server?->monitor_status;

        if (! config('gaiacp.client_area.hosting_uptime') || $service->type !== ServiceType::Hosting
            || ! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)
            || blank($server->monitor_module) || $status === null || $status === MonitorStatus::Unknown) {
            return null;
        }

        $uptime = UptimeHistory::percent(UptimeHistory::for($server, '30d')['uptime']);
        $text = match ($status) {
            MonitorStatus::Up => __('Server online'),
            MonitorStatus::Down => __('Server not responding'),
            MonitorStatus::Maintenance => __('Server under maintenance'),
            MonitorStatus::Pending, MonitorStatus::Unknown => __('Server being checked'),
        };

        return [
            'status' => $status === MonitorStatus::Pending ? MonitorStatus::Maintenance : $status,
            'text' => $uptime === null ? $text : __(':state · :uptime uptime in the last 30 days', ['state' => $text, 'uptime' => $uptime]),
        ];
    }

    /**
     * The uptime of an active or suspended hosting account's server from its monitor, for the last 30 days and 24 hours
     * (Settings → Client area → Show the server's uptime); null when it is off or nothing is known yet.
     *
     * @return array{'30d': array<string, mixed>, '24h': array<string, mixed>}|null UptimeHistory::for() of each range
     */
    public static function uptime(Service $service): ?array
    {
        $server = $service->server;

        if (! config('gaiacp.client_area.hosting_uptime') || $service->type !== ServiceType::Hosting
            || ! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true) || blank($server?->monitor_module)) {
            return null;
        }

        $uptime = ['30d' => UptimeHistory::for($server, '30d'), '24h' => UptimeHistory::for($server, '24h')];

        return $uptime['30d']['uptime'] === null ? null : $uptime;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('domain')
                        ->placeholder('—'),
                    TextEntry::make('type')
                        ->badge(),
                    TextEntry::make('package')
                        ->state(fn (Service $record) => static::package($record))
                        ->placeholder('—'),
                    TextEntry::make('status')
                        ->badge(),
                    TextEntry::make('start_date')
                        ->label('Started')
                        ->date(AppServiceProvider::dateFormat()),
                    TextEntry::make('next_due_date')
                        ->label('Next due date')
                        ->date(AppServiceProvider::dateFormat())
                        ->color(fn (Service $record) => $record->isOverdue() ? 'danger' : null)
                        ->helperText(fn (Service $record) => $record->auto_terminate ? __('Ends on this date: it will not renew.') : null),
                    TextEntry::make('username')
                        ->copyable()
                        ->visible(fn (Service $record) => $record->type === ServiceType::Hosting && $record->server_id !== null && filled($record->username)),
                    TextEntry::make('price_cents')
                        ->label('Price')
                        ->state(fn (Service $record) => Money::format($record->price_cents, $record->currency))
                        ->helperText(fn (Service $record) => static::newPrice($record)),
                    TextEntry::make('billing_cycle')
                        ->label('Billing cycle'),
                    // What was picked of the product's options (not those hidden from clients); its price includes them.
                    TextEntry::make('options')
                        ->label('Options')
                        ->state(fn (Service $record) => collect($record->options ?? [])->reject(fn (array $pick) => $pick['hidden'] ?? false)
                            ->map(fn (array $pick) => ($pick['type'] ?? null) === 'yesno' ? $pick['name'] : $pick['name'].': '.$pick['value'])->values()->all())
                        ->listWithLineBreaks()
                        ->columnSpanFull()
                        ->visible(fn (Service $record) => ProductOptions::describe($record->options) !== ''),
                ]),
            Section::make(__('SSL certificate'))
                ->columns(3)
                ->columnSpanFull()
                ->visible(fn (Service $record) => $record->type === ServiceType::Ssl && $record->status !== ServiceStatus::Pending)
                ->schema(CertificateEntries::make(client: true)),
            Section::make(__('Add-ons'))
                ->columnSpanFull()
                ->visible(fn (Service $record) => $record->addons()->whereIn('status', [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended])->exists())
                ->schema([
                    TextEntry::make('addons')
                        ->hiddenLabel()
                        ->state(fn (Service $record) => OrderAddon::list($record)),
                ]),
            Section::make(__('Package change requested'))
                ->columnSpanFull()
                ->visible(fn (Service $record) => RequestUpgrade::pending($record) !== null)
                ->schema([
                    TextEntry::make('upgrade')
                        ->hiddenLabel()
                        ->state(fn (Service $record) => ($request = RequestUpgrade::pending($record)) ? __('You asked on :date to move it to :product (:price). We will confirm by email.', [
                            'date' => $request->created_at->format(AppServiceProvider::dateFormat()),
                            'product' => $request->product->name,
                            'price' => Money::format($request->price_cents, $record->currency),
                        ]) : null),
                ]),
            Section::make(__('Cancellation requested'))
                ->columnSpanFull()
                ->visible(fn (Service $record) => RequestCancellation::pending($record) !== null)
                ->schema([
                    TextEntry::make('cancellation')
                        ->hiddenLabel()
                        ->state(fn (Service $record) => self::cancellationText(RequestCancellation::pending($record))),
                ]),
            // The panel's pages, opened already signed in (cPanel, Plesk); HostingSelfService::shortcuts()
            Section::make(__('Quick shortcuts'))
                ->columnSpanFull()
                ->visible(fn (Service $record) => HostingSelfService::shortcuts($record) !== [])
                ->schema([
                    View::make('filament.client.shortcuts'),
                ]),
            // The server's uptime as the admin dashboard shows it: a square per day and per hour, lighting up in turn.
            Section::make(__('Server uptime'))
                ->columnSpanFull()
                ->visible(fn (Service $record) => static::uptime($record) !== null)
                ->schema([
                    View::make('filament.client.server-uptime')
                        ->viewData(fn (Service $record) => ['uptime' => static::uptime($record)]),
                ]),
            Section::make(__('Usage'))
                ->columnSpanFull()
                ->visible(fn (Service $record) => config('gaiacp.client_area.hosting_usage') && HostingUsage::hasUsage($record))
                ->schema([
                    Livewire::make(HostingUsage::class, fn (Service $record) => ['service' => $record, 'client' => true])
                        ->key('hostingUsage')
                        ->lazy(),
                ]),
        ]);
    }

    private static function cancellationText(?CancellationRequest $request): ?string
    {
        if (! $request) {
            return null;
        }

        return __('You asked on :date to cancel it :when. We will confirm by email.', [
            'date' => $request->created_at->format(AppServiceProvider::dateFormat()),
            'when' => $request->type === CancellationType::Immediate
                ? __('immediately')
                : __('at the end of the billing period (:date)', ['date' => $request->service->next_due_date?->format(AppServiceProvider::dateFormat())]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'view' => ViewService::route('/{record}'),
        ];
    }
}
