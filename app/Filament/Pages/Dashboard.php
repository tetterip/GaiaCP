<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\TranslatesPageLabels;
use App\Support\DashboardLayout;
use App\Support\Greeting;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * Filament's dashboard, first in the sidebar's Main group. Open to every admin; its widgets check their own areas.
 * Two columns of cards that each fill down on their own, one after the other on a phone (resources/views/filament/
 * dashboard/cards.blade.php). Each admin drags the cards within and between the columns there and hides the ones they
 * don't need under Show / hide cards (App\Support\DashboardLayout).
 */
class Dashboard extends BaseDashboard
{
    use TranslatesPageLabels;

    protected static string|UnitEnum|null $navigationGroup = 'Main';

    protected static ?int $navigationSort = -2;

    public function getColumns(): int|array
    {
        return ['default' => 1, 'md' => 2];
    }

    /** @return list<class-string> the admin's cards, the first column's then the second's */
    public function getWidgets(): array
    {
        $user = filament()->auth()->user();

        return $user ? array_values(array_merge(...DashboardLayout::shown($user))) : parent::getWidgets();
    }

    public function getWidgetsContentComponent(): Component
    {
        return View::make('filament.dashboard.cards')
            ->viewData(fn (): array => ['columns' => collect(($user = filament()->auth()->user()) ? DashboardLayout::shown($user) : [1 => [], 2 => []])
                ->map(fn (array $widgets) => collect($widgets)
                    ->filter(fn (string $widget) => $widget::canView())
                    ->map(fn (string $widget, string $key) => [
                        'class' => $widget,
                        'label' => $widget::dashboardLabel(),
                        'data' => [...$this->getWidgetData(), ...$widget::getDefaultProperties()],
                    ])
                    ->all())
                ->all()]);
    }

    /**
     * The cards' new columns and order after the admin dragged one (or moved it with the arrow keys on its handle).
     *
     * @param  array<int|string, list<string>>  $columns
     */
    public function reorderDashboard(array $columns): void
    {
        DashboardLayout::reorder(filament()->auth()->user(), $columns);
        $this->skipRender();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('arrangeDashboard')
                ->label('Show / hide cards')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->modalWidth(Width::Medium)
                ->modalDescription(__('Pick the cards to show. To change their order, drag a card by its handle on the dashboard. Only you see this layout.'))
                ->modalSubmitActionLabel(__('Save'))
                ->fillForm(fn () => ['cards' => collect($this->arrangeableRows())->mapWithKeys(fn (array $row) => [$row['widget'] => $row['visible']])->all()])
                ->schema(fn () => collect($this->arrangeableRows())
                    ->map(fn (array $row) => Toggle::make('cards.'.$row['widget'])
                        ->label(DashboardLayout::widgets()[$row['widget']]::dashboardLabel()))
                    ->all())
                ->extraModalFooterActions([
                    Action::make('resetDashboard')
                        ->label('Back to the default')
                        ->color('gray')
                        ->cancelParentActions()
                        ->action(function () {
                            DashboardLayout::reset(filament()->auth()->user());
                            $this->redirect(static::getUrl());
                        }),
                ])
                ->action(function (array $data) {
                    $allowed = array_column($this->arrangeableRows(), 'widget');
                    DashboardLayout::show(filament()->auth()->user(), array_intersect_key((array) ($data['cards'] ?? []), array_flip($allowed)));
                    Notification::make()->success()->title(__('Saved'))->send();
                    $this->redirect(static::getUrl());
                }),
        ];
    }

    /** @return list<array{widget: string, visible: bool}> the cards this admin may see */
    private function arrangeableRows(): array
    {
        $widgets = DashboardLayout::widgets();

        return collect(DashboardLayout::rows(filament()->auth()->user()))
            ->filter(fn (array $row) => $widgets[$row['widget']]::canView())
            ->values()
            ->all();
    }

    public function getHeading(): string|Htmlable
    {
        $name = str(filament()->auth()->user()?->name ?? '')->before(' ')->toString();
        if ($name === '') {
            return $this->getTitle();
        }

        return Greeting::for($name);
    }

    public function getSubheading(): ?string
    {
        return __(':date · what needs your attention today', ['date' => now()->locale(app()->getLocale())->translatedFormat('l j F')]);
    }
}
