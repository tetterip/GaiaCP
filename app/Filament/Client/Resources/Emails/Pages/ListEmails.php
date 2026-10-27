<?php

namespace App\Filament\Client\Resources\Emails\Pages;

use App\Filament\Client\Resources\Emails\EmailResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListEmails extends ListRecords
{
    protected static string $resource = EmailResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('Email history');
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
