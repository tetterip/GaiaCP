<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Aade\AadeSettings;
use Plugins\GoogleLogin\GoogleLoginSettings;
use Plugins\Recaptcha\RecaptchaSettings;
use Plugins\Stripe\StripeSettings;
use Plugins\Turnstile\TurnstileSettings;
use Tests\TestCase;

/** Links inside texts (helper texts, descriptions, Text components) look like links: resources/views/filament/links.blade.php. */
class TextLinksTest extends TestCase
{
    use RefreshDatabase;

    /** The containers that stylesheet styles links in. */
    private const STYLED = ['fi-sc-text', 'fi-section-header-description', 'fi-modal-description', 'fi-callout-description',
        'fi-empty-state-description', 'fi-fo-checkbox-list-option-description', 'fi-ta-header-description', 'fi-prose', 'gcp-help'];

    public function test_both_panels_load_the_link_style(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('a:not([class*="fi-"])', false);
        $this->get('/login')->assertOk()->assertSee('a:not([class*="fi-"])', false);
    }

    public function test_the_stylesheet_and_this_test_name_the_same_containers(): void
    {
        $view = file_get_contents(resource_path('views/filament/links.blade.php'));

        foreach (self::STYLED as $class) {
            $this->assertSame(2, preg_match_all('/\\.'.preg_quote($class, '/').'(?![\\w-])/', $view), "{$class} is not in both the style and the script");
        }
    }

    public function test_links_on_the_settings_pages_sit_in_a_styled_text_and_open_other_sites_in_a_new_tab(): void
    {
        $this->actingAs(User::factory()->create());
        config(['gaiacp.aade.enabled' => true]); // its console link shows only then

        foreach ([RecaptchaSettings::class, TurnstileSettings::class, StripeSettings::class, AadeSettings::class, GoogleLoginSettings::class] as $page) {
            $links = $this->textLinks($this->get($page::getUrl())->assertOk()->getContent());

            $this->assertNotEmpty($links, "{$page} has no links in its texts");

            foreach ($links as $link) {
                $this->assertTrue($this->inStyledText($link), "A link on {$page} is outside the styled texts: ".$link->getAttribute('href'));
                $this->assertSame('_blank', $link->getAttribute('target'), "{$page}: ".$link->getAttribute('href'));
            }
        }
    }

    /** @return list<DOMElement> the page's links in its main content that are not Filament's own link components */
    private function textLinks(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        $links = [];
        foreach ((new DOMXPath($dom))->query('//main//a[@href]') as $link) {
            if (! str_contains($link->getAttribute('class'), 'fi-')) {
                $links[] = $link;
            }
        }

        return $links;
    }

    private function inStyledText(DOMElement $link): bool
    {
        for ($node = $link->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (array_intersect(explode(' ', $node->getAttribute('class')), self::STYLED) !== []) {
                return true;
            }
        }

        return false;
    }
}
