<?php

namespace Plugins\SiteChecks;

use App\Enums\AdminArea;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Services\ServiceResource;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Services → Site checks: each active hosting account's last DNS and HTTPS check (SiteCheckRunner), problems first. */
class SiteChecksPage extends Page implements HasTable
{
    use InteractsWithTable;
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Services';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Site checks';

    protected static ?string $slug = 'site-checks';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Services;
    }

    /** Hidden while the Site checks plugin is disabled (Settings → Plugins). */
    public static function canAccess(): bool
    {
        return app(PluginManager::class)->enabled('site-checks') && static::canUseAdminArea() && parent::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkAll')
                ->label('Check all now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Each site takes a moment; with many sites this can take a minute. It also runs by itself every day at 05:00.'))
                ->action(function () {
                    set_time_limit(600);
                    $summary = app(SiteCheckRunner::class)->run();

                    Notification::make()
                        ->{$summary['problems'] > 0 ? 'warning' : 'success'}()
                        ->title(__(':sites site(s) checked, :problems with a problem', ['sites' => $summary['sites'], 'problems' => $summary['problems']]))
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(SiteCheck::query()->with(['service.client', 'service.server']))
            ->defaultSort('domain')
            ->columns([
                TextColumn::make('domain')
                    ->searchable()
                    ->sortable()
                    ->url(fn (SiteCheck $record) => ServiceResource::getUrl('edit', ['record' => $record->service_id])),
                TextColumn::make('service.client.last_name')
                    ->label('Client')
                    ->formatStateUsing(fn (SiteCheck $record) => $record->service?->client?->name)
                    ->url(fn (SiteCheck $record) => $record->service?->client_id ? ClientResource::getUrl('edit', ['record' => $record->service->client_id]) : null),
                TextColumn::make('service.server.name')
                    ->label('Server'),
                TextColumn::make('dns_status')
                    ->label('DNS')
                    ->badge()
                    ->description(fn (SiteCheck $record) => $record->dns_status === DnsStatus::Ours ? null : implode(', ', $record->addresses ?? [])),
                TextColumn::make('ssl_status')
                    ->label('HTTPS')
                    ->badge()
                    ->description(fn (SiteCheck $record) => $record->ssl_valid_to
                        ? __('Until :date', ['date' => $record->ssl_valid_to->format(AppServiceProvider::dateFormat())]).($record->ssl_issuer ? " · {$record->ssl_issuer}" : '')
                        : $record->ssl_error),
                TextColumn::make('ssl_valid_to')
                    ->label('Expires')
                    ->date(AppServiceProvider::dateFormat())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('checked_at')
                    ->label('Checked')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('problems')
                    ->label('Problems only')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query) => $query->where(fn (Builder $q) => $q
                        ->whereIn('dns_status', array_map(fn (DnsStatus $s) => $s->value, array_filter(DnsStatus::cases(), fn (DnsStatus $s) => $s->isProblem())))
                        ->orWhereIn('ssl_status', array_map(fn (SslStatus $s) => $s->value, array_filter(SslStatus::cases(), fn (SslStatus $s) => $s->isProblem()))))),
            ])
            ->recordActions([
                Action::make('checkNow')
                    ->label('Check now')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->action(function (SiteCheck $record) {
                        $runner = app(SiteCheckRunner::class);
                        [$check, $new] = $runner->check($record->service);
                        $runner->tell($new);

                        Notification::make()
                            ->{$check->problems() === [] ? 'success' : 'warning'}()
                            ->title($check->domain)
                            ->body($check->dns_status->getLabel().' · '.$check->ssl_status->getLabel())
                            ->send();
                    }),
            ])
            ->emptyStateHeading(fn () => SiteCheck::query()->exists() ? __('No problems found') : __('Not checked yet'))
            ->emptyStateDescription(fn () => SiteCheck::query()->exists() ? null : __('The check runs every day at 05:00, or now with Check all now.'));
    }
}
