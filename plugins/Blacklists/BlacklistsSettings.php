<?php

namespace Plugins\Blacklists;

use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Providers\AppServiceProvider;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/** The DNS blacklists to ask, Check now, and what is listed right now. Super admins only, like Servers. */
class BlacklistsSettings extends SettingsFormPage
{
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 75;

    protected static ?string $title = 'IP blacklist check';

    protected static ?string $slug = 'ip-blacklist-check';

    public static function settingsDescription(): string
    {
        return __('Checks your servers\' IP addresses against spam blacklists every hour and tells you in the bell when one is listed, before clients find their emails in spam.');
    }

    public static function pluginId(): string
    {
        return 'blacklists';
    }

    public static function keys(): array
    {
        return (new BlacklistsPlugin)->settingKeys();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkNow')
                ->label('Check now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function () {
                    $summary = app(BlacklistCheck::class)->run();

                    Notification::make()
                        ->{$summary['listed'] > 0 ? 'danger' : 'success'}()
                        ->title($summary['listed'] > 0
                            ? __(':count listing(s) found', ['count' => $summary['listed']])
                            : __('No server is listed'))
                        ->body(__(':ips IP address(es) of :servers server(s) checked.', ['ips' => $summary['ips'], 'servers' => $summary['servers']])
                            .($summary['refused'] !== [] ? ' '.__('Did not answer: :zones.', ['zones' => implode(', ', $summary['refused'])]) : ''))
                        ->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Listed now'))
                    ->schema(fn () => [Text::make(self::listedText())]),
                Section::make(__('Blacklists'))
                    ->description(__('Every hour GaiaCP asks each list about every active server\'s IP address (the server\'s IP address field, else what its hostname points to). A new listing and its removal are in the bell for super admins and in the activity log.'))
                    ->schema([
                        Textarea::make('blacklists.zones')
                            ->label('DNS blacklists')
                            ->rows(6)
                            ->maxLength(2000)
                            ->required()
                            ->helperText(__('One per line, e.g. zen.spamhaus.org. Spamhaus answers only through a DNS resolver of your server\'s own, not a public one like 8.8.8.8; when a list refuses to answer it counts as not checked, never as listed.')),
                    ]),
            ]);
    }

    private static function listedText(): string
    {
        $listings = BlacklistCheck::listings();
        $last = Cache::get(BlacklistCheck::LAST_RUN);
        $checked = $last ? __('Last check: :time.', ['time' => CarbonImmutable::parse($last['at'])->timezone(config('app.timezone'))->format(AppServiceProvider::dateFormat().' H:i')]) : __('Not checked yet.');

        if ($listings->isEmpty()) {
            return __('No server is listed.').' '.$checked;
        }

        return $listings->map(fn (BlacklistListing $l) => __(':server (:ip) on :zone since :date', [
            'server' => $l->server?->name,
            'ip' => $l->ip,
            'zone' => $l->zone,
            'date' => $l->listed_at->timezone(config('app.timezone'))->format(AppServiceProvider::dateFormat().' H:i'),
        ]))->join(' · ').'. '.$checked;
    }
}
