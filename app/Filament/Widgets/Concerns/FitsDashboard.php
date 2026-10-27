<?php

namespace App\Filament\Widgets\Concerns;

/**
 * A dashboard card (App\Support\DashboardLayout): it fills its place on the dashboard, half the width on a wide screen
 * and the whole width on a phone (resources/views/filament/dashboard/cards.blade.php).
 */
trait FitsDashboard
{
    /** The card's name under Show / hide cards and on its drag handle. */
    abstract public static function dashboardLabel(): string;

    public function getColumnSpan(): int|string|array
    {
        return 'full';
    }
}
