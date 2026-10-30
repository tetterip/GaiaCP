<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** A product's name in other languages and its public page (/<group>/<slug>), for visitors and search engines. */
class ProductPageTest extends TestCase
{
    use RefreshDatabase;

    private ProductGroup $group;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gaiacp.languages.enabled' => ['en', 'el'], 'gaiacp.company.name' => 'Acme Hosting']);
        $this->group = ProductGroup::create(['slug' => 'web-hosting', 'names' => ['en' => 'Shared hosting'], 'page' => true, 'features' => [['key' => 'disk', 'names' => ['en' => 'Disk space']]]]);
        $this->product = Product::factory()->priced(6000)->create([
            'name' => 'Business',
            'names' => ['el' => 'Επαγγελματικό'],
            'description' => 'For small shops.',
            'descriptions' => ['el' => 'Για μικρά καταστήματα.'],
            'product_group_id' => $this->group->id,
            'features' => ['disk' => '20 GB'],
            'page' => true,
            'page_titles' => ['en' => 'Business hosting'],
            'page_descriptions' => ['en' => 'Hosting for shops.'],
            'page_texts' => ['en' => 'Made for **online shops**.'],
        ]);
    }

    public function test_the_name_and_description_follow_the_language(): void
    {
        $this->assertSame('business', $this->product->slug); // made from the name
        $this->assertSame('Business', $this->product->localName());
        $this->assertSame('Επαγγελματικό', $this->product->localName('el'));
        $this->assertSame('Για μικρά καταστήματα.', $this->product->localDescription('el'));

        $this->get('/web-hosting?lang=el')->assertOk()->assertSee('Επαγγελματικό')->assertDontSee('>Business<', false);
        $this->get('/web-hosting?lang=en')->assertOk()->assertSee('href="'.url('web-hosting/business').'"', false);
    }

    public function test_visitors_see_the_page_with_its_product_data(): void
    {
        $this->get('/web-hosting/business')->assertOk()
            ->assertSee('Business hosting')
            ->assertSee('<strong>online shops</strong>', false)
            ->assertSee('For small shops.')
            ->assertSeeInOrder([__('What is included'), 'Disk space', '20 GB'])
            ->assertSee('<meta name="description" content="Hosting for shops.">', false)
            ->assertSee('<link rel="canonical" href="'.url('web-hosting/business').'">', false)
            ->assertSee('"@type":"Product"', false)
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertDontSee('noindex', false);

        $this->assertStringContainsString('<loc>'.url('web-hosting/business').'</loc>', $this->get('/sitemap.xml')->getContent());

        // Without a text: still a page, but kept out of search engines
        $this->product->update(['page_texts' => []]);
        $this->get('/web-hosting/business')->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee('application/ld+json', false);
        $this->assertStringNotContainsString('web-hosting/business', $this->get('/sitemap.xml')->getContent());
    }

    public function test_it_is_a_404_when_off_retired_or_in_another_group(): void
    {
        $this->get('/other/business')->assertNotFound();
        $this->product->update(['retired' => true]);
        $this->get('/web-hosting/business')->assertNotFound();
        $this->product->update(['retired' => false, 'page' => false]);
        $this->get('/web-hosting/business')->assertNotFound();
    }

    public function test_admins_fill_in_the_other_languages_and_the_page(): void
    {
        $this->actingAs(User::factory()->create());
        $other = Product::factory()->priced(1000)->create(['name' => 'Starter Plan', 'product_group_id' => $this->group->id]);

        Livewire::test(EditProduct::class, ['record' => $other->getRouteKey()])
            ->fillForm([
                'names' => ['el' => 'Αρχικό'],
                'page' => true,
                'page_texts' => ['en' => 'A first site.'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $other->refresh();
        $this->assertSame('Αρχικό', $other->localName('el'));
        $this->assertSame('starter-plan', $other->slug);
        $this->get('/web-hosting/starter-plan')->assertOk()->assertSee('A first site.');
    }
}
