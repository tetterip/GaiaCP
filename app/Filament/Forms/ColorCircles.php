<?php

namespace App\Filament\Forms;

use App\Models\ProductTag;
use Filament\Forms\Components\ViewField;
use Illuminate\Validation\Rule;

/** The colour picker of product tags and products: a circle for each of ProductTag::COLORS, with "None" when optional. */
class ColorCircles
{
    public static function make(string $name = 'color', bool $optional = false): ViewField
    {
        $colors = collect(ProductTag::colorOptions())
            ->map(fn (string $label, string $key) => ['label' => $label, 'hex' => ProductTag::COLORS[$key]])
            ->all();

        return ViewField::make($name)
            ->view('filament.forms.color-circles')
            ->viewData(['colors' => $colors, 'optional' => $optional])
            ->rules([$optional ? 'nullable' : 'required', Rule::in(array_keys($colors))]);
    }
}
