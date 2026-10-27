<?php

namespace App\Support;

use App\Models\User;
use Filament\Facades\Filament;

/**
 * Each admin's dashboard: two columns of cards, each column filling down on its own (masonry), kept in
 * `users.dashboard_layout` as [{widget, column, visible}] in order. Cards are dragged within and between the columns on
 * the dashboard and shown or hidden under Show / hide cards; a phone shows the first column, then the second. A card added
 * later goes to the end of the column with fewer cards; one an admin may not open (its canView()) never shows.
 */
class DashboardLayout
{
    /** The cards that start in the second column; the rest start in the first, so a phone shows the most urgent first. */
    public const SECOND_COLUMN = ['ActivityChart', 'UpcomingRenewals', 'SupportTickets'];

    /** @return array<string, class-string> key => widget class, in their default order */
    public static function widgets(): array
    {
        return collect(Filament::getPanel('admin')->getWidgets())
            ->filter(fn (string $widget) => method_exists($widget, 'dashboardLabel'))
            ->sortBy(fn (string $widget) => $widget::getSort())
            ->mapWithKeys(fn (string $widget) => [class_basename($widget) => $widget])
            ->all();
    }

    /** @return list<array{widget: string, column: int, visible: bool}> the admin's cards, theirs first, then any new ones */
    public static function rows(User $user): array
    {
        $widgets = self::widgets();
        $saved = filled($user->dashboard_layout);
        $rows = collect((array) $user->dashboard_layout)
            ->filter(fn ($row) => is_array($row) && isset($widgets[$row['widget'] ?? '']))
            ->unique('widget')
            ->map(fn (array $row) => [
                'widget' => (string) $row['widget'],
                'column' => (int) ($row['column'] ?? 1) === 2 ? 2 : 1,
                'visible' => (bool) ($row['visible'] ?? true),
            ]);

        foreach (array_keys($widgets) as $key) {
            if (! $rows->contains('widget', $key)) {
                $column = $saved
                    ? ($rows->where('column', 2)->count() < $rows->where('column', 1)->count() ? 2 : 1)
                    : (in_array($key, self::SECOND_COLUMN, true) ? 2 : 1);
                $rows->push(['widget' => $key, 'column' => $column, 'visible' => true]);
            }
        }

        return $rows->values()->all();
    }

    /** @return array{1: array<string, class-string>, 2: array<string, class-string>} key => widget class of the cards to show, per column */
    public static function shown(User $user): array
    {
        $widgets = self::widgets();
        $columns = [1 => [], 2 => []];

        foreach (self::rows($user) as $row) {
            if ($row['visible']) {
                $columns[$row['column']][$row['widget']] = $widgets[$row['widget']];
            }
        }

        return $columns;
    }

    /**
     * Saves which cards show, as Show / hide cards sent them (only those the admin can see); the order stays as it is.
     *
     * @param  array<string, bool>  $visible  widget key => shown
     */
    public static function show(User $user, array $visible): void
    {
        self::store($user, collect(self::rows($user))
            ->map(fn (array $row) => [...$row, 'visible' => (bool) ($visible[$row['widget']] ?? $row['visible'])])
            ->all());
    }

    /**
     * Puts the cards dragged on the dashboard in their new columns and order. Within each column they take the places
     * the column's cards had, so the hidden ones and the ones the admin may not open keep theirs; unknown keys are
     * ignored.
     *
     * @param  array<int|string, list<string>>  $columns  [1 => widget keys, 2 => widget keys]
     */
    public static function reorder(User $user, array $columns): void
    {
        $rows = collect(self::rows($user))->keyBy('widget');
        $sent = [];

        foreach ([1, 2] as $column) {
            foreach ((array) ($columns[$column] ?? []) as $key) {
                $key = (string) $key;
                if ($rows->has($key) && ! isset($sent[$key])) {
                    $sent[$key] = $column;
                }
            }
        }

        // The sent cards, column by column, fill the places they had between the cards that were not sent.
        $order = array_keys($sent);
        $next = 0;
        $result = $rows->values()->map(function (array $row) use ($sent, $order, &$next, $rows) {
            if (! isset($sent[$row['widget']])) {
                return $row;
            }
            $key = $order[$next++];

            return [...$rows[$key], 'column' => $sent[$key]];
        });

        self::store($user, $result->all());
    }

    public static function reset(User $user): void
    {
        $user->forceFill(['dashboard_layout' => null])->save();
    }

    /** @param  list<array{widget: string, column: int, visible: bool}>  $rows */
    private static function store(User $user, array $rows): void
    {
        $user->forceFill(['dashboard_layout' => array_values($rows)])->save();
    }
}
