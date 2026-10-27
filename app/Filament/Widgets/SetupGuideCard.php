<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\SetupGuide;
use App\Filament\Widgets\Concerns\FitsDashboard;
use App\Support\SetupGuide as Guide;
use Filament\Widgets\Widget;

/** At the top of the dashboard for super admins, until every setup step is done or skipped or they hide it: how far the setup guide is and the next step. */
class SetupGuideCard extends Widget
{
    use FitsDashboard;

    protected static bool $isLazy = false;

    protected static ?int $sort = 0;

    protected string $view = 'filament.dashboard.setup-guide';

    public static function dashboardLabel(): string
    {
        return __('Finish setting up GaiaCP');
    }

    public static function canView(): bool
    {
        return SetupGuide::canAccess() && Guide::showOnDashboard();
    }

    public function hide(): void
    {
        Guide::hide();
        $this->redirect(filament()->getUrl(), navigate: false);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $steps = Guide::steps();

        return [
            'url' => SetupGuide::getUrl(),
            'done' => $steps->whereNotNull('status')->count(),
            'total' => $steps->count(),
            'next' => $steps->firstWhere('status', null),
        ];
    }
}
