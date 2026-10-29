<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ProductGroups\Pages\ManageProductGroups;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** A product group's public page (/<slug>, Settings → Product groups → Public page), for visitors and search engines. */
class ProductGroupPageTest extends TestCase
{
    use RefreshDatabase;

    private ProductGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gaiacp.languages.enabled' => ['en', 'el'], 'gaiacp.company.name' => 'Acme Hosting']);
        $this->group = ProductGroup::create([
            'slug' => 'web-hosting',
            'names' => ['en' => 'Shared hosting', 'el' => 'Φιλοξενία'],
            'descriptions' => ['en' => 'Websites and email.'],
            'features' => [['key' => 'disk', 'names' => ['en' => 'Disk space']]],
            'page' => true,
            'page_titles' => ['en' => 'Fast web hosting', 'el' => 'Γρήγορη φιλοξενία'],
            'page_descriptions' => ['en' => 'Hosting with daily backups.'],
            'page_texts' => ['en' => "Our **hosting** suits small shops.\n\n<script>alert(1)</script>"],
            'faq' => [
                ['questions' => ['en' => 'Is email included?', 'el' => 'Έχει email;'], 'answers' => ['en' => 'Yes, *unlimited*.']],
                ['questions' => ['en' => ''], 'answers' => ['en' => 'dropped']],
            ],
        ]);
        Product::factory()->priced(6000)->create(['name' => 'Basic', 'product_group_id' => $this->group->id, 'features' => ['disk' => '10 GB']]);
        Product::factory()->priced(12000)->create(['name' => 'Pro', 'product_group_id' => $this->group->id, 'features' => ['disk' => '50 GB']]);
    }

    public function test_visitors_see_the_page_with_its_text_products_compare_table_and_questions(): void
    {
        $this->assertCount(1, $this->group->fresh()->faq); // the empty question is dropped

        $response = $this->get('/web-hosting')->assertOk()
            ->assertSee('<title>', false)
            ->assertSee('Fast web hosting')
            ->assertSee('<strong>hosting</strong>', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSeeInOrder(['Basic', 'Pro', __('Compare packages'), 'Disk space', '10 GB', '50 GB'])
            ->assertSee('Is email included?')
            ->assertSee('<em>unlimited</em>', false)
            ->assertSee('<meta name="description" content="Hosting with daily backups.">', false)
            ->assertSee('<meta property="og:title" content="Fast web hosting - Acme Hosting">', false)
            ->assertSee('<link rel="canonical" href="'.url('web-hosting').'">', false)
            ->assertSee('"@type":"FAQPage"', false)
            ->assertSee('"@type":"ItemList"', false)
            ->assertSee('"priceCurrency":"EUR"', false);

        // Public orders off and no sign up: Order goes to the login
        $response->assertSee('href="'.url('login').'"', false);

        $this->get('/web-hosting?lang=el')->assertOk()->assertSee('Γρήγορη φιλοξενία')->assertSee('Έχει email;');
    }

    public function test_order_links_follow_the_public_order_page_and_signed_in_clients(): void
    {
        config(['gaiacp.client_area.public_orders' => true]);
        $basic = Product::query()->where('name', 'Basic')->value('id');

        $this->get('/web-hosting')->assertOk()->assertSee(e(route('filament.client.cart', ['group' => 'web-hosting', 'product' => $basic])), false);

        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
        $this->actingAs($user, 'client')->get('/web-hosting')->assertOk()
            ->assertSee(e(url('order/web-hosting').'?product='.$basic), false)
            ->assertDontSee('application/ld+json', false);
    }

    public function test_without_its_switch_or_for_another_address_it_is_a_404(): void
    {
        $this->get('/no-such-group')->assertNotFound();
        $this->group->update(['page' => false]);
        $this->get('/web-hosting')->assertNotFound();

        // The client area's own pages still win
        $this->get('/login')->assertOk();
    }

    public function test_the_home_page_menu_and_sitemap_link_to_it(): void
    {
        config(['gaiacp.public_area.home' => true]);

        $this->get('/')->assertOk()->assertSee('href="'.url('web-hosting').'"', false);
        $this->assertStringContainsString('<loc>'.url('web-hosting').'?lang=el</loc>', $this->get('/sitemap.xml')->getContent());
    }

    public function test_admins_switch_it_on_and_cant_take_a_link_of_the_client_area(): void
    {
        $this->actingAs(User::factory()->create());
        $group = ProductGroup::create(['slug' => 'vps', 'names' => ['en' => 'VPS']]);

        Livewire::test(ManageProductGroups::class)
            ->callAction(TestAction::make('edit')->table($group), ['slug' => 'login'])
            ->assertHasActionErrors(['slug']);

        Livewire::test(ManageProductGroups::class)
            ->callAction(TestAction::make('edit')->table($group), [
                'page' => true,
                'page_titles' => ['en' => 'Cloud servers'],
                'faq' => [['questions' => ['en' => 'Root access?'], 'answers' => ['en' => 'Yes.']]],
            ])
            ->assertHasNoActionErrors();

        $group->refresh();
        $this->assertTrue($group->page);
        $this->assertSame('Cloud servers', $group->pageTitle());
        $this->assertSame([['question' => 'Root access?', 'answer' => 'Yes.']], $group->questions());
    }
}
