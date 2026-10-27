<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Filament\Pages\Auth\EditProfile;
use App\Models\ActivityLog;
use App\Models\ModuleLog;
use App\Models\PushSubscription;
use App\Models\User;
use App\Push\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** The admin panel as an app (manifest, service worker) and push notifications for the bell (App\Push\WebPush). */
class WebPushTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc:123';

    /** @var array{key: \OpenSSLAsymmetricKey, public: string, auth: string} a browser's key pair and auth secret */
    private array $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();

        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($key)['ec'];
        $this->browser = ['key' => $key, 'public' => "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT), 'auth' => random_bytes(16)];
    }

    /** @return array{endpoint: string, keys: array{p256dh: string, auth: string}} as PushSubscription.toJSON() gives it */
    private function subscription(string $endpoint = self::ENDPOINT): array
    {
        return ['endpoint' => $endpoint, 'expirationTime' => null, 'keys' => ['p256dh' => WebPush::encode($this->browser['public']), 'auth' => WebPush::encode($this->browser['auth'])]];
    }

    private function subscribe(User $admin, string $endpoint = self::ENDPOINT): PushSubscription
    {
        return PushSubscription::query()->create([
            'user_id' => $admin->id, 'endpoint_hash' => hash('sha256', $endpoint), 'endpoint' => $endpoint,
            'public_key' => WebPush::encode($this->browser['public']), 'auth_token' => WebPush::encode($this->browser['auth']),
        ]);
    }

    private static function pem(string $raw): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$raw), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /** What the browser does with a push (RFC 8291), written apart from WebPush with PHP's own hash_hkdf. */
    private function decrypt(string $body): string
    {
        $salt = substr($body, 0, 16);
        $this->assertSame(4096, unpack('N', substr($body, 16, 4))[1]);
        $this->assertSame(65, ord($body[20]));
        $serverPublic = substr($body, 21, 65);

        $shared = openssl_pkey_derive(self::pem($serverPublic), $this->browser['key'], 32);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$this->browser['public'].$serverPublic, $this->browser['auth']);
        $key = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $record = substr($body, 86);

        $plain = openssl_decrypt(substr($record, 0, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, substr($record, -16));
        $this->assertNotFalse($plain);
        $this->assertSame("\x02", substr($plain, -1));

        return substr($plain, 0, -1);
    }

    /** The VAPID token is signed by the key the browser was given, for the push service's origin. */
    private function assertVapid(string $header, string $origin): void
    {
        $this->assertSame(1, preg_match('/^vapid t=([^.]+)\.([^.]+)\.([^,]+), k=(.+)$/', $header, $parts));
        $this->assertSame(app(WebPush::class)->publicKey(), $parts[4]);
        $claims = json_decode(WebPush::decode($parts[2]), true);
        $this->assertSame($origin, $claims['aud']);
        $this->assertGreaterThan(now()->timestamp, $claims['exp']);

        // JWT's raw r || s back to DER for openssl_verify
        $der = fn (string $int) => "\x02".chr(strlen($int = ltrim($int, "\0") ?: "\0") + (ord($int[0]) > 0x7F ? 1 : 0)).(ord($int[0]) > 0x7F ? "\0" : '').$int;
        $signature = WebPush::decode($parts[3]);
        $sequence = $der(substr($signature, 0, 32)).$der(substr($signature, 32));
        $this->assertSame(1, openssl_verify("{$parts[1]}.{$parts[2]}", "\x30".chr(strlen($sequence)).$sequence, self::pem(WebPush::decode($parts[4])), OPENSSL_ALGO_SHA256));
    }

    public function test_the_panel_is_installable_with_a_manifest_and_a_service_worker(): void
    {
        $this->get('/admin/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('start_url', url('/admin'))
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('icons.2.purpose', 'maskable');

        $this->get('/admin/sw.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8')->assertSee("addEventListener('push'", false);
        $this->assertFileExists(public_path('images/gaiacp/icon-512.png'));

        $this->get('/admin/login')->assertSee(route('pwa.manifest'), false)->assertSee('apple-touch-icon', false);
    }

    public function test_a_bell_notification_is_pushed_encrypted_to_the_admins_devices(): void
    {
        $admin = User::factory()->create();
        $this->subscribe($admin);
        $limited = User::factory()->limitedTo([AdminArea::Clients])->create();
        $this->subscribe($limited, 'https://fcm.googleapis.com/fcm/send/other');
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        config(['gaiacp.debug.module_log' => true]);

        DB::transaction(fn () => ActivityLog::record('server.down', 'Server pl1 is down', properties: ['server_name' => 'pl1']));

        Http::assertSentCount(1); // server problems are for super admins only
        Http::assertSent(function (Request $request) {
            $this->assertSame(self::ENDPOINT, $request->url());
            $this->assertSame('aes128gcm', $request->header('Content-Encoding')[0]);
            $this->assertVapid($request->header('Authorization')[0], 'https://fcm.googleapis.com');

            $message = json_decode($this->decrypt($request->body()), true);
            $this->assertStringContainsString('is down', $message['title']);
            $this->assertStringEndsWith('/admin/settings/servers', $message['url']);

            return true;
        });
        $this->assertNotNull(PushSubscription::query()->where('user_id', $admin->id)->value('last_sent_at'));
        $this->assertStringContainsString('bytes of binary data', (string) ModuleLog::query()->where('module', 'push')->value('request'));
    }

    public function test_nothing_is_pushed_after_a_rollback_and_a_gone_device_is_removed(): void
    {
        $admin = User::factory()->create();
        $this->subscribe($admin);
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 410)]);

        try {
            DB::transaction(function () {
                ActivityLog::record('server.down', 'Server pl1 is down');
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }
        Http::assertNothingSent();

        ActivityLog::record('server.down', 'Server pl1 is down');
        Http::assertSentCount(1);
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_the_profile_turns_push_on_and_off_for_this_device_only_at_a_push_service(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(EditProfile::class)
            ->call('savePushSubscription', $this->subscription('https://169.254.169.254/latest'))
            ->assertNotified(__('Push notifications could not be turned on'))
            ->call('savePushSubscription', $this->subscription())
            ->assertSee('Send a test');

        $subscription = PushSubscription::query()->sole();
        $this->assertSame($admin->id, $subscription->user_id);
        $this->assertStringNotContainsString(WebPush::encode($this->browser['auth']), (string) DB::table('push_subscriptions')->value('auth_token'));

        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        Livewire::test(EditProfile::class)
            ->call('sendTestPush')
            ->assertNotified(trans_choice('{1} Sent to :count device|[2,*] Sent to :count devices', 1, ['count' => 1]));
        Http::assertSent(fn (Request $request) => json_decode($this->decrypt($request->body()), true)['title'] === 'Test notification from GaiaCP');

        // Another admin can't remove it; the device itself can
        $this->actingAs(User::factory()->create());
        Livewire::test(EditProfile::class)->call('removePushDevice', $subscription->id)->call('deletePushSubscription', self::ENDPOINT);
        $this->assertSame(1, PushSubscription::query()->count());

        $this->actingAs($admin);
        Livewire::test(EditProfile::class)->call('deletePushSubscription', self::ENDPOINT);
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_the_server_keys_are_made_once_and_kept_encrypted(): void
    {
        $key = app(WebPush::class)->publicKey();

        $this->assertSame(65, strlen(WebPush::decode($key)));
        $this->assertSame($key, app(WebPush::class)->publicKey());
        $this->assertStringNotContainsString('PRIVATE KEY', (string) DB::table('settings')->where('key', 'push.vapid')->value('value'));
    }
}
