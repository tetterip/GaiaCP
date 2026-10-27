<?php

namespace App\Filament\Resources\KbArticles;

use App\Support\Locales;
use Filament\Forms\Components\TextInput;

/** A knowledge base category's fields: its name in each language (the default one required) and its order. */
class KbCategoryForm
{
    /** @return list<TextInput> */
    public static function fields(): array
    {
        return [
            ...collect(Locales::available())->map(fn (string $name, string $locale) => TextInput::make("names.{$locale}")
                ->label(Locales::multiple() ? __('Name (:language)', ['language' => $name]) : __('Name'))
                ->maxLength(100)
                ->required(Locales::isDefault($locale)))->values()->all(),
            TextInput::make('sort')
                ->label('Position')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0),
        ];
    }
}
