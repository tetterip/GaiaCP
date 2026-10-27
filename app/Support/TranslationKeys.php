<?php

namespace App\Support;

use App\Enums\MailTemplate;
use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\KeyboardShortcuts;
use App\Notifications\ResetAdminPassword;
use App\Plugins\Plugin;
use App\Plugins\PluginManager;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpToken;
use ReflectionClass;
use ReflectionProperty;

use function Filament\Support\get_model_label;

/**
 * Every English text a language file can translate (the keys of lang/<code>.json, and of each plugin's
 * plugins/<Name>/lang/<code>.json for its own): the __() calls in app/ and resources/views (and the plugin's
 * folder), the Filament labels (written ones and the ones made from field names), and the labels built at run
 * time (enums, menu groups, shortcuts). Used by `gaiacp:translations`.
 */
final class TranslationKeys
{
    /** Filament makes a label from these components' names when none is written (`first_name` → "First name"). */
    private const NAMED_COMPONENTS = ['TextInput', 'Textarea', 'Select', 'Toggle', 'Checkbox', 'DatePicker', 'DateTimePicker',
        'TagsInput', 'Radio', 'MarkdownEditor', 'RichEditor', 'Repeater', 'KeyValue', 'CheckboxList', 'ToggleButtons',
        'TextColumn', 'IconColumn', 'ToggleColumn', 'TextEntry', 'IconEntry', 'KeyValueEntry', 'RepeatableEntry',
        'SelectFilter', 'TernaryFilter', 'Filter'];

    /** @return list<string> sorted: the core's texts and every plugin's */
    public static function all(): array
    {
        $keys = self::core();

        foreach (app(PluginManager::class)->all() as $plugin) {
            array_push($keys, ...self::forPlugin($plugin));
        }

        return self::clean($keys);
    }

    /** @return list<string> sorted: the texts of app/ and resources/views, which lang/<code>.json translates */
    public static function core(): array
    {
        $keys = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() === 'php') {
                array_push($keys, ...self::fromPhp($file->getContents()));
            }
        }

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                array_push($keys, ...self::fromBlade($file->getContents()));
            }
        }

        array_push($keys, ...self::runtime());

        return self::clean($keys);
    }

    /** @return list<string> sorted: a plugin's own texts, which its lang/<code>.json translates (the app's file wins) */
    public static function forPlugin(Plugin $plugin): array
    {
        $keys = [];

        foreach (File::allFiles($plugin->path()) as $file) {
            if ($file->getExtension() === 'php') {
                array_push($keys, ...self::fromPhp($file->getContents()));
            }
        }

        array_push($keys, ...self::filamentLabels($plugin->pages()));

        return self::clean($keys);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function clean(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys, fn (string $key) => preg_match('/\pL{2,}/u', $key) === 1)));
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    public static function fromPhp(string $code): array
    {
        $tokens = PhpToken::tokenize($code);
        $keys = [];

        foreach ($tokens as $i => $token) {
            if (! $token->is(T_STRING)) {
                continue;
            }

            $previous = self::significant($tokens, $i, -1);
            $isLabel = $token->text === 'label' && $previous !== null && $tokens[$previous]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]);
            $isMake = $token->text === 'make' && $previous !== null && $tokens[$previous]->is(T_DOUBLE_COLON)
                && in_array($tokens[self::significant($tokens, $previous, -1)]->text, self::NAMED_COMPONENTS, true);

            if ($token->text !== '__' && ! $isLabel && ! $isMake) {
                continue;
            }

            $open = self::significant($tokens, $i, 1);
            if ($open === null || $tokens[$open]->text !== '(') {
                continue;
            }

            $text = self::literal($tokens, $open);
            if ($text === null) {
                continue;
            }

            $keys[] = $isMake ? self::labelFromName($text) : $text;
        }

        return $keys;
    }

    /** @return list<string> */
    public static function fromBlade(string $view): array
    {
        preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*[,)]/", $view, $matches);

        return array_map(fn (string $key) => stripslashes($key), $matches[1]);
    }

    /** "first_name" → "First name", "aade.username" → "Username", as Filament does. */
    public static function labelFromName(string $name): string
    {
        return (string) Str::of($name)->afterLast('.')->kebab()->replace(['-', '_'], ' ')->ucfirst();
    }

    /** @return list<string> labels only known once the code runs */
    private static function runtime(): array
    {
        $keys = [...SettingsCluster::GROUPS, ...ReportsCluster::GROUPS, ...MailTemplate::GROUPS, ...ResetAdminPassword::VIEW_TEXTS, ...self::filamentLabels()];

        foreach (File::files(app_path('Enums')) as $file) {
            $enum = 'App\\Enums\\'.$file->getFilenameWithoutExtension();

            if (! is_subclass_of($enum, BackedEnum::class)) {
                continue;
            }

            foreach ($enum::cases() as $case) {
                if ($case instanceof HasLabel) {
                    $keys[] = (string) self::untranslated(fn () => $case->getLabel());
                }
            }
        }

        foreach (MailTemplate::cases() as $template) {
            $keys[] = self::untranslated(fn () => $template->label());
            $keys[] = self::untranslated(fn () => $template->sentWhen());
            array_push($keys, ...array_values($template->placeholders()));
        }

        foreach (self::untranslated(fn () => KeyboardShortcuts::sequences()) as $group => $items) {
            $keys[] = $group;
            array_push($keys, ...array_column($items, 0));
        }

        // In English whatever the admin's language, like the sequences above
        return [...$keys, ...array_values(self::untranslated(fn () => KeyboardShortcuts::general()))];
    }

    /**
     * Page titles, menu labels and record names, which Filament classes keep in static properties
     * or derive from the model name (translated by the App\Filament\Concerns\Translates* traits).
     *
     * @return list<string>
     */
    /** @param  list<class-string>|null  $classes  null = every class in app/Filament */
    private static function filamentLabels(?array $classes = null): array
    {
        $keys = [];
        $classes ??= array_map(fn ($file) => 'App\\Filament\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()), File::allFiles(app_path('Filament')));

        foreach ($classes as $class) {
            if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            foreach (['title', 'navigationLabel', 'modelLabel', 'pluralModelLabel', 'navigationGroup', 'heading'] as $property) {
                if (property_exists($class, $property) && (new ReflectionProperty($class, $property))->isStatic()) {
                    $value = (new ReflectionProperty($class, $property))->getValue();
                    if (is_string($value)) {
                        $keys[] = $value;
                    }
                }
            }

            if (is_subclass_of($class, Resource::class)) {
                $model = (new ReflectionProperty($class, 'modelLabel'))->getValue() ?? get_model_label($class::getModel());
                $plural = (new ReflectionProperty($class, 'pluralModelLabel'))->getValue() ?? Str::plural($model);
                array_push($keys, $model, $plural, Str::ucwords($plural));
            }

            if (is_subclass_of($class, RelationManager::class) && (new ReflectionProperty($class, 'title'))->getValue() === null) {
                $keys[] = Str::headline((new ReflectionProperty($class, 'relationship'))->getValue());
            }
        }

        return $keys;
    }

    /** The English text, whatever the current language. */
    private static function untranslated(callable $callback): mixed
    {
        // English, the keys' language, even where the default language is another one
        $original = app()->getLocale();
        app()->setLocale('en');

        try {
            return $callback();
        } finally {
            app()->setLocale($original);
        }
    }

    /** The string literal (or literals joined with ".") right after the "(" at $open, else null. */
    private static function literal(array $tokens, int $open): ?string
    {
        $text = '';
        $i = $open;

        while (true) {
            $i = self::significant($tokens, $i, 1);
            if ($i === null || ! $tokens[$i]->is(T_CONSTANT_ENCAPSED_STRING)) {
                return null;
            }

            $literal = $tokens[$i]->text;
            $text .= $literal[0] === "'"
                ? strtr(substr($literal, 1, -1), ['\\\\' => '\\', "\\'" => "'"])
                : stripcslashes(substr($literal, 1, -1));

            $i = self::significant($tokens, $i, 1);
            if ($i === null) {
                return null;
            }
            if (in_array($tokens[$i]->text, [')', ','], true)) {
                return $text;
            }
            if ($tokens[$i]->text !== '.') {
                return null;
            }
        }
    }

    private static function significant(array $tokens, int $from, int $step): ?int
    {
        for ($i = $from + $step; isset($tokens[$i]); $i += $step) {
            if (! $tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }
}
