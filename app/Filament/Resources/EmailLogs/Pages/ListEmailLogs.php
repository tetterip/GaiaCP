<?php

namespace App\Filament\Resources\EmailLogs\Pages;

use App\Filament\Resources\EmailLogs\EmailLogResource;
use Filament\Resources\Pages\ListRecords;

class ListEmailLogs extends ListRecords
{
    protected static string $resource = EmailLogResource::class;

    public function getSubheading(): ?string
    {
        return __('Kept for :days days (Settings → Logging).', ['days' => (int) config('gaiacp.debug.email_log_days')]);
    }
}
