<?php

namespace App\Filament\Clusters\Settings;

use App\Filament\Concerns\TranslatesPageLabels;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Settings (/admin/settings): a grid of cards, one per settings page, grouped like the sub-navigation that every
 * page in the cluster shows on its left. A page or resource joins by setting `$cluster` to this class, a
 * `$navigationGroup` from GROUPS and implementing SettingsCard for its description.
 */
class SettingsCluster extends Cluster
{
    use TranslatesPageLabels;

    /** Sub-navigation and card groups, in order. A page's navigationSort keeps it inside its group's range. */
    public const GROUPS = ['General', 'Billing', 'Products & domains', 'Client area', 'Support', 'Email & notifications', 'Integrations', 'AI assistant', 'Security', 'System', 'Logs'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 100;

    protected static ?string $title = 'Settings';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    protected string $view = 'filament.settings-overview';

    /** Only for an admin who can open at least one page in it. */
    public static function canAccess(): bool
    {
        return static::canAccessClusteredComponents();
    }

    public function mount(): void
    {
        // Show the cards instead of redirecting to the first page.
    }

    /** The breadcrumb above each page in the cluster, in the admin's language. */
    public static function getClusterBreadcrumb(): ?string
    {
        return __((string) parent::getClusterBreadcrumb());
    }

    public function getSubNavigation(): array
    {
        return []; // the cards are the navigation here
    }

    public function getSubheading(): ?string
    {
        return __('Your business, billing, products, client area, support, email, integrations and the system.');
    }

    /** @return Collection<string, Collection<int, array{label: string, description: string, icon: mixed, url: string}>> */
    public function cards(): Collection
    {
        return collect(static::getClusteredComponents())
            ->filter(fn (string $component) => is_subclass_of($component, SettingsCard::class) && $component::canAccess())
            ->reject(fn (string $component) => filled($component::getNavigationParentItem())) // shown on its parent's page
            ->sortBy(fn (string $component) => $component::getNavigationSort())
            ->map(fn (string $component) => [
                'group' => $component::getNavigationGroup(),
                'label' => $component::getNavigationLabel(),
                'description' => $component::settingsDescription(),
                'icon' => $component::getNavigationIcon(),
                'url' => $component::getUrl(),
            ])
            ->groupBy('group')
            ->sortBy(fn ($cards, string $group) => array_search($group, array_map(fn (string $g) => __($g), self::GROUPS), true));
    }
}
