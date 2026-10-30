<?php

namespace Tests\Feature;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Pages\Help;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** System → Help: the admin guide in docs/admin-guide/<language>/, one chapter per file. */
class AdminGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_admin_reads_the_guide_from_its_first_chapter(): void
    {
        User::factory()->create();
        $this->actingAs(User::factory()->limitedTo([])->create());

        $chapters = Help::chapters('en');
        $this->assertSame('getting-started', array_key_first($chapters));
        $this->assertSame('Getting started', $chapters['getting-started']['title']);

        $this->get(Help::getUrl())
            ->assertOk()
            ->assertSee('Getting started')
            ->assertSee('The setup guide')
            ->assertSee('Moving from WHMCS')
            // the cron line in Getting started scrolls inside its box on a phone
            ->assertSee('* * * * * cd', false)
            ->assertSee('.gcp-guide-body pre { max-width: 100%; overflow-x: auto; }', false);
    }

    public function test_a_chapter_opens_by_its_name_and_its_links_stay_in_the_panel(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(Help::getUrl(['chapter' => 'plugins-and-integrations']))
            ->assertOk()
            ->assertSee('<h2 id="the-plugins-page">', false)
            ->assertSee(e(Help::chapterUrl('maintenance')), false)
            ->assertSee('https://github.com/'.config('gaiacp.updates.repository').'/blob/main/docs/plugin-tutorial.md', false);

        // An unknown chapter shows the first one
        $this->get(Help::getUrl(['chapter' => 'nope']))->assertOk()->assertSee('The setup guide');
    }

    public function test_getting_started_names_every_settings_group(): void
    {
        $text = preg_replace('/\s+/', ' ', File::get(base_path('docs/admin-guide/en/01-getting-started.md')));

        foreach (SettingsCluster::GROUPS as $group) {
            $this->assertStringContainsString($group, $text, "Getting started does not name the {$group} settings group");
        }
    }

    public function test_the_plugins_chapter_lists_every_bundled_plugin(): void
    {
        $text = Str::lower(File::get(base_path('docs/admin-guide/en/13-plugins-and-integrations.md')));
        $table = Str::between($text, '## what ships with gaiacp', "\n## ");

        foreach (app(PluginManager::class)->all() as $plugin) {
            $this->assertStringContainsString(Str::lower($plugin->name()), $table, "What ships with GaiaCP does not list {$plugin->name()}");
        }
    }

    public function test_every_link_between_chapters_points_at_a_chapter_that_exists(): void
    {
        $files = array_map('basename', glob(base_path(Help::FOLDER.'/en/*.md')));

        foreach (glob(base_path(Help::FOLDER.'/*/*.md')) as $path) {
            preg_match_all('/\]\((?:\.\/)?(\d+-[\w-]+\.md)(?:#[\w-]+)?\)/', file_get_contents($path), $links);

            foreach ($links[1] as $link) {
                $this->assertContains($link, $files, basename(dirname($path)).'/'.basename($path)." links to {$link}");
            }
        }
    }

    public function test_every_link_to_a_section_points_at_a_heading_that_exists(): void
    {
        $anchors = [];

        foreach (glob(base_path(Help::FOLDER.'/*/*.md')) as $path) {
            preg_match_all('/^#{2,3} (.+)$/m', file_get_contents($path), $headings);
            $anchors[$path] = array_map(fn ($heading) => Str::slug(html_entity_decode(strip_tags(Str::inlineMarkdown($heading)))), $headings[1]);
        }

        foreach ($anchors as $path => $ids) {
            preg_match_all('/\]\(((?:\.\/)?\d+-[\w-]+\.md)?#([^)]+)\)/u', file_get_contents($path), $links, PREG_SET_ORDER);

            foreach ($links as $link) {
                $target = $link[1] === '' ? $path : dirname($path).'/'.ltrim($link[1], './');
                $this->assertContains($link[2], $anchors[$target] ?? [], basename(dirname($path)).'/'.basename($path)." links to {$link[0]}");
            }
        }
    }

    public function test_the_greek_guide_has_every_chapter(): void
    {
        $greek = Help::chapters('el');
        $this->assertSame('Πρώτα βήματα', $greek['getting-started']['title']);

        foreach ($greek as $slug => $chapter) {
            $this->assertSame(base_path(Help::FOLDER.'/el'), dirname($chapter['path']), "{$slug} has no Greek chapter");
        }
    }

    public function test_the_search_finds_sections_across_chapters(): void
    {
        $this->actingAs(User::factory()->create());

        $results = Livewire::test(Help::class)->set('q', 'Compare with the server')->instance()->results();

        $this->assertContains(['servers', 'Compare with the server'], array_map(fn (array $r) => [$r['slug'], $r['section']], $results));
        $this->assertSame([], Livewire::test(Help::class)->set('q', 'zzqx')->instance()->results());
    }

    public function test_a_translated_chapter_replaces_the_english_one_and_the_rest_stay_english(): void
    {
        $this->assertSame(Help::chapters('en'), Help::chapters('xx'));

        $folder = base_path(Help::FOLDER.'/xx');
        mkdir($folder);

        try {
            file_put_contents("{$folder}/01-getting-started.md", "# Πρώτα βήματα\n\nΚείμενο.\n");
            $chapters = Help::chapters('xx');

            $this->assertSame('Πρώτα βήματα', $chapters['getting-started']['title']);
            $this->assertSame('Products', $chapters['products']['title']);
        } finally {
            @unlink("{$folder}/01-getting-started.md");
            rmdir($folder);
        }
    }
}
