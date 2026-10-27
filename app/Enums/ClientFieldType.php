<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** The kind of a custom client field (Settings → Client fields). */
enum ClientFieldType: string implements HasLabel
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Select = 'select';
    case Checkbox = 'checkbox';
    case Date = 'date';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Text => 'Text',
            self::Textarea => 'Text on several lines',
            self::Select => 'List of choices',
            self::Checkbox => 'Yes / no',
            self::Date => 'Date',
        });
    }
}
