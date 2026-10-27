<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemHealth as SystemHealthPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BehindCloudflareTest extends TestCase
{
    use RefreshDatabase;

    private const CLOUDFLARE = '162.158.10.20';

    private const VISITOR = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_ip', fn (Request $request) => $request->ip().' '.($request->secure() ? 'https' : 'http'));
    }

    private function ipFrom(string $remote, array $headers = []): string
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote])->withHeaders($headers)->get('/_ip')->assertOk()->getContent();
    }

    public function test_the_visitor_address_and_https_come_from_cloudflare(): void
    {
        $this->assertSame(self::VISITOR.' https', $this->ipFrom(self::CLOUDFLARE, [
            'X-Forwarded-For' => self::VISITOR,
            'X-Forwarded-Proto' => 'https',
        ]));
    }

    public function test_cloudflare_ipv6_edges_are_trusted_too(): void
    {
        $this->assertSame(self::VISITOR.' http', $this->ipFrom('2606:4700:10::1', ['X-Forwarded-For' => self::VISITOR]));
    }

    public function test_anyone_else_cannot_fake_their_address(): void
    {
        $this->assertSame('198.51.100.7 http', $this->ipFrom('198.51.100.7', [
            'X-Forwarded-For' => self::VISITOR,
            'X-Forwarded-Proto' => 'https',
        ]));
    }

    public function test_cloudflare_can_be_switched_off_and_other_proxies_added(): void
    {
        config(['gaiacp.proxies.cloudflare' => false]);
        $this->assertSame(self::CLOUDFLARE.' http', $this->ipFrom(self::CLOUDFLARE, ['X-Forwarded-For' => self::VISITOR]));

        config(['gaiacp.proxies.trusted' => ['10.0.0.0/8']]);
        $this->assertSame(self::VISITOR.' http', $this->ipFrom('10.1.2.3', ['X-Forwarded-For' => self::VISITOR]));
    }

    public function test_system_health_shows_cloudflare_in_use(): void
    {
        $this->actingAs(User::factory()->create());
        $cloudflare = ['CF-Connecting-IP' => self::VISITOR, 'X-Forwarded-For' => self::VISITOR, 'CF-Visitor' => '{"scheme":"https"}'];

        // Full (strict): Cloudflare reaches the server over https too
        $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE])->withHeaders($cloudflare)
            ->get(str_replace('http://', 'https://', SystemHealthPage::getUrl()))
            ->assertOk()
            ->assertSee('In use; visitor address '.self::VISITOR)
            ->assertDontSee('Flexible');
    }

    public function test_system_health_warns_when_visitor_addresses_are_cloudflare_s_or_ssl_is_flexible(): void
    {
        config(['gaiacp.proxies.cloudflare' => false]);
        $this->actingAs(User::factory()->create());

        $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE])
            ->withHeaders(['CF-Connecting-IP' => self::VISITOR, 'X-Forwarded-For' => self::VISITOR, 'CF-Visitor' => '{"scheme":"https"}'])
            ->get(SystemHealthPage::getUrl())
            ->assertOk()
            ->assertSee('visitor addresses are Cloudflare', false)
            ->assertSee('Full (strict)');
    }
}
