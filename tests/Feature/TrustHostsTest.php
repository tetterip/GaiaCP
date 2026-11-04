<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetAdminPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** GaiaCP answers only on APP_URL's address, so email links never point to a host an attacker sent. */
class TrustHostsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_host', fn (Request $request) => $request->getHost());
        config(['app.url' => 'https://billing.example']);
    }

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    public function test_only_the_install_s_own_address_is_answered(): void
    {
        $this->get('https://billing.example/_host')->assertOk()->assertSeeText('billing.example');
        $this->get('https://www.billing.example/_host')->assertOk();
        $this->get('https://BILLING.example/_host')->assertOk();

        $this->get('https://evil.example/_host')->assertBadRequest();
        $this->get('https://billing.example.evil.example/_host')->assertBadRequest();

        config(['app.url' => 'https://www.billing.example']);
        $this->get('https://billing.example/_host')->assertOk();
        $this->get('https://evil.example/_host')->assertBadRequest();

        config(['gaiacp.trusted_hosts' => ['second.example']]);
        $this->get('https://second.example/_host')->assertOk();
    }

    public function test_a_password_reset_asked_on_another_host_is_never_sent_with_it(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['email' => 'admin@billing.example']);

        $this->get('https://evil.example/admin/password-reset/request')->assertBadRequest();
        $this->post('https://evil.example/livewire/update')->assertBadRequest();

        Notification::assertNotSentTo($admin, ResetAdminPassword::class);
    }

    public function test_a_local_address_leaves_it_off_so_a_fresh_copy_opens(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->get('https://anything.example/_host')->assertOk();
    }
}
