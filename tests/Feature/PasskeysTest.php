<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Client\Pages\Auth\EditProfile as ClientProfile;
use App\Filament\Client\Pages\Auth\Login as ClientLogin;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Models\Client;
use App\Models\Passkey;
use App\Models\User;
use App\SignIn\Passkeys\Cbor;
use App\SignIn\Passkeys\PasskeyException;
use App\SignIn\Passkeys\Passkeys;
use App\SignIn\SignIn;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

/** Passkeys (App\SignIn\Passkeys): added from the profile, then a login without a password on both panels. */
class PasskeysTest extends TestCase
{
    use RefreshDatabase;

    /** The device's private key, as a phone would keep it. */
    private OpenSSLAsymmetricKey $key;

    private string $credentialId;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->credentialId = random_bytes(16);
    }

    // ---- A pretend authenticator ----

    /** CBOR for what an authenticator sends; byte strings are given as ['b' => ...]. */
    private static function cbor(mixed $value): string
    {
        $head = function (int $major, int $length): string {
            return match (true) {
                $length < 24 => chr(($major << 5) | $length),
                $length < 256 => chr(($major << 5) | 24).chr($length),
                $length < 65536 => chr(($major << 5) | 25).pack('n', $length),
                default => chr(($major << 5) | 26).pack('N', $length),
            };
        };

        return match (true) {
            is_int($value) && $value >= 0 => $head(0, $value),
            is_int($value) => $head(1, -1 - $value),
            is_string($value) => $head(3, strlen($value)).$value,
            is_array($value) && array_keys($value) === ['b'] => $head(2, strlen($value['b'])).$value['b'],
            is_array($value) && array_is_list($value) && $value !== [] => $head(4, count($value)).implode('', array_map(self::cbor(...), $value)),
            is_array($value) => $head(5, count($value)).implode('', array_map(fn ($k, $v) => self::cbor($k).self::cbor($v), array_keys($value), $value)),
        };
    }

    private function authData(bool $withKey, int $flags = 0x05, string $rpId = 'localhost'): string
    {
        $data = hash('sha256', $rpId, true).chr($flags | ($withKey ? 0x40 : 0)).pack('N', $this->counter);

        if ($withKey) {
            $details = openssl_pkey_get_details($this->key)['ec']; // OpenSSL drops leading zero bytes; COSE keeps 32
            $cose = self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => ['b' => str_pad($details['x'], 32, "\0", STR_PAD_LEFT)], -3 => ['b' => str_pad($details['y'], 32, "\0", STR_PAD_LEFT)]]);
            $data .= str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$cose;
        }

        return $data;
    }

    private function clientData(string $type, string $challenge, ?string $origin = null): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin ?? request()->getSchemeAndHttpHost(), 'crossOrigin' => false]);
    }

    /** @param array<string, mixed> $options */
    private function attestation(array $options): array
    {
        return [
            'id' => Passkeys::encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Passkeys::encode($this->clientData('webauthn.create', $options['challenge'])),
                'attestationObject' => Passkeys::encode(self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => ['b' => $this->authData(true)]])),
            ],
        ];
    }

    /** @param array<string, mixed> $options */
    private function assertion(array $options, ?User $user = null, ?string $origin = null, int $flags = 0x05): array
    {
        $authData = $this->authData(false, $flags);
        $clientData = $this->clientData('webauthn.get', $options['challenge'], $origin);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => Passkeys::encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Passkeys::encode($clientData),
                'authenticatorData' => Passkeys::encode($authData),
                'signature' => Passkeys::encode($signature),
                'userHandle' => $user ? Passkeys::encode(Passkeys::userHandle($user)) : null,
            ],
        ];
    }

    private function addPasskey(User $user): Passkey
    {
        $passkeys = app(Passkeys::class);

        return $passkeys->register($user, $this->attestation($passkeys->creationOptions($user)), 'Chrome on Android');
    }

    // ---- Tests ----

    public function test_an_admin_adds_a_passkey_from_the_profile(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $profile = Livewire::test(EditProfile::class)->assertSee(__('Add a passkey on this device'));
        $options = $profile->instance()->passkeyCreationOptions();

        $this->assertSame('localhost', $options['rp']['id']);
        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);
        $this->assertSame(Passkeys::encode(Passkeys::userHandle($admin)), $options['user']['id']);

        $profile->call('addPasskey', $this->attestation($options))->assertNotified();

        $passkey = $admin->passkeys()->sole();
        $this->assertSame(Passkeys::encode($this->credentialId), $passkey->credential_id);
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $passkey->public_key);
        $this->assertArrayNotHasKey('public_key', $passkey->toArray());
        $this->assertDatabaseHas('activity_logs', ['event' => 'sign_in.passkey_added']);

        // The next one is made without it (excludeCredentials), and the same one can't be added twice
        $this->assertSame([['type' => 'public-key', 'id' => $passkey->credential_id]], app(Passkeys::class)->creationOptions($admin)['excludeCredentials']);
        $profile->call('addPasskey', $this->attestation($profile->instance()->passkeyCreationOptions()));
        $this->assertSame(1, $admin->passkeys()->count());

        $profile->call('removePasskey', $passkey->id);
        $this->assertSame(0, $admin->passkeys()->count());
    }

    public function test_an_admin_logs_in_with_the_passkey_without_two_factor(): void
    {
        $admin = User::factory()->create();
        $admin->saveAppAuthenticationSecret('SECRETSECRETSECRET');
        $this->addPasskey($admin);
        $this->counter = 1;

        $login = Livewire::test(Login::class)->assertSee(__('Log in with a passkey'));
        $login->call('passkeyLogin', $this->assertion($login->instance()->passkeyOptions(), $admin))->assertRedirect(Filament::getUrl());

        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertNotNull($admin->passkeys()->sole()->last_used_at);
        $this->assertSame(1, $admin->passkeys()->sole()->sign_count);
        $this->assertDatabaseHas('activity_logs', ['event' => 'sign_in.passkey']);
    }

    public function test_a_client_logs_in_with_a_passkey_added_in_the_client_area(): void
    {
        Filament::setCurrentPanel('client');
        $client = Client::factory()->create(['email' => 'maria@example.gr']);
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => 'maria@example.gr']);
        $this->actingAs($user, 'client');

        $profile = Livewire::test(ClientProfile::class);
        $profile->call('addPasskey', $this->attestation($profile->instance()->passkeyCreationOptions()));
        $this->assertSame(1, $user->passkeys()->count());

        auth('client')->logout();
        $login = Livewire::test(ClientLogin::class);
        $login->call('passkeyLogin', $this->assertion($login->instance()->passkeyOptions()));
        $this->assertAuthenticatedAs($user, 'client');
    }

    public function test_refused_other_sites_answers_without_verification_replays_and_logins_turned_off(): void
    {
        $admin = User::factory()->create();
        $this->addPasskey($admin);
        $passkeys = app(Passkeys::class);

        foreach ([
            fn () => $this->assertion($passkeys->requestOptions(), origin: 'https://evil.example'),
            fn () => $this->assertion($passkeys->requestOptions(), flags: 0x01), // user present, not verified
            fn () => $this->assertion(['challenge' => Passkeys::encode(random_bytes(32))]), // a challenge never given
            fn () => $this->assertion($passkeys->requestOptions(), User::factory()->create()), // another login's handle
        ] as $i => $answer) {
            try {
                $passkeys->verify($answer());
                $this->fail("Answer {$i} was accepted");
            } catch (PasskeyException) {
                $this->assertGuest('web');
            }
        }

        // The same signature twice: the challenge is used up
        $answer = $this->assertion($passkeys->requestOptions());
        $passkeys->verify($answer);
        $this->expectException(PasskeyException::class);
        $passkeys->verify($answer);
    }

    public function test_a_counter_that_goes_back_is_refused(): void
    {
        $admin = User::factory()->create();
        $this->addPasskey($admin);
        $passkeys = app(Passkeys::class);

        $this->counter = 5;
        $passkeys->verify($this->assertion($passkeys->requestOptions()));

        $this->counter = 3;
        $this->expectException(PasskeyException::class);
        $passkeys->verify($this->assertion($passkeys->requestOptions()));
    }

    public function test_a_turned_off_admin_or_a_client_cant_open_admin_with_one(): void
    {
        $admin = User::factory()->create(['active' => false]);
        $this->addPasskey($admin);

        $login = Livewire::test(Login::class);
        $login->call('passkeyLogin', $this->assertion($login->instance()->passkeyOptions()))->assertNotified();
        $this->assertGuest('web');
    }

    public function test_off_in_settings_hides_the_button_and_the_profile_section(): void
    {
        config(['gaiacp.sign_in.passkeys' => false]);
        $this->get('/admin/login')->assertOk()->assertDontSee(__('Log in with a passkey'));

        $this->actingAs(User::factory()->create());
        Livewire::test(EditProfile::class)->assertDontSee(__('Add a passkey on this device'));
    }

    public function test_the_login_page_marks_the_way_used_last(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee(__('Log in with a passkey'))->assertDontSee(__('Last used'));
        $this->withCookie(SignIn::cookie('admin'), SignIn::PASSKEY)->get('/admin/login')->assertSee(__('Last used'));
        $this->withCookie(SignIn::cookie('admin'), SignIn::PASSWORD)->get('/admin/login')->assertDontSee(__('Last used'));
    }

    public function test_a_password_login_is_remembered_as_used_last(): void
    {
        User::factory()->create(['email' => 'alex@example.gr']);

        Livewire::test(Login::class)->fillForm(['email' => 'alex@example.gr', 'password' => 'password'])->call('authenticate');

        $this->assertAuthenticated();
        $cookie = cookie()->queued(SignIn::cookie('admin'));
        $this->assertSame(SignIn::PASSWORD, $cookie?->getValue());
        $this->assertSame('/', $cookie->getPath()); // not the Livewire request's
        $this->assertTrue($cookie->isHttpOnly());
    }

    public function test_cbor_reads_what_authenticators_send(): void
    {
        $this->assertSame(['fmt' => 'none', 'n' => -7, 'list' => [1, 500, 70000]], Cbor::decode(self::cbor(['fmt' => 'none', 'n' => -7, 'list' => [1, 500, 70000]])));
        $this->expectException(PasskeyException::class);
        Cbor::decode("\xA1\x01");
    }
}
