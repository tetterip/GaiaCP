<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Filament\Pages\Plugins;
use App\Plugins\Plugin;
use App\Plugins\PluginManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Settings → Payment methods: every payment plugin (Bank transfer, Stripe, ...), each opening its own settings
 * page. Those pages sit under this one in the sub-navigation (PaymentMethodPage) and have no card of their own.
 */
class PaymentMethodsSettings extends Page implements HasTable, SettingsCard
{
    use InteractsWithTable;
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Payment methods';

    protected static ?string $slug = 'payment-methods';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::BusinessSettings;
    }

    public static function settingsDescription(): string
    {
        return __('How clients pay: bank transfer, card payments with Stripe and any other payment plugin.');
    }

    public function getSubheading(): ?string
    {
        return __('Payments you record by hand can also be Cash or Other. Turn a method on or off with the switch on its page.');
    }

    private function plugins(): PluginManager
    {
        return app(PluginManager::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->plugins()->all()
                ->filter(fn (Plugin $plugin) => filled($plugin->paymentModules()))
                ->map(fn (Plugin $plugin) => [
                    'id' => $plugin->id(),
                    'name' => $plugin->name(),
                    'description' => $plugin->description(),
                    'state' => $this->plugins()->state($plugin),
                ])
                ->all())
            ->columns([
                TextColumn::make('name')
                    ->label('Payment method')
                    ->weight('medium')
                    ->description(fn (array $record) => $record['description'])
                    ->wrap(),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->grow(false),
            ])
            ->paginated(false)
            ->recordUrl(fn (array $record) => $this->settingsUrl($record))
            ->recordActions([
                Action::make('settings')
                    ->iconButton()
                    ->tooltip(__('Settings'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->color('gray')
                    ->visible(fn (array $record) => $this->settingsUrl($record) !== null)
                    ->url(fn (array $record) => $this->settingsUrl($record)),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('plugins')
                ->label('Plugins')
                ->icon(Heroicon::OutlinedPuzzlePiece)
                ->color('gray')
                ->visible(fn () => Plugins::canAccess())
                ->url(fn () => Plugins::getUrl()),
        ];
    }

    /** The method's own settings page (with its On switch), when this admin may open it. */
    private function settingsUrl(array $record): ?string
    {
        $page = $this->plugins()->find($record['id'])?->settingsPage();

        return $page && $page::canAccess() ? $page::getUrl() : null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
