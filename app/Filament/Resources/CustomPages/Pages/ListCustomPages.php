<?php

namespace App\Filament\Resources\CustomPages\Pages;

use App\Filament\Resources\CustomPages\CustomPageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListCustomPages extends ListRecords
{
    protected static string $resource = CustomPageResource::class;

    public function getSubheading(): Htmlable
    {
        return CustomPageResource::menusHint();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New page'),
        ];
    }
}
