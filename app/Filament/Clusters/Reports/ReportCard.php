<?php

namespace App\Filament\Clusters\Reports;

/** A report shown as a card on the Reports page. */
interface ReportCard
{
    /** One line under the card's title: what the report shows. */
    public static function reportDescription(): string;
}
