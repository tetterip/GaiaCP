<?php

namespace App\Filament\Resources\CustomPages\Pages;

use App\Filament\Client\Pages\CustomPageView;
use App\Filament\Resources\CustomPages\CustomPageResource;
use App\Models\CustomPage;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class EditCustomPage extends EditRecord
{
    protected static string $resource = CustomPageResource::class;

    public function getSubheading(): Htmlable
    {
        return CustomPageResource::menusHint();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label('Open in the client area')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (CustomPage $record) => $record->published)
                ->url(fn (CustomPage $record) => CustomPageView::urlFor($record), shouldOpenInNewTab: true),
            DeleteAction::make(),
        ];
    }
}
