<?php

namespace App\Filament\Resources\KbArticles\Pages;

use App\Filament\Resources\KbArticles\KbArticleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKbArticle extends CreateRecord
{
    protected static string $resource = KbArticleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['created_by' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
