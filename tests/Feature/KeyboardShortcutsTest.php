<?php

namespace Tests\Feature;

use App\Filament\KeyboardShortcuts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
