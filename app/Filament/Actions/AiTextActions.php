<?php

namespace App\Filament\Actions;

use App\Ai\AiException;
use App\Ai\Assistant;
use App\Support\Locales;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\Intl\Languages;

/**
 * The AI menu after a text field's label (App\Ai\Assistant): Improve the text in a few ways, Translate it into one
 * of the panel's languages, or fill this language's text from another language's field. Each opens a window with
 * the suggestion, which the admin can change and then use (it replaces the field's text) or close. Hidden while the
 * assistant or the feature is off.
 */
class AiTextActions
{
    /**
     * @param  string|null  $locale  the field's language, when it holds one language of a text with several
     * @param  array<string, string>  $translateFrom  for such a field: language => the field (its name in the same form)
     *                                                with that language's text; offers "Translate from …" instead of
     *                                                "Translate into …"
     * @param  Closure(): ?string|null  $clientLanguage  the client's language, offered first under Translate into
     * @param  list<array{name: string, label: string, feature: string, ask: Closure(Assistant, string, Get): string}>  $write
     *                                                                                                                          items first in the menu that write the text from elsewhere in the form (ask gets the field's text as a draft)
     */
    public static function on(Field $field, ?string $locale = null, array $translateFrom = [], ?Closure $clientLanguage = null, array $write = []): Field
    {
        $actions = [];

        foreach ($write as $item) {
            $actions[] = self::action($item['name'], $item['label'], $item['feature'], $item['ask'])
                ->icon(Heroicon::OutlinedSparkles);
        }

        foreach (Assistant::styles() as $style => $label) {
            $actions[] = self::action("aiImprove_{$style}", $label, 'improve', fn (Assistant $ai, string $text) => $ai->improve($text, $style))
                ->icon(Heroicon::OutlinedPencil);
        }

        if ($translateFrom !== []) {
            foreach ($translateFrom as $from => $source) {
                $actions[] = self::action("aiFrom_{$from}", __('Translate from :language', ['language' => self::languageName($from)]), 'translate',
                    fn (Assistant $ai, string $text) => $ai->translate($text, $locale ?? Locales::default()), source: $source,
                    emptySource: __('The :language text is empty, so there is nothing to translate from.', ['language' => self::languageName($from)]))
                    ->icon(Heroicon::OutlinedLanguage);
            }

            // The text written here in another language (Greek typed in the English tab)
            $into = $locale ?? Locales::default();
            $actions[] = self::action("aiTranslate_{$into}", __('Translate this text into :language', ['language' => self::languageName($into)]), 'translate',
                fn (Assistant $ai, string $text) => $ai->translate($text, $into))
                ->icon(Heroicon::OutlinedLanguage);
        } else {
            // The client's own language first, also when it is not one of the panel's
            $actions[] = self::action('aiTranslate_client', fn () => __('Translate into :language (the client\'s)', ['language' => self::languageName((string) self::clientLocale($clientLanguage))]), 'translate',
                fn (Assistant $ai, string $text) => $ai->translate($text, (string) self::clientLocale($clientLanguage)))
                ->icon(Heroicon::OutlinedLanguage)
                ->visible(fn () => Assistant::enabled('translate') && self::clientLocale($clientLanguage) !== null);

            foreach (self::targets() as $target) {
                $actions[] = self::action("aiTranslate_{$target}", __('Translate into :language', ['language' => self::languageName($target)]), 'translate',
                    fn (Assistant $ai, string $text) => $ai->translate($text, $target))
                    ->icon(Heroicon::OutlinedLanguage)
                    ->visible(fn () => Assistant::enabled('translate') && $target !== self::clientLocale($clientLanguage));
            }
        }

        return $field->afterLabel(
            ActionGroup::make($actions)
                ->label(__('AI'))
                ->tooltip(__('AI assistant'))
                ->icon(Heroicon::OutlinedSparkles)
                ->link()
                ->visible(fn () => collect(['improve', 'translate', ...array_column($write, 'feature')])->contains(fn (string $feature) => Assistant::enabled($feature))),
        );
    }

    /**
     * For one language's field of a text kept in several (`texts.el.body`): Improve, Translate from each other
     * language's field, and Translate this text into the field's language.
     *
     * @param  Closure(string): string  $nameFor  a language => its field's name, e.g. fn ($l) => "texts.{$l}.body"
     */
    public static function forLocale(Field $field, string $locale, Closure $nameFor): Field
    {
        $others = collect(Locales::available())->except($locale)->keys()->mapWithKeys(fn (string $other) => [$other => $nameFor($other)])->all();

        return self::on($field, $locale, $others);
    }

    /**
     * @param  Closure(Assistant, string, Get): string  $ask
     * @param  string|null  $source  the field whose text is sent, when not this one
     * @param  string|null  $emptySource  what to say when that field is empty
     */
    private static function action(string $name, string|Closure $label, string $feature, Closure $ask, ?string $source = null, ?string $emptySource = null): Action
    {
        return Action::make($name)
            ->label($label)
            ->visible(fn () => Assistant::enabled($feature))
            ->modalHeading($label)
            ->modalDescription(fn () => __('Suggested by :service. Change it if you like, then use it: it replaces the text in the field.', ['service' => Assistant::provider()?->aiLabel()]))
            ->modalWidth('3xl')
            ->fillForm(function (Action $action, Field $schemaComponent, Get $schemaGet) use ($ask, $source, $emptySource) {
                $text = (string) ($source === null ? $schemaComponent->getState() : $schemaGet($source));

                try {
                    if ($emptySource !== null && trim($text) === '') {
                        throw new AiException($emptySource);
                    }

                    return ['result' => $ask(app(Assistant::class), $text, $schemaGet)];
                } catch (AiException $e) {
                    Notification::make()->danger()->title(__('The AI assistant gave no answer'))->body($e->getMessage())->send();
                    $action->cancel();
                }
            })
            ->schema([
                Textarea::make('result')
                    ->hiddenLabel()
                    ->rows(12)
                    ->autosize()
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('Use this text'))
            ->action(fn (array $data, Field $schemaComponent) => $schemaComponent->state($data['result']));
    }

    /** The panel's languages, for Translate into. @return list<string> */
    private static function targets(): array
    {
        return array_keys(Locales::available() + ['en' => 'English']);
    }

    private static function clientLocale(?Closure $clientLanguage): ?string
    {
        $locale = $clientLanguage ? $clientLanguage() : null;

        return filled($locale) ? (string) $locale : null;
    }

    private static function languageName(string $locale): string
    {
        return Locales::available()[$locale] ?? (Languages::exists($locale) ? Languages::getName($locale, $locale) : $locale);
    }
}
