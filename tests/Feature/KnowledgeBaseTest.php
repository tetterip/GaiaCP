<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Client\Pages\KnowledgeBase as ClientKnowledgeBase;
use App\Filament\Client\Pages\KnowledgeBaseArticle;
use App\Filament\Client\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Resources\KbArticles\Pages\CreateKbArticle;
use App\Filament\Resources\KbArticles\Pages\ListKbArticles;
use App\Filament\Resources\KbCategories\Pages\ManageKbCategories;
use App\Models\Client;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\User;
use App\Support\KnowledgeBase;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Support → Knowledge base: articles the admins write, which clients read, search and are offered on new tickets. */
class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private KbCategory $email;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['email' => 'admin@example.com']);
        $client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
        $this->email = KbCategory::create(['names' => ['en' => 'Email', 'el' => 'Email']]);
    }

    private function article(string $title, string $body, array $attributes = []): KbArticle
    {
        return KbArticle::create($attributes + [
            'kb_category_id' => $this->email->id,
            'texts' => ['en' => ['title' => $title, 'body' => $body]],
            'published' => true,
        ]);
    }

    private function asClient(): void
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');
    }

    public function test_an_admin_writes_an_article_and_a_category(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);

        Livewire::test(ManageKbCategories::class)
            ->callAction('create', ['names' => ['en' => 'Domains', 'el' => 'Domains'], 'sort' => 2])
            ->assertHasNoFormErrors();

        Livewire::test(CreateKbArticle::class)
            ->fillForm([
                'kb_category_id' => $this->email->id,
                'published' => true,
                'texts' => [
                    'en' => ['title' => 'Set up email on your phone', 'body' => 'Open **Settings**.'],
                    'el' => ['title' => 'Ρύθμιση email στο κινητό', 'body' => 'Ανοίξτε τις **Ρυθμίσεις**.'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $article = KbArticle::query()->sole();
        $this->assertSame('set-up-email-on-your-phone', $article->slug);
        $this->assertSame($this->admin->id, $article->created_by);
        $this->assertSame('Ρύθμιση email στο κινητό', $article->title('el'));
        $this->assertStringContainsString('ρυθμιση email στο κινητο', $article->search_text);
        $this->assertSame(2, KbCategory::count());

        Livewire::test(ListKbArticles::class)->assertCanSeeTableRecords([$article]);

        // Same title again: its own address
        $this->assertSame('set-up-email-on-your-phone-2', $this->article('Set up email on your phone', 'Again')->slug);
    }

    public function test_clients_see_published_articles_by_category_and_search_them(): void
    {
        $draft = $this->article('Draft', 'Not yet', ['published' => false]);
        $this->assertFalse(KnowledgeBase::enabled());

        $this->article('Ρύθμιση email στο κινητό', 'Ανοίξτε τις ρυθμίσεις του κινητού.');
        $this->article('Change nameservers', 'Use the Change nameservers button.', ['kb_category_id' => null]);
        $this->asClient();

        $this->get(ClientKnowledgeBase::getUrl())->assertOk()
            ->assertSee('Ρύθμιση email στο κινητό')
            ->assertSee('Other articles')
            ->assertDontSee('Draft');

        // Accents and case don't matter
        Livewire::test(ClientKnowledgeBase::class)
            ->set('search', 'ΡΥΘΜΙΣΗ κινητο')
            ->assertSee('Ρύθμιση email στο κινητό')
            ->assertDontSee('Change nameservers')
            ->set('search', 'nothing like it')
            ->assertSee('No article matches');

        $this->get(KnowledgeBaseArticle::getUrl(['slug' => $draft->slug]))->assertNotFound();

        config(['gaiacp.support.knowledge_base' => false]);
        $this->get(ClientKnowledgeBase::getUrl())->assertForbidden();
    }

    public function test_an_article_counts_its_views_and_one_answer_a_session(): void
    {
        $article = $this->article('Set up email', "## Steps\n\n1. Open **Settings**\n\n<script>alert(1)</script>");
        $this->article('Webmail', 'Go to /webmail.');
        $this->asClient();

        $this->get(KnowledgeBaseArticle::getUrl(['slug' => $article->slug]))->assertOk()
            ->assertSee('<strong>Settings</strong>', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Webmail');
        $this->get(KnowledgeBaseArticle::getUrl(['slug' => $article->slug]))->assertOk();
        $this->assertSame(1, $article->fresh()->views);

        Livewire::test(KnowledgeBaseArticle::class, ['slug' => $article->slug])
            ->call('vote', true)
            ->assertSee('Thank you for your answer.')
            ->call('vote', false);

        $this->assertSame([1, 0], [$article->fresh()->helpful_yes, $article->fresh()->helpful_no]);
    }

    public function test_a_new_ticket_offers_matching_articles(): void
    {
        $this->article('Set up email on your phone', 'Open Settings.');
        $this->article('Change nameservers', 'Use the button.');
        $this->asClient();

        $this->assertSame(['Set up email on your phone'], KnowledgeBase::suggestions('My email does not work')->map->title()->all());
        $this->assertTrue(KnowledgeBase::suggestions('hi')->isEmpty());

        Livewire::test(CreateTicket::class)
            ->fillForm(['subject' => 'Email on my phone'])
            ->assertSee('These articles may already answer it:')
            ->assertSee('Set up email on your phone')
            ->assertDontSee('Change nameservers');
    }
}
