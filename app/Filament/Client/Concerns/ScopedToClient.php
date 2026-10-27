<?php

namespace App\Filament\Client\Concerns;

use App\Filament\Concerns\TranslatesResourceLabels;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A client-area resource: only the signed-in client's own records, read-only. getEloquentQuery() also resolves
 * the record in a URL, so another client's id is a 404. A visitor (the public area's pages, which need no login) gets
 * none at all and no global search, since where('client_id', null) would match records without a client.
 */
trait ScopedToClient
{
    use TranslatesResourceLabels;

    public static function client(): ?Client
    {
        return filament()->auth()->user()?->client;
    }

    public static function clientId(): ?int
    {
        return filament()->auth()->user()?->client_id;
    }

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        $query = static::scopeToClient(parent::getEloquentQuery());

        return static::clientId() === null ? $query->whereRaw('1 = 0') : $query;
    }

    public static function canGloballySearch(): bool
    {
        return static::clientId() !== null && parent::canGloballySearch();
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    abstract protected static function scopeToClient(Builder $query): Builder;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
