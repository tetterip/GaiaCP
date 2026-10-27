<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\TwoFactorReminder;
use App\Models\Client;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The "turn on two-factor authentication" notice at the top of both panels, until it is on or closed. */
class TwoFactorReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_without_2fa_sees_it_until_they_close_it(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Turn on two-factor authentication')->assertSee('/admin/profile');

        Filament::setCurrentPanel('admin');
        Livewire::actingAs($admin)->test(TwoFactorReminder::class)->call('dismiss')->assertDontSee('Turn on two-factor authentication');

        $this->assertNotNull($admin->refresh()->two_factor_reminder_dismissed_at);
        $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee('Turn on two-factor authentication');
    }

    public function test_a_client_sees_it_in_the_client_area(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);

        $this->actingAs($user, 'client')->get('/dashboard')->assertOk()->assertSee('Protect your account with two-factor authentication');
    }

    public function test_not_shown_once_2fa_is_on_or_on_the_profile_page(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get('/admin/profile')->assertOk()->assertDontSee('Turn on two-factor authentication');

        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($admin->refresh())->get('/admin')->assertOk()->assertDontSee('Turn on two-factor authentication');
    }
}
