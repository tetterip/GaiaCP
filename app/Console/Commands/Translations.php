<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use App\Support\Locales;
use App\Support\TranslationKeys;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:translations {locale : Language code, e.g. el}
    {--plugin= : Only this plugin\'s texts (its folder name, e.g. Telegram)}
    {--write : Add the missing texts to lang/<locale>.json (and the one in each plugin folder), in English, for a translator to replace}')]
#[Description('List the panel and email texts that lang/<locale>.json does not translate yet')]
class Translations extends Command
{
    public function handle(): int
    {
        $locale = (string) $this->argument('locale');

        if (! preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale)) {
            $this->error('Use a language code such as el or pt_BR.');

            return self::FAILURE;
        }

        $core = TranslationKeys::core();
        $only = $this->option('plugin');
        $plugins = app(PluginManager::class)->all()
            ->filter(fn ($plugin) => blank($only) || strcasecmp(basename($plugin->path()), (string) $only) === 0 || $plugin->id() === $only);

        if (filled($only) && $plugins->isEmpty()) {
            $this->error("No plugin {$only}: use its folder name under plugins/.");

            return self::FAILURE;
        }

        if (blank($only)) {
            $this->check(lang_path("{$locale}.json"), "lang/{$locale}.json", $core);
        }

        // A plugin's file holds its texts that the app's file does not (the app's file wins where both have one).
        foreach ($plugins as $plugin) {
            $keys = array_values(array_diff(TranslationKeys::forPlugin($plugin), $core));
            $path = $plugin->path()."/lang/{$locale}.json";

            if ($keys !== [] || is_file($path)) {
                $this->check($path, str_replace(base_path().'/', '', $path), $keys);
            }
        }

        if (! array_key_exists($locale, Locales::available())) {
            $this->comment("{$locale} is not offered yet: switch it on under Settings → Languages once it is translated.");
        }

        return self::SUCCESS;
    }

    /** @param  list<string>  $keys  the texts this file should translate */
    private function check(string $path, string $name, array $keys): void
    {
        $translated = is_file($path) ? (array) json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
        $missing = array_values(array_diff($keys, array_keys($translated)));
        $unused = array_values(array_diff(array_keys($translated), $keys));

        foreach ($missing as $key) {
            $this->line($key);
        }

        $this->newLine();
        $this->info(count($keys).' texts, '.count($missing)." not in {$name}.");

        if ($unused !== []) {
            $this->warn(count($unused).' in the file are no longer used: '.implode(' | ', array_slice($unused, 0, 10)).(count($unused) > 10 ? ' …' : ''));
        }

        if ($this->option('write') && $missing !== []) {
            $translated += array_combine($missing, $missing);
            ksort($translated);
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, json_encode($translated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $this->info("Added them to {$name}.");
        }
    }
}
