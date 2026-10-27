<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Actions\Quotes;
use App\Filament\Resources\Quotes\QuoteResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** A new quote, for the lead or client in the link (?lead= / ?client=, from their page) or picked here. */
class CreateQuote extends CreateRecord
{
    protected static string $resource = QuoteResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return app(Quotes::class)->create($data);
    }
}
