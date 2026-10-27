<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Enums\QuoteStatus;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** The open quotes first (drafts and sent), then each answer, and all. */
class ListQuotes extends ListRecords
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New quote'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('Open'))
                ->badge(Quote::query()->open()->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->open()),
            'accepted' => Tab::make(__('Accepted'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', QuoteStatus::Accepted)),
            'declined' => Tab::make(__('Declined or expired'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [QuoteStatus::Declined, QuoteStatus::Expired])),
            'all' => Tab::make(__('All')),
        ];
    }
}
