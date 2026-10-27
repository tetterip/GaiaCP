<?php

namespace App\Filament\Resources\KbArticles\Pages;

use App\Filament\Resources\KbArticles\KbArticleResource;
use App\Filament\Resources\KbCategories\KbCategoryResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListKbArticles extends ListRecords
{
    protected static string $resource = KbArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('categories')
                ->label('Categories')
                ->icon(Heroicon::OutlinedFolder)
                ->color('gray')
                ->url(KbCategoryResource::getUrl())
                ->visible(KbCategoryResource::canAccess()),
            CreateAction::make()->label('New article'),
        ];
    }
}
