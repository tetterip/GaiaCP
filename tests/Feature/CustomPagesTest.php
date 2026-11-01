<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Enums\UserRole;
use App\Filament\Clusters\Settings\Pages\MenuSettings;
use App\Filament\Resources\CustomPages\Pages\CreateCustomPage;
use App\Filament\Resources\CustomPages\Pages\EditCustomPage;
use App\Models\Client;
use App\Models\CustomPage;
use App\Models\User;
use App\Support\ClientMenu;
use App\Support\PageImages;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Support → Pages: pages of your own written with the editor, at /pages/{slug}, in the menus and the sitemap. */
class CustomPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private CustomPage $about;

    private CustomPage $prices;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gaiacp.locales' => ['en' => 'English', 'el' => 'Ελληνικά']]);
        $this->admin = User::factory()->create(['email' => 'admin@example.com']);
        $client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);

        $this->about = CustomPage::create([
            'texts' => [
                'en' => ['title' => 'About us', 'body' => '<h2>Who we are</h2><p>A <strong>small</strong> team.</p><script>alert(1)</script><p><a href="javascript:alert(1)">bad</a></p>', 'description' => 'Hosting since 2010.'],
                'el' => ['title' => 'Ποιοι είμαστε', 'body' => '<p>Μια μικρή ομάδα.</p>'],
            ],
            'published' => true,
        ]);
        $this->prices = CustomPage::create(['texts' => ['en' => ['title' => 'Reseller prices', 'body' => '<p>Only for you.</p>']], 'published' => true, 'clients_only' => true]);
        CustomPage::create(['texts' => ['en' => ['title' => 'Draft page', 'body' => '<p>Not yet.</p>']]]);
    }

    public function test_visitors_and_clients_read_a_published_page_sanitized(): void
    {
        $this->assertSame('about-us', $this->about->slug);

        $this->get('/pages/about-us')->assertOk()
            ->assertSee('<h2>Who we are</h2>', false)
            ->assertSee('<strong>small</strong>', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('javascript:alert', false)
            ->assertSee('<meta name="description" content="Hosting since 2010.">', false);

        // Another language: its own text, and the first language's where there is none
        $this->withSession(['public_area.locale' => 'el'])->get('/pages/about-us')->assertOk()->assertSee('Μια μικρή ομάδα.');
        $this->assertSame('Reseller prices', $this->prices->title('el'));

        $this->get('/pages/draft-page')->assertNotFound();

        // One kept for clients asks visitors to log in, which brings them back to it
        $this->get('/pages/reseller-prices')->assertRedirect(Filament::getPanel('client')->getLoginUrl());
        $this->assertStringEndsWith('/pages/reseller-prices', session('url.intended'));
        $this->actingAs($this->user, 'client')->get('/pages/reseller-prices')->assertOk()->assertSee('Only for you.');
    }

    public function test_pages_are_added_to_both_menus(): void
    {
        $draft = CustomPage::query()->where('slug', 'draft-page')->first();
        config(['gaiacp.public_area.home' => true]);
        $items = fn () => [
            ['type' => 'page', 'key' => 'home'],
            ['type' => 'custom_page', 'custom_page' => $this->about->id],
            ['type' => 'custom_page', 'custom_page' => $this->prices->id, 'label' => ['en' => 'Our prices']],
            ['type' => 'custom_page', 'custom_page' => $draft->id],
        ];
        config(['gaiacp.public_area.menu' => ['items' => $items(), 'seen' => array_keys(ClientMenu::pages(ClientMenu::PUBLIC))]]);

        // Visitors: not the one kept for clients, nor the draft
        $this->get('/')->assertOk()
            ->assertSee('About us')
            ->assertSee('href="'.url('/pages/about-us').'"', false)
            ->assertDontSee('Our prices')
            ->assertDontSee('Draft page');

        config(['gaiacp.client_area.menu' => ['items' => [
            ['type' => 'page', 'key' => 'Dashboard'],
            ['type' => 'custom_page', 'custom_page' => $this->prices->id, 'label' => ['en' => 'Our prices']],
        ], 'seen' => []]]);
        $this->actingAs($this->user, 'client');
        Filament::setCurrentPanel('client');

        $this->get('/pages/reseller-prices')->assertOk()->assertSee('Our prices')->assertSee('href="'.url('/pages/reseller-prices').'"', false);
    }

    public function test_the_menus_settings_add_a_page(): void
    {
        $this->actingAs($this->admin);

        $settings = Livewire::test(MenuSettings::class)->assertOk();
        $items = $settings->get('data.public_area.menu.items');
        $items['new'] = ['type' => 'custom_page', 'data' => ['custom_page' => $this->about->id, 'label' => ['en' => 'Who we are']]];
        $settings->set('data.public_area.menu.items', $items)->call('save')->assertHasNoFormErrors();

        $saved = config('gaiacp.public_area.menu')['items'];
        $this->assertSame(['type' => 'custom_page', 'custom_page' => $this->about->id, 'label' => ['en' => 'Who we are']], end($saved));

        $settings->set('data.public_area.menu.items.bad', ['type' => 'custom_page', 'data' => []])
            ->call('save')->assertHasFormErrors(['public_area.menu.items.bad.data.custom_page']);
    }

    public function test_the_sitemap_lists_the_public_ones(): void
    {
        config(['gaiacp.seo.index' => true]);

        $this->get('/sitemap.xml')->assertOk()
            ->assertSee(url('/pages/about-us'), false)
            ->assertDontSee('reseller-prices')
            ->assertDontSee('draft-page');
    }

    public function test_admins_write_pages_with_the_editor(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateCustomPage::class)
            ->assertActionDoesNotExist('createAnother')
            ->fillForm(['texts' => ['en' => ['title' => 'About us', 'body' => '<p>Second <em>time</em>.</p>']], 'published' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $page = CustomPage::query()->latest('id')->first();
        $this->assertSame(['about-us-2', $this->admin->id, true], [$page->slug, $page->created_by, $page->published]);
        $this->assertStringContainsString('<em>time</em>', $page->texts['en']['body']);

        Livewire::test(EditCustomPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['slug' => 'who-we-are'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('who-we-are', $page->fresh()->slug);

        $this->get('/admin/pages')->assertOk()->assertSee('/pages/who-we-are');
    }

    public function test_admins_without_support_cant_open_it(): void
    {
        $this->actingAs(User::factory()->limitedTo([AdminArea::Billing])->create());

        $this->get('/admin/pages')->assertForbidden();
    }

    public function test_pictures_are_kept_and_served_without_storage_link(): void
    {
        Storage::fake(PageImages::DISK);

        $upload = UploadedFile::fake()->image('team.png', 40, 40);
        $id = PageImages::store($upload);
        $this->assertNull(PageImages::store(UploadedFile::fake()->create('evil.svg', 1, 'image/svg+xml')));

        $this->assertMatchesRegularExpression('#^pages/[A-Za-z0-9]{40}\.png$#', (string) $id);
        $url = PageImages::url($id);
        $this->assertSame(route('page-image', ['file' => basename($id)]), $url);
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        // Only ids of these images resolve
        $this->assertNull(PageImages::url('../.env'));
        $this->assertNull(PageImages::url('branding/logo.png'));
        $this->get('/page-images/missing1234567890123456789.png')->assertNotFound();

        $this->about->update(['texts' => ['en' => ['title' => 'About us', 'body' => '<p>Us:</p><img src="https://example.com/x.png" data-id="'.$id.'" alt="Team">']]]);
        $this->get('/pages/about-us')->assertOk()->assertSee($url, false)->assertSee('alt="Team"', false);
    }

    public function test_the_featured_image_shows_above_the_text_and_in_link_previews(): void
    {
        Storage::fake(PageImages::DISK);
        $id = PageImages::store(UploadedFile::fake()->image('cover.jpg', 1200, 630));
        $this->about->update(['image' => $id]);
        $url = PageImages::url($id);

        $this->get('/pages/about-us')->assertOk()
            ->assertSee('<img src="'.$url.'"', false)
            ->assertSee('<meta property="og:image" content="'.$url.'">', false)
            ->assertSee('summary_large_image', false);

        // Without one, no large preview card
        $this->about->update(['image' => null]);
        $this->get('/pages/about-us')->assertOk()->assertDontSee('summary_large_image', false);
    }

    public function test_admins_upload_a_featured_image(): void
    {
        Storage::fake(PageImages::DISK);
        $this->actingAs($this->admin);

        Livewire::test(EditCustomPage::class, ['record' => $this->about->getRouteKey()])
            ->fillForm(['image' => UploadedFile::fake()->image('cover.png', 1200, 630)])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotNull(PageImages::url($this->about->fresh()->image));
    }
}
