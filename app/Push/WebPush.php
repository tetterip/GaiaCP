<?php

namespace App\Push;

use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use App\Support\ModuleLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Push notifications to the admins' devices (the bell, AdminAlerts), by the Web Push protocol with no library:
 * VAPID (RFC 8292) signs who sends, the payload is encrypted for the browser (RFC 8291, aes128gcm). The server's
 * VAPID key pair is made on first use and kept in `settings` (`push.vapid`, encrypted). A push goes out after the
 * response (and after the transaction commits); a device the push service no longer knows (404, 410) is removed.
 */
class WebPush
{
    private const SETTING = 'push.vapid';

    /** DER prefix of a P-256 public key (SubjectPublicKeyInfo) before its 65 raw bytes. */
    private const P256_SPKI = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** The bell's notification for this admin, sent to each of their devices once the request is done. */
    public static function notify(User $admin, Notification $notification): void
    {
        if (! $admin->pushSubscriptions()->exists()) {
            return;
        }

        $url = collect($notification->getActions())->first(fn ($action) => $action instanceof Action && filled($action->getUrl()))?->getUrl();
        $message = [
            'title' => strip_tags((string) $notification->getTitle()),
            'body' => Str::limit(trim(strip_tags((string) $notification->getBody())), 240),
            'url' => $url ?? url('/admin'),
            'tag' => $notification->getId(),
        ];
        $id = $admin->id;

        DB::afterCommit(fn () => defer(fn () => rescue(fn () => app(self::class)->sendTo(User::query()->find($id), $message)))->always());
    }

    /**
     * @param  array{title: string, body?: string, url?: string, tag?: string}  $message
     * @return int devices it reached
     */
    public function sendTo(?User $admin, array $message): int
    {
        if (! $admin) {
            return 0;
        }

        $sent = 0;
        foreach ($admin->pushSubscriptions as $subscription) {
            try {
                $sent += $this->send($subscription, $message) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /** @param  array<string, mixed>  $message */
    public function send(PushSubscription $subscription, array $message): bool
    {
        if (! self::isPushService($subscription->endpoint)) {
            throw new RuntimeException('Not a push service address');
        }

        $response = Http::withHeaders([
            'Authorization' => $this->vapidHeader($subscription->endpoint),
            'Content-Encoding' => 'aes128gcm',
            'TTL' => '86400', // a day: a phone that was off still gets it
            'Urgency' => 'high',
        ])
            ->withBody($this->encrypt((string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $subscription->public_key, $subscription->auth_token), 'application/octet-stream')
            ->withMiddleware(ModuleLogger::middleware('push', 'send'))
            ->timeout(10)
            ->post($subscription->endpoint);

        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete(); // unsubscribed in the browser, or expired

            return false;
        }

        if (! $response->successful()) {
            throw new RuntimeException("Push to {$subscription->device()} failed: HTTP {$response->status()} ".Str::limit($response->body(), 200));
        }

        $subscription->forceFill(['last_sent_at' => now()])->save();

        return true;
    }

    /** The browsers' push services (Chrome / Edge / Android, Firefox, Safari / iOS, Windows): the only addresses a push goes to. */
    public const PUSH_HOSTS = ['fcm.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

    public static function isPushService(string $endpoint): bool
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        return str_starts_with($endpoint, 'https://') && collect(self::PUSH_HOSTS)
            ->contains(fn (string $allowed) => $host === $allowed || str_ends_with($host, '.'.$allowed));
    }

    /** The server's public key, base64url, for the browser's pushManager.subscribe(). */
    public function publicKey(): string
    {
        return $this->keys()['public'];
    }

    /** The encrypted body (RFC 8291): salt, record size, the server's one-time public key, then the AES-GCM record. */
    public function encrypt(string $payload, string $browserKey, string $authSecret): string
    {
        $browserPublic = self::decode($browserKey);
        $auth = self::decode($authSecret);
        if (strlen($browserPublic) !== 65 || strlen($auth) < 16) {
            throw new RuntimeException('Not a push subscription key');
        }

        $local = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $localPublic = self::rawPublic($local);
        $shared = openssl_pkey_derive(self::publicKeyPem($browserPublic), $local, 32);
        if ($shared === false) {
            throw new RuntimeException('Could not derive the push key');
        }

        $ikm = self::hkdf($auth, $shared, "WebPush: info\0".$browserPublic.$localPublic, 32);
        $salt = random_bytes(16);
        $key = self::hkdf($salt, $ikm, "Content-Encoding: aes128gcm\0", 16);
        $nonce = self::hkdf($salt, $ikm, "Content-Encoding: nonce\0", 12);

        $tag = '';
        $cipher = openssl_encrypt($payload."\x02", 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

        return $salt.pack('N', 4096).chr(65).$localPublic.$cipher.$tag;
    }

    /** "vapid t=<JWT>, k=<public key>": an ES256 token for the push service's origin, valid for 12 hours. */
    public function vapidHeader(string $endpoint): string
    {
        $keys = $this->keys();
        $origin = parse_url($endpoint, PHP_URL_SCHEME).'://'.parse_url($endpoint, PHP_URL_HOST);
        $contact = filled(config('gaiacp.company.email')) ? 'mailto:'.config('gaiacp.company.email') : url('/');

        $input = self::encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            .'.'.self::encode((string) json_encode(['aud' => $origin, 'exp' => now()->addHours(12)->timestamp, 'sub' => $contact], JSON_UNESCAPED_SLASHES));
        openssl_sign($input, $der, $keys['private'], OPENSSL_ALGO_SHA256);

        return 'vapid t='.$input.'.'.self::encode(self::derToRaw($der)).', k='.$keys['public'];
    }

    /** @return array{public: string, private: string} made once and kept, so the browsers' subscriptions stay valid */
    private function keys(): array
    {
        $stored = Setting::query()->where('key', self::SETTING)->value('value');
        try {
            $keys = $stored ? json_decode(Crypt::decryptString($stored), true) : null;
        } catch (DecryptException) {
            $keys = null; // APP_KEY changed: new keys, the devices turn push on again
        }

        if (! is_array($keys) || ! isset($keys['public'], $keys['private'])) {
            $pair = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            openssl_pkey_export($pair, $private);
            $keys = ['public' => self::encode(self::rawPublic($pair)), 'private' => $private];
            Setting::query()->updateOrCreate(['key' => self::SETTING], ['value' => Crypt::encryptString((string) json_encode($keys))]);
            if ($stored) {
                PushSubscription::query()->delete(); // made for the old keys, the push services would refuse them
            }
        }

        return $keys;
    }

    private static function rawPublic(\OpenSSLAsymmetricKey $key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'];

        return "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function publicKeyPem(string $raw): \OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::P256_SPKI).$raw), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new RuntimeException('Not a P-256 public key');
        }

        return $key;
    }

    /** HKDF-SHA256 with one block of output (RFC 5869), all Web Push needs. */
    private static function hkdf(string $salt, string $ikm, string $info, int $length): string
    {
        $prk = hash_hmac('sha256', $ikm, $salt, true);

        return substr(hash_hmac('sha256', $info."\x01", $prk, true), 0, $length);
    }

    /** An ECDSA signature from OpenSSL's DER (SEQUENCE of two INTEGERs) to JWT's 64 raw bytes. */
    private static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $parts = '';
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $int = ltrim(substr($der, $offset + 2, $length), "\0");
            $parts .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts;
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'), true);
    }
}
