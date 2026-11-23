<?php

namespace App\Filament\Clusters\Reports;

use App\Filament\Clusters\Concerns\SearchesCardsInEnglishToo;
use App\Filament\Concerns\TranslatesPageLabels;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Reports (/admin/reports): a card per report, grouped like the sub-navigation every report shows on its left
 * (the same layout as Settings). A report joins by setting `$cluster` to this class, a `$navigationGroup` from
 * GROUPS and implementing ReportCard for its description.
 */
class ReportsCluster extends Cluster
{
    use SearchesCardsInEnglishToo;
    use TranslatesPageLabels;

    /** Sub-navigation and card groups, in order. */
    public const GROUPS = ['Questions', 'Income', 'Services', 'Sales'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'Reports';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    protected string $view = 'filament.settings-overview';

    /** Only for an admin who can open at least one page in it. */
    public static function canAccess(): bool
    {
        return static::canAccessClusteredComponents();
    }

    public function mount(): void
    {
        // Show the cards instead of redirecting to the first report.
    }

    /** The breadcrumb above each page in the cluster, in the admin's language. */
    public static function getClusterBreadcrumb(): ?string
    {
        return __((string) parent::getClusterBreadcrumb());
    }

    public function getSubNavigation(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        return __('Income received and expected, and lists of domains and services to filter and export.');
    }

    /**
     * What the search box matches for a card: its group, name and description in the admin's language and in English.
     *
     * @param  string  $component  a report page implementing ReportCard
     */
    public static function searchText(string $component): string
    {
        return static::inAdminLanguageAndEnglish(fn (): string => implode(' ', [
            (string) $component::getNavigationGroup(),
            $component::getNavigationLabel(),
            $component::reportDescription(),
        ]));
    }

    /** The cards by group, each with label, description, icon, url and search (the texts the search box matches). */
    public function cards(): Collection
    {
        return collect(static::getClusteredComponents())
            ->filter(fn (string $component) => is_subclass_of($component, ReportCard::class) && $component::canAccess())
            ->sortBy(fn (string $component) => $component::getNavigationSort())
            ->map(fn (string $component) => [
                'group' => $component::getNavigationGroup(),
                'label' => $component::getNavigationLabel(),
                'description' => $component::reportDescription(),
                'icon' => $component::getNavigationIcon(),
                'url' => $component::getUrl(),
                'search' => static::searchText($component),
            ])
            ->groupBy('group')
            ->sortBy(fn ($cards, string $group) => array_search($group, array_map(fn (string $g) => __($g), self::GROUPS), true));
    }
}
