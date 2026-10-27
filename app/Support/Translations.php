<?php

namespace App\Support;

use App\Plugins\Plugin;
use App\Plugins\PluginManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Intl\Locales as IntlLocales;
use Throwable;

/**
 * The languages GaiaCP has text for and how complete each one is (Settings → Languages). A language is English (the
 * built-in text) plus every lang/<code>.json and every file uploaded to storage/app/private/lang/<code>.json. Uploaded
 * translations live in storage, so an update never replaces them: the core's in `lang/<code>.json` there, a plugin's
 * in `lang/plugins/<Folder>/<code>.json` there, each over the shipped file (App\Plugins\PluginManager::registerTranslations()).
 * A text with no translation shows in English.
 */
final class Translations
{
    /** @var array<string, string>|null */
    private ?array $found = null;

    /** The languages shipped as machine translations, until someone who speaks them has checked them. */
    public const MACHINE_TRANSLATED = ['de', 'es', 'fr', 'it', 'nl', 'pl', 'pt_BR', 'tr'];

    public static function overridePath(string $path = ''): string
    {
        return storage_path('app/private/lang'.($path !== '' ? '/'.$path : ''));
    }

    public static function pluginOverridePath(Plugin $plugin): string
    {
        return self::overridePath('plugins/'.basename($plugin->path()));
    }

    public static function validCode(?string $code): bool
    {
        return is_string($code) && preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $code) === 1 && IntlLocales::exists($code);
    }

    /** @return array<string, string> code => name in that language: English first, then by code */
    public static function found(): array
    {
        $self = app(self::class);

        if ($self->found === null) {
            $codes = collect([...File::glob(lang_path('*.json')), ...File::glob(self::overridePath('*.json'))])
                ->map(fn (string $file) => pathinfo($file, PATHINFO_FILENAME))
                ->filter(fn (string $code) => $code !== 'en' && self::validCode($code))
                ->unique()->sort()->values();

            $self->found = ['en' => 'English'] + $codes->mapWithKeys(fn (string $code) => [$code => self::name($code)])->all();
        }

        return $self->found;
    }

    public static function flush(): void
    {
        app(self::class)->found = null;
    }

    /** The language's name in itself: "Ελληνικά", "Deutsch", "Português (Brasil)". */
    public static function name(string $code): string
    {
        try {
            $name = str_contains($code, '_') ? IntlLocales::getName($code, $code) : Languages::getName($code, $code);
        } catch (Throwable) {
            return $code;
        }

        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }

    /**
     * The texts a language does not translate yet: the core's and each enabled plugin's own.
     *
     * @return array{total: int, missing: int, core: list<string>, plugins: array<string, array{name: string, total: int, missing: list<string>}>}
     */
    public static function missing(string $locale): array
    {
        $keys = self::keys();
        $core = $locale === 'en' ? $keys['core'] : self::translated($locale, lang_path(), self::overridePath());
        $result = ['total' => count($keys['core']), 'missing' => 0, 'core' => [], 'plugins' => []];
        $result['core'] = $locale === 'en' ? [] : array_values(array_diff($keys['core'], $core));

        foreach (app(PluginManager::class)->enabledPlugins() as $plugin) {
            $own = $keys['plugins'][basename($plugin->path())] ?? [];
            if ($own === []) {
                continue;
            }
            $translated = $locale === 'en' ? $own : [...$core, ...self::translated($locale, $plugin->path().'/lang', self::pluginOverridePath($plugin))];
            $result['plugins'][basename($plugin->path())] = ['name' => $plugin->name(), 'total' => count($own), 'missing' => array_values(array_diff($own, $translated))];
            $result['total'] += count($own);
        }

        $result['missing'] = count($result['core']) + array_sum(array_map(fn (array $p) => count($p['missing']), $result['plugins']));

        return $result;
    }

    /**
     * After a plugin update: per language offered (English aside), how many of its texts are not translated.
     *
     * @return array<string, int> language name => count, only the ones missing some
     */
    public static function missingForPlugin(Plugin $plugin): array
    {
        $own = self::keys()['plugins'][basename($plugin->path())] ?? [];

        return collect(Locales::available())->except('en')
            ->map(fn (string $name, string $code) => count(array_diff($own, [
                ...self::translated($code, lang_path(), self::overridePath(), $plugin->path().'/lang', self::pluginOverridePath($plugin)),
            ])))
            ->filter()
            ->mapWithKeys(fn (int $count, string $code) => [Locales::available()[$code] => $count])
            ->all();
    }

    /** How much of the language is translated, 0 to 100 (rounded down, so 100 means every text). */
    public static function percent(string $locale): int
    {
        $missing = self::missing($locale);

        return $missing['total'] === 0 ? 100 : (int) floor(100 * ($missing['total'] - $missing['missing']) / $missing['total']);
    }

    /**
     * "Download missing texts": each text untranslated in the language, with the English as its value, to replace.
     *
     * @return array<string, string>
     */
    public static function export(string $locale): array
    {
        $missing = self::missing($locale);
        $keys = $missing['core'];
        foreach ($missing['plugins'] as $plugin) {
            array_push($keys, ...$plugin['missing']);
        }
        $keys = array_values(array_unique($keys));

        return array_combine($keys, $keys);
    }

    /**
     * An uploaded translation: each text goes to the core's file or to its plugin's, in storage. Unknown texts and
     * ones left in English are skipped; HTML only where the English has it.
     *
     * @param  array<mixed>  $lines  English text => translation
     * @return array{saved: int, skipped: int}
     */
    public static function import(string $locale, array $lines): array
    {
        $keys = self::keys();
        $core = array_flip($keys['core']);
        $owner = [];
        foreach ($keys['plugins'] as $folder => $own) {
            foreach ($own as $key) {
                $owner[$key] ??= $folder;
            }
        }

        $files = [];
        $skipped = 0;
        foreach ($lines as $key => $value) {
            $key = (string) $key;
            $value = is_string($value) ? trim($value) : '';
            $target = isset($core[$key]) ? '' : ($owner[$key] ?? null);

            if ($value === '' || $value === $key || $target === null) {
                $skipped++;

                continue;
            }

            $files[$target][$key] = str_contains($key, '<') ? $value : strip_tags($value);
        }

        foreach ($files as $folder => $translated) {
            $path = ($folder === '' ? self::overridePath() : self::overridePath("plugins/{$folder}"))."/{$locale}.json";
            $existing = is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode(array_merge($existing, $translated), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }

        self::flush();
        app('translator')->setLoaded([]); // the next text read loads the new files

        return ['saved' => array_sum(array_map('count', $files)), 'skipped' => $skipped];
    }

    /**
     * The texts to translate (App\Support\TranslationKeys), cached until GaiaCP or a plugin changes version.
     *
     * @return array{core: list<string>, plugins: array<string, list<string>>}
     */
    public static function keys(): array
    {
        $plugins = app(PluginManager::class)->all();
        $version = Version::current().'|'.$plugins->map(fn (Plugin $p) => basename($p->path()).'@'.$p->version())->implode(',');

        return Cache::remember('gaiacp.translation_keys.'.md5($version), 3600, function () use ($plugins) {
            $core = TranslationKeys::core();

            return [
                'core' => $core,
                'plugins' => $plugins->mapWithKeys(fn (Plugin $p) => [basename($p->path()) => array_values(array_diff(TranslationKeys::forPlugin($p), $core))])->all(),
            ];
        });
    }

    /** @return list<string> the texts the language's files in these folders translate */
    private static function translated(string $locale, string ...$folders): array
    {
        $keys = [];
        foreach ($folders as $folder) {
            $file = "{$folder}/{$locale}.json";
            if (is_file($file)) {
                array_push($keys, ...array_keys((array) json_decode((string) file_get_contents($file), true)));
            }
        }

        return $keys;
    }
}
