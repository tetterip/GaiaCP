<?php

namespace App\Models;

use App\Enums\ClientFieldAccess;
use App\Enums\ClientFieldType;
use App\Providers\AppServiceProvider;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * A custom client field (Settings → Client fields, App\Support\ClientFields): its value is `clients.custom_fields[key]`.
 *
 * @property array<string, string|null> $labels by language
 * @property list<string>|null $options the choices of a list
 */
#[Fillable(['key', 'labels', 'type', 'options', 'required', 'client_access', 'on_notice', 'sort'])]
class ClientField extends Model
{
    protected $attributes = [
        'type' => 'text',
        'client_access' => 'editable',
        'required' => false,
        'on_notice' => false,
        'sort' => 0,
    ];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'options' => 'array',
            'type' => ClientFieldType::class,
            'client_access' => ClientFieldAccess::class,
            'required' => 'boolean',
            'on_notice' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** @param Builder<ClientField> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }

    /** The label in a language, else the default language's, else the key. */
    public function label(?string $locale = null): string
    {
        $labels = $this->labels ?? [];

        return ($labels[Locales::normalize($locale ?? app()->getLocale())] ?? null)
            ?: ($labels[Locales::default()] ?? null)
            ?: (collect($labels)->filter()->first() ?? $this->key);
    }

    /** A value as people read it: "Yes" / "No", a date in Settings → General's format; null when empty. */
    public function display(mixed $value): ?string
    {
        if ($this->type === ClientFieldType::Checkbox) {
            return $value === null ? null : ($value ? __('Yes') : __('No'));
        }

        if ($value === null || $value === '') {
            return null;
        }

        if ($this->type === ClientFieldType::Date) {
            try {
                return CarbonImmutable::parse((string) $value)->format(AppServiceProvider::dateFormat());
            } catch (Throwable) {
                return (string) $value;
            }
        }

        return (string) $value;
    }
}
