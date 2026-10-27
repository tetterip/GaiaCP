<?php

namespace App\Filament\Pages;

use App\Ai\AiException;
use App\Ai\Assistant;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\KbArticle;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * System → Help: the admin guide, one chapter per file in docs/admin-guide/<language>/ (`NN-slug.md`, its `# ` line the
 * title), so the same files read on GitHub. English is the full set; a language's folder may hold some chapters, the
 * rest stay English. Links between chapters (`03-products.md`) open here; links to the other docs open on GitHub.
 * Open to every admin, like the dashboard: it describes the panel, not anyone's data. With Settings → AI assistant →
 * Panel help on, Ask the AI assistant answers the search as a question from the closest sections of the guide.
 */
class Help extends Page
{
    use TranslatesPageLabels;

    public const FOLDER = 'docs/admin-guide';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    /** After Settings. */
    protected static ?int $navigationSort = 110;

    protected static ?string $title = 'Help';

    protected static ?string $slug = 'help';

    protected string $view = 'filament.help';

    #[Url]
    public ?string $chapter = null;

    #[Url]
    public string $q = '';

    /** The AI assistant's answer to $q (Markdown), after Ask the AI assistant. */
    public ?string $answer = null;

    /** How much of the guide goes with a question to the AI service, in characters. */
    public const AI_CONTEXT = 16000;

    public function getSubheading(): ?string
    {
        return __('How to run your hosting business with GaiaCP, chapter by chapter.');
    }

    /** @return array<string, array{title: string, path: string}> slug => chapter, in order */
    public static function chapters(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $chapters = [];

        foreach (glob(base_path(self::FOLDER.'/en/*.md')) ?: [] as $english) {
            $file = basename($english);
            $translated = base_path(self::FOLDER."/{$locale}/{$file}");
            $path = $locale !== 'en' && is_file($translated) ? $translated : $english;
            $slug = (string) preg_replace('/^\d+-|\.md$/', '', $file);

            $chapters[$slug] = ['title' => self::titleOf((string) file_get_contents($path)) ?? $slug, 'path' => $path];
        }

        return $chapters;
    }

    /** The chapter open now: the one asked for, else the first. */
    public function currentSlug(): ?string
    {
        $chapters = self::chapters();

        return array_key_exists((string) $this->chapter, $chapters) ? $this->chapter : array_key_first($chapters);
    }

    public function body(): HtmlString
    {
        $slug = $this->currentSlug();

        if ($slug === null) {
            return new HtmlString('');
        }

        $markdown = (string) file_get_contents(self::chapters()[$slug]['path']);

        return new HtmlString(self::toHtml((string) preg_replace('/\A# [^\n]*\n+/', '', $markdown)));
    }

    /** @return list<array{slug: string, chapter: string, section: ?string, anchor: ?string, excerpt: string}> sections with every word of the search */
    public function results(): array
    {
        $words = array_filter(explode(' ', KbArticle::searchable($this->q)));

        if ($words === []) {
            return [];
        }

        $results = [];

        foreach (self::chapters() as $slug => $chapter) {
            $markdown = (string) preg_replace('/\A# [^\n]*\n+/', '', (string) file_get_contents($chapter['path']));

            foreach (preg_split('/^(?=## )/m', $markdown) ?: [] as $part) {
                $section = str_starts_with($part, '## ') ? trim(strtok($part, "\n"), "# \t") : null;
                $text = KbArticle::searchable($chapter['title'].' '.$part);

                if (collect($words)->every(fn (string $word) => str_contains($text, $word))) {
                    $results[] = [
                        'slug' => $slug,
                        'chapter' => $chapter['title'],
                        'section' => $section,
                        'anchor' => $section === null ? null : Str::slug($section),
                        'excerpt' => Str::limit(self::plain($section === null ? $part : Str::after($part, "\n")), 180),
                    ];
                }
            }
        }

        return $results;
    }

    public function updatedQ(): void
    {
        $this->answer = null;
    }

    /** Whether Ask the AI assistant shows: on under Settings → AI assistant, with something typed in the search. */
    public function canAsk(): bool
    {
        return Assistant::enabled('help') && mb_strlen(trim($this->q)) >= 3;
    }

    /**
     * Ask the AI assistant: the question typed in the search, answered from the guide's sections closest to it (only
     * those and the question are sent; nothing about clients or this install).
     */
    public function ask(): void
    {
        if (! $this->canAsk()) {
            return;
        }

        try {
            $this->answer = app(Assistant::class)->help($this->q, self::sectionsFor($this->q), app()->getLocale());
        } catch (AiException $e) {
            Notification::make()->danger()->title(__('The AI assistant gave no answer'))->body($e->getMessage())->send();
        }
    }

    public function answerHtml(): HtmlString
    {
        return new HtmlString(self::toHtml((string) $this->answer));
    }

    /**
     * The guide's sections with the most words of the question (in the admin's language), up to AI_CONTEXT characters,
     * each named by its file and anchor so the answer can link to it.
     *
     * @return array<string, string> "03-products.md#anchor Chapter › Section" => its Markdown
     */
    public static function sectionsFor(string $question, ?string $locale = null): array
    {
        $words = collect(explode(' ', KbArticle::searchable($question)))
            ->filter(fn (string $word) => mb_strlen($word) >= 3)
            ->map(fn (string $word) => mb_substr($word, 0, 5)) // a rough stem: "domains" and "domain" both count
            ->unique();
        $sections = [];

        foreach (self::chapters($locale) as $chapter) {
            $markdown = (string) preg_replace('/\A# [^\n]*\n+/', '', (string) file_get_contents($chapter['path']));

            foreach (preg_split('/^(?=## )/m', $markdown) ?: [] as $part) {
                $section = str_starts_with($part, '## ') ? trim(strtok($part, "\n"), "# \t") : null;
                $text = KbArticle::searchable($chapter['title'].' '.$section.' '.$part);
                $name = basename($chapter['path']).($section !== null ? '#'.Str::slug($section) : '').' '.$chapter['title'].($section !== null ? " › {$section}" : '');
                $sections[] = ['name' => $name, 'text' => trim($part), 'found' => $words->filter(fn (string $word) => str_contains($text, $word))->values()->all()];
            }
        }

        // A word found in few sections ("tld") counts more than one found in most ("add")
        $count = max(count($sections), 1);
        $weight = $words->mapWithKeys(fn (string $word) => [$word => log(($count + 1) / (1 + collect($sections)->filter(fn (array $s) => in_array($word, $s['found'], true))->count()))]);
        $sections = collect($sections)
            ->map(fn (array $s) => $s + ['score' => collect($s['found'])->sum(fn (string $word) => $weight[$word])])
            ->filter(fn (array $s) => $s['score'] > 0)
            ->all();

        $picked = [];
        $length = 0;

        foreach (collect($sections)->sortByDesc('score')->take(8) as $section) {
            if ($length + mb_strlen($section['text']) > self::AI_CONTEXT && $picked !== []) {
                continue;
            }
            $picked[$section['name']] = mb_substr($section['text'], 0, self::AI_CONTEXT);
            $length += mb_strlen($picked[$section['name']]);
        }

        return $picked;
    }

    public static function chapterUrl(string $slug, ?string $anchor = null): string
    {
        return static::getUrl(['chapter' => $slug]).($anchor ? "#{$anchor}" : '');
    }

    private static function titleOf(string $markdown): ?string
    {
        return preg_match('/^# (.+)$/m', $markdown, $match) ? trim($match[1]) : null;
    }

    /** Markdown to HTML, with ids on the headings and the links pointed at this page or at GitHub. */
    private static function toHtml(string $markdown): string
    {
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        $html = (string) preg_replace_callback(
            '/<h([23])>(.*?)<\/h\1>/s',
            fn (array $m) => sprintf('<h%s id="%s">%s</h%1$s>', $m[1], Str::slug(html_entity_decode(strip_tags($m[2]))), $m[2]),
            $html,
        );

        return (string) preg_replace_callback('/href="([^"]+)"/', function (array $m) {
            $href = html_entity_decode($m[1]);

            if (preg_match('/^(?:\.\/)?\d+-([\w-]+)\.md(?:#([\w-]+))?$/', $href, $link)) {
                return 'href="'.e(self::chapterUrl($link[1], $link[2] ?? null)).'"';
            }

            if (preg_match('/^(?:\.\.\/)+([\w\/.-]+\.md)(#[\w-]+)?$/', $href, $link)) {
                $repository = config('gaiacp.updates.repository');

                return 'href="'.e("https://github.com/{$repository}/blob/main/docs/{$link[1]}".($link[2] ?? '')).'"';
            }

            return $m[0];
        }, $html);
    }

    private static function plain(string $markdown): string
    {
        $text = strip_tags(Str::markdown($markdown, ['html_input' => 'strip']));

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text)));
    }
}
