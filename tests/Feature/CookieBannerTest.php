<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Enums\UserRole;
use App\Filament\Clusters\Settings\Pages\CookieSettings;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Cookies: the client area's cookie banner and the scripts that wait for "Accept all". */
class CookieBannerTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST"></script>';

    public function test_the_banner_is_off_until_switched_on(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('gaiacp-cookies', false);
    }

    public function test_without_scripts_the_banner_only_informs(): void
    {
        config(['gaiacp.cookies.banner' => true, 'gaiacp.cookies.policy_url' => 'https://example.com/cookies']);

        $this->get('/login')->assertOk()
            ->assertSee(['id="gaiacp-cookies"', 'https://example.com/cookies', __('Cookie settings')], false)
            ->assertSee(__('We use only the cookies this site needs to work, such as keeping you signed in.'))
            ->assertDontSee(__('Accept all'))
            ->assertDontSee('<template id="gaiacp-consent-scripts"', false);
    }

    public function test_scripts_wait_in_a_template_for_accept_all_and_never_reach_admin(): void
    {
        config(['gaiacp.cookies.banner' => true, 'gaiacp.cookies.scripts' => self::SCRIPT]);
        $client = Client::factory()->create(['email' => 'maria@example.gr']);
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);

        $this->actingAs($user, 'client')->get('/dashboard')->assertOk()
            ->assertSee(['<template id="gaiacp-consent-scripts">'.self::SCRIPT.'</template>', __('Only necessary'), __('Accept all')], false);

        $this->actingAs(User::factory()->create(), 'web')->get(EditClient::getUrl(['record' => $client], panel: 'admin'))
            ->assertOk()->assertDontSee('gaiacp-cookies', false)->assertDontSee('G-TEST', false);
    }

    public function test_only_super_admins_edit_it(): void
    {
        $super = User::factory()->create();
        $this->actingAs(User::factory()->limitedTo([AdminArea::ServiceSettings])->create())->get(CookieSettings::getUrl())->assertForbidden();

        $this->actingAs($super);
        Livewire::test(CookieSettings::class)
            ->set('data.cookies.banner', true)
            ->set('data.cookies.scripts', self::SCRIPT)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(config('gaiacp.cookies.banner'));
        $this->assertSame(self::SCRIPT, config('gaiacp.cookies.scripts'));
    }
}
