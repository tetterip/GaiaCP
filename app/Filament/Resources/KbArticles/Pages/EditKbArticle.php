<?php

namespace App\Filament\Resources\KbArticles\Pages;

use App\Filament\Client\Pages\KnowledgeBaseArticle;
use App\Filament\Resources\KbArticles\KbArticleResource;
use App\Models\KbArticle;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditKbArticle extends EditRecord
{
    protected static string $resource = KbArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label('Open in the client area')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (KbArticle $record) => $record->published)
                ->url(fn (KbArticle $record) => KnowledgeBaseArticle::getUrl(['slug' => $record->slug], panel: 'client'), shouldOpenInNewTab: true),
            DeleteAction::make(),
        ];
    }
}
