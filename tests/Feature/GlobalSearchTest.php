<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Filament\GlobalSearch\GlobalSearchProvider;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Livewire\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->client = Client::factory()->create([
            'first_name' => 'Νίκος', 'last_name' => 'Αντωνίου', 'company' => null, 'email' => 'info@acme.gr',
        ]);
        Service::factory()->for($this->client)->create(['label' => 'Acme Pro Ultra', 'domain' => 'acme.gr']);
        Service::factory()->for($this->client)->create([
            'label' => 'TurboGrow', 'domain' => 'acme.eu', 'status' => ServiceStatus::Suspended,
        ]);
        Service::factory()->domain()->for($this->client)->create(['domain' => 'acme.gr']);
        Service::factory()->create(['label' => 'Someone else', 'domain' => 'example.org']);
    }

    /** @return array<string, list<GlobalSearchResult>> */
    private function search(string $query): array
    {
        $this->get('/admin')->assertOk(); // boots the panel

        return (new GlobalSearchProvider)->getResults($query)->getCategories()
            ->map(fn ($results) => collect($results)->all())->all();
    }

    /**
     * Result titles as plain text: the title, then the status badge.
     *
     * @param  list<GlobalSearchResult>  $results
     * @return list<string>
     */
    private function titles(array $results): array
    {
        return array_map(
            fn (GlobalSearchResult $result): string => preg_replace('/\s+/', ' ', trim(strip_tags($result->title->toHtml()))),
            $results,
        );
    }

    public function test_a_domain_finds_the_client_their_services_and_the_domain_in_separate_groups(): void
    {
        $results = $this->search('acme');

        $this->assertSame(['Clients (1)', 'Products/Services (2)', 'Domains (1)'], array_keys($results));
        $this->assertSame(["Νίκος Αντωνίου #{$this->client->id} Active"], $this->titles($results['Clients (1)']));
        $this->assertSame(['info@acme.gr'], $results['Clients (1)'][0]->details);

        $this->assertSame(
            ['Acme Pro Ultra - acme.gr Active', 'TurboGrow - acme.eu Suspended'],
            $this->titles($results['Products/Services (2)']),
        );
        $this->assertSame(['Νίκος Αντωνίου'], $results['Products/Services (2)'][0]->details);

        $this->assertSame(['acme.gr Active'], $this->titles($results['Domains (1)']));
        $this->assertSame(['Νίκος Αντωνίου'], $results['Domains (1)'][0]->details);
    }

    public function test_a_client_name_also_finds_their_services(): void
    {
        $results = $this->search('Αντωνίου');

        $this->assertSame(['Clients (1)', 'Products/Services (2)', 'Domains (1)'], array_keys($results));
    }

    public function test_the_search_field_returns_results_in_the_panel(): void
    {
        $this->get('/admin')->assertOk();

        Livewire::test(GlobalSearch::class)
            ->set('search', 'acme')
            ->assertSee('Domains (1)')
            ->assertSee('Acme Pro Ultra - acme.gr')
            ->assertDontSee('example.org');
    }
}
