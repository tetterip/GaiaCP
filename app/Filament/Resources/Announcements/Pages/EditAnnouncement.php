<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Client\Pages\AnnouncementPage;
use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Models\Announcement;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label('Open in the client area')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (Announcement $record) => $record->published_at?->isPast() ?? false)
                ->url(fn (Announcement $record) => AnnouncementPage::getUrl(['slug' => $record->slug], panel: 'client'), shouldOpenInNewTab: true),
            DeleteAction::make(),
        ];
    }
}
