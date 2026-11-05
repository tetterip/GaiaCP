<?php

namespace Tests\Feature;

use App\Filament\KeyboardShortcuts;
use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KeyboardShortcutsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_shortcut_opens_a_page(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (KeyboardShortcuts::urls() as $keys => $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_shortcuts_are_unique_two_key_sequences(): void
    {
        $keys = collect(KeyboardShortcuts::sequences())->flatMap(fn (array $group) => array_keys($group));

        $this->assertSame($keys->count(), count(KeyboardShortcuts::urls()));
        $keys->each(fn (string $sequence) => $this->assertMatchesRegularExpression('/^[gn] [a-z]$/', $sequence));
    }

    public function test_admin_pages_carry_the_shortcuts_and_their_list(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/clients')
            ->assertOk()
            ->assertSee('open-keyboard-shortcuts', false)
            ->assertSee('Keyboard shortcuts')
            ->assertSee('New order');
    }

    public function test_the_login_page_has_no_shortcuts(): void
    {
        $this->get('/admin/login')->assertOk()->assertDontSee('open-keyboard-shortcuts', false);
    }

    public function test_the_list_holds_the_focus_and_has_a_close_button(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/clients')->assertOk()
            ->assertSee('x-trap.noscroll="open"', false)
            ->assertSee('enabled: true', false);
    }

    /** WCAG 2.1.4: single-key shortcuts can be turned off, e.g. for speech input; the user menu still opens the list. */
    public function test_an_admin_can_turn_the_single_keys_off_in_the_profile(): void
    {
        $user = User::factory()->create();
        $this->assertTrue($user->fresh()->keyboard_shortcuts);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm(['keyboard_shortcuts' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($user->fresh()->keyboard_shortcuts);
        $this->get('/admin/clients')->assertOk()
            ->assertSee('enabled: false', false)
            ->assertSee('open-keyboard-shortcuts', false)
            ->assertSee('They are turned off in your profile.');
    }
}
