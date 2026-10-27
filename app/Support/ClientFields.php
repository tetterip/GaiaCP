<?php

namespace App\Support;

use App\Enums\ClientFieldAccess;
use App\Enums\ClientFieldType;
use App\Models\Client;
use App\Models\ClientField;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The custom client fields of Settings → Client fields, read once per request, as form fields and as the lines a
 * payment notice shows. Values live in `clients.custom_fields` ({key: value}); a field's key never changes, so
 * values survive a label change, and a deleted field's value is kept but no longer shown.
 */
final class ClientFields
{
    /** @var Collection<int, ClientField>|null */
    private ?Collection $all = null;

    /** @return Collection<int, ClientField> in their order */
    public static function all(): Collection
    {
        $self = app(self::class);

        try {
            return $self->all ??= ClientField::query()->ordered()->get();
        } catch (Throwable) {
            return collect(); // not migrated yet
        }
    }

    public static function flush(): void
    {
        app(self::class)->all = null;
    }

    /** @return Collection<int, ClientField> the ones clients see (shown or editable) */
    public static function forClient(): Collection
    {
        return self::all()->reject(fn (ClientField $field) => $field->client_access === ClientFieldAccess::Hidden)->values();
    }

    /** @return Collection<int, ClientField> the ones clients fill in */
    public static function editable(): Collection
    {
        return self::all()->filter(fn (ClientField $field) => $field->client_access === ClientFieldAccess::Editable)->values();
    }

    /**
     * Form fields for these client fields, under `custom_fields.<key>` of the form's state.
     *
     * @param  Collection<int, ClientField>  $fields
     * @return list<Field>
     */
    public static function components(Collection $fields, bool $readOnlyUnlessEditable = false): array
    {
        return $fields->map(function (ClientField $field) use ($readOnlyUnlessEditable): Field {
            $name = "custom_fields.{$field->key}";

            $component = match ($field->type) {
                ClientFieldType::Text => TextInput::make($name)->maxLength(255),
                ClientFieldType::Textarea => Textarea::make($name)->rows(3)->maxLength(2000),
                ClientFieldType::Select => Select::make($name)->options(array_combine($field->options ?? [], $field->options ?? []))->in($field->options ?? []),
                ClientFieldType::Checkbox => Checkbox::make($name)->inline(false),
                ClientFieldType::Date => DatePicker::make($name),
            };

            $locked = $readOnlyUnlessEditable && $field->client_access !== ClientFieldAccess::Editable;

            $required = $field->required && ! $locked;
            $component = $component instanceof Checkbox ? $component->accepted($required) : $component->required($required);

            return $component
                ->label($field->label())
                ->translateLabel(false)
                ->disabled($locked)
                ->dehydrated(! $locked);
        })->values()->all();
    }

    /**
     * The values a form gave for these fields, cleaned: only their keys, blanks as null, a checkbox as true / false.
     *
     * @param  Collection<int, ClientField>  $fields
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>
     */
    public static function clean(Collection $fields, ?array $values): array
    {
        return $fields->mapWithKeys(function (ClientField $field) use ($values) {
            $value = $values[$field->key] ?? null;

            return [$field->key => match ($field->type) {
                ClientFieldType::Checkbox => (bool) $value,
                default => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value),
            }];
        })->all();
    }

    /** @return array<string, string> key => value as shown, for the fields a payment notice shows (`bill_to.custom`) */
    public static function forNotice(Client $client): array
    {
        return self::all()
            ->filter(fn (ClientField $field) => $field->on_notice)
            ->mapWithKeys(fn (ClientField $field) => [$field->key => $field->display($client->custom_fields[$field->key] ?? null)])
            ->filter(fn (?string $value) => $value !== null)
            ->all();
    }

    /**
     * A notice's custom lines in the language in use: "Label: value".
     *
     * @param  array<string, string>|null  $custom  `bill_to.custom`
     * @return list<string>
     */
    public static function noticeLines(?array $custom): array
    {
        $fields = self::all()->keyBy('key');

        return collect($custom ?? [])
            ->map(fn (string $value, string $key) => __(':label: :value', ['label' => $fields->get($key)?->label() ?? $key, 'value' => $value]))
            ->values()->all();
    }
}
