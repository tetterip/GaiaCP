<?php

namespace App\Filament\Client\Resources\Quotes\Pages;

use App\Filament\Client\Resources\Quotes\QuoteResource;
use Filament\Resources\Pages\ListRecords;

class ListQuotes extends ListRecords
{
    protected static string $resource = QuoteResource::class;
}
