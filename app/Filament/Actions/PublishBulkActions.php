<?php

namespace App\Filament\Actions;

use App\Models\Announcement;
use App\Models\KbArticle;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Support\Icons\Heroicon;

/** Publish / Unpublish / Delete on the announcements or knowledge base articles picked in a list. */
class PublishBulkActions
{
    public const MAX = 100;

    /** Publish now the drafts and scheduled ones; Unpublish makes them drafts again. */
    public static function announcements(): BulkActionGroup
    {
        return BulkActionGroup::make([
            SimpleBulkAction::make('publish', 'Publish now', Heroicon::OutlinedEye,
                fn (Announcement $a) => $a->published_at !== null && $a->published_at->lte(now()) ? __('already published') : null,
                fn (Announcement $a) => $a->update(['published_at' => now()]),
                fn (Announcement $a) => $a->title(),
                ':count announcement published|:count announcements published'),
            SimpleBulkAction::make('unpublish', 'Unpublish', Heroicon::OutlinedEyeSlash,
                fn (Announcement $a) => $a->published_at === null ? __('a draft already') : null,
                fn (Announcement $a) => $a->update(['published_at' => null]),
                fn (Announcement $a) => $a->title(),
                ':count announcement unpublished|:count announcements unpublished',
                __('They become drafts: clients no longer see them.')),
            DeleteBulkAction::make(),
        ]);
    }

    public static function articles(): BulkActionGroup
    {
        return BulkActionGroup::make([
            SimpleBulkAction::make('publish', 'Publish', Heroicon::OutlinedEye,
                fn (KbArticle $a) => $a->published ? __('already published') : null,
                fn (KbArticle $a) => $a->update(['published' => true]),
                fn (KbArticle $a) => $a->title(),
                ':count article published|:count articles published'),
            SimpleBulkAction::make('unpublish', 'Unpublish', Heroicon::OutlinedEyeSlash,
                fn (KbArticle $a) => $a->published ? null : __('not published'),
                fn (KbArticle $a) => $a->update(['published' => false]),
                fn (KbArticle $a) => $a->title(),
                ':count article unpublished|:count articles unpublished',
                __('Clients no longer see them.')),
            DeleteBulkAction::make(),
        ]);
    }
}
