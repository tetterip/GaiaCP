<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Two clients may share an email (a personal and a company client), but the form warns, since only one can log in. */
class ClientSameEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_the_email_field_names_the_other_client_with_that_email(): void
    {
        $other = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papa', 'email' => 'Maria@Example.gr']);

        Livewire::test(CreateClient::class)
            ->fillForm(['email' => 'maria@example.gr'])
            ->assertSee('Maria Papa')
            ->assertSee(e(EditClient::getUrl(['record' => $other])), false);
    }

    public function test_a_client_is_not_warned_about_itself_and_saving_still_works(): void
    {
        $client = Client::factory()->create(['email' => 'solo@example.gr']);

        $this->assertNull(ClientForm::sameEmail('solo@example.gr', $client));
        $this->assertNull(ClientForm::sameEmail('not-an-email', null));
        $this->assertNotNull(ClientForm::sameEmail('SOLO@example.gr', null));

        $twin = Client::factory()->create(['email' => 'other@example.gr']);
        Livewire::test(EditClient::class, ['record' => $twin->getRouteKey()])
            ->fillForm(['email' => 'solo@example.gr'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('solo@example.gr', $twin->refresh()->email);
    }
}
