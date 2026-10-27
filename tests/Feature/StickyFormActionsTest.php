<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Client\Pages\Auth\EditProfile as ClientProfile;
use App\Filament\Pages\Auth\EditProfile as AdminProfile;
use App\Filament\Pages\EditEmailTemplate;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Models\Client;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Enums\Alignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Save / Create float at the foot of the screen on long forms, styled like the Settings pages' Save bar. */
class StickyFormActionsTest extends TestCase
{
    use RefreshDatabase;

    private const STICKY = 'x-intersect:leave="enableSticky"';

    public function test_admin_forms_have_sticky_buttons_and_their_style(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();

        foreach ([CreateClient::getUrl(), EditClient::getUrl(['record' => $client]), EditEmailTemplate::getUrl(['template' => 'hosting_welcome'])] as $url) {
            $this->get($url)->assertOk()
                ->assertSee(self::STICKY, false)
                ->assertSee('.fi-sc-actions.fi-sticky .fi-ac', false);
        }
    }

    public function test_the_client_profile_has_sticky_buttons(): void
    {
        $client = Client::factory()->create(['email' => 'maria@example.gr']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]), 'client');

        $this->get(filament()->getPanel('client')->getProfileUrl())->assertOk()->assertSee(self::STICKY, false);
    }

    public function test_the_buttons_are_on_the_right_everywhere_like_the_settings_save_bar(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();

        $this->assertSame(Alignment::End, Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])->instance()->getFormActionsAlignment());
        $this->assertSame(Alignment::End, Livewire::test(AdminProfile::class)->instance()->getFormActionsAlignment());

        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]), 'client');
        Filament::setCurrentPanel('client');
        $this->assertSame(Alignment::End, Livewire::test(ClientProfile::class)->instance()->getFormActionsAlignment());

        $this->assertStringContainsString('.fi-sc-actions .fi-ac.fi-align-end', view('filament.sticky-actions')->render());
    }
}
