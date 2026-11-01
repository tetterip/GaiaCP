<?php

namespace App\SignIn\Passkeys;

use App\Models\Passkey;
use App\Models\User;
use App\Support\Demo;

/**
 * Passkeys (WebAuthn, https://www.w3.org/TR/webauthn-3/) on both logins: a login adds one from its profile, then logs
 * in with its fingerprint, face or screen lock instead of a password. Only the public key is kept here (Passkey);
 * the browser proves it holds the private key by signing a one-time challenge kept in the session.
 *
 * Passkeys are discoverable (the login page needs no email address) and always ask the user to unlock the device
 * (user verification), so they count as two factors on their own: two-factor authentication is not asked after one.
 * Attestation is not asked for ("none"), so no list of device makers is kept. ES256 and RS256 keys are accepted.
 * The site's host name is the relying party, so passkeys keep working after a move to a subfolder of the same host.
 */
class Passkeys
{
    private const CHALLENGE = 'passkeys.challenge.';

    private const CHALLENGE_SECONDS = 300;

    /** Settings → Login security → Passkeys (not on the public demo, whose logins everyone shares). */
    public static function enabled(): bool
    {
        return (bool) config('gaiacp.sign_in.passkeys') && ! Demo::enabled();
    }

    /**
     * What navigator.credentials.create() needs to add a passkey for $user (binary values base64url).
     *
     * @return array<string, mixed>
     */
    public function creationOptions(User $user): array
    {
        return [
            'challenge' => $this->challenge('create'),
            'rp' => ['id' => self::rpId(), 'name' => self::rpName()],
            'user' => ['id' => self::encode(self::userHandle($user)), 'name' => $user->email, 'displayName' => $user->name ?: $user->email],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => CoseKey::ES256], ['type' => 'public-key', 'alg' => CoseKey::RS256]],
            'timeout' => self::CHALLENGE_SECONDS * 1000,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'excludeCredentials' => $user->passkeys()->pluck('credential_id')
                ->map(fn (string $id) => ['type' => 'public-key', 'id' => $id])->all(),
        ];
    }

    /**
     * The browser's answer to creationOptions() (PublicKeyCredential, binary values base64url), saved as $name.
     *
     * @param  array<string, mixed>  $credential
     *
     * @throws PasskeyException
     */
    public function register(User $user, array $credential, string $name): Passkey
    {
        $this->clientData((string) ($credential['response']['clientDataJSON'] ?? ''), 'create');
        $attestation = Cbor::decode(self::decode((string) ($credential['response']['attestationObject'] ?? '')));

        if (! is_array($attestation) || ! is_string($attestation['authData'] ?? null)) {
            throw new PasskeyException('No authenticator data');
        }

        $authData = $attestation['authData'];
        $signCount = $this->checkAuthData($authData);

        if ((ord($authData[32]) & 0x40) === 0 || strlen($authData) < 55) {
            throw new PasskeyException('No credential in the authenticator data');
        }

        $idLength = unpack('n', substr($authData, 53, 2))[1];
        $id = substr($authData, 55, $idLength);
        $key = Cbor::decode($authData, 55 + $idLength);

        if ($idLength === 0 || strlen($id) !== $idLength || ! hash_equals(self::encode($id), self::normalise((string) ($credential['id'] ?? '')))) {
            throw new PasskeyException('The credential ID does not match');
        }

        if (! is_array($key)) {
            throw new PasskeyException('No public key');
        }

        if (Passkey::query()->where('credential_id', self::encode($id))->exists()) {
            throw new PasskeyException('This passkey is already added');
        }

        return $user->passkeys()->create([
            'name' => mb_substr(trim($name), 0, 100),
            'credential_id' => self::encode($id),
            'public_key' => CoseKey::toPem($key),
            'sign_count' => $signCount,
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
        ]);
    }

    /**
     * What navigator.credentials.get() needs on the login page: any passkey of this site.
     *
     * @return array<string, mixed>
     */
    public function requestOptions(): array
    {
        return [
            'challenge' => $this->challenge('get'),
            'rpId' => self::rpId(),
            'timeout' => self::CHALLENGE_SECONDS * 1000,
            'userVerification' => 'required',
            'allowCredentials' => [],
        ];
    }

    /**
     * The passkey that signed the challenge of requestOptions() (its login is not checked here: the caller does).
     *
     * @param  array<string, mixed>  $credential  the browser's PublicKeyCredential, binary values base64url
     *
     * @throws PasskeyException
     */
    public function verify(array $credential): Passkey
    {
        $clientJson = $this->clientData((string) ($credential['response']['clientDataJSON'] ?? ''), 'get');
        $passkey = Passkey::query()->with('user')->where('credential_id', self::normalise((string) ($credential['id'] ?? '')))->first();

        if ($passkey === null || $passkey->user === null) {
            throw new PasskeyException('Unknown passkey');
        }

        $authData = self::decode((string) ($credential['response']['authenticatorData'] ?? ''));
        $signCount = $this->checkAuthData($authData);
        $handle = (string) ($credential['response']['userHandle'] ?? '');

        if ($handle !== '' && ! hash_equals(self::userHandle($passkey->user), self::decode($handle))) {
            throw new PasskeyException('The passkey belongs to another login');
        }

        $signed = $authData.hash('sha256', $clientJson, true);

        if (openssl_verify($signed, self::decode((string) ($credential['response']['signature'] ?? '')), $passkey->public_key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new PasskeyException('Wrong signature');
        }

        // A counter that goes back means a copied key; passkeys synced between devices keep it at 0
        if (($signCount > 0 || $passkey->sign_count > 0) && $signCount <= $passkey->sign_count) {
            throw new PasskeyException('The signature counter went back');
        }

        $passkey->forceFill(['sign_count' => $signCount, 'last_used_at' => now()])->save();

        return $passkey;
    }

    /** The relying party: this site's host name, without port. */
    public static function rpId(): string
    {
        return request()->getHost();
    }

    private static function rpName(): string
    {
        return (string) (config('gaiacp.company.name') ?: config('app.name'));
    }

    /** The user.id the passkey keeps: not the email address (the spec asks for no personal data), the same every time. */
    public static function userHandle(User $user): string
    {
        return hash('sha256', 'gaiacp-passkey-user:'.$user->getKey(), true);
    }

    /** A new challenge for $ceremony, kept in the session until it is answered once. */
    private function challenge(string $ceremony): string
    {
        $challenge = self::encode(random_bytes(32));
        session()->put(self::CHALLENGE.$ceremony, ['value' => $challenge, 'expires' => time() + self::CHALLENGE_SECONDS]);

        return $challenge;
    }

    /** The client data JSON, checked: the ceremony, the challenge of this session (used up) and this site's origin. */
    private function clientData(string $encoded, string $ceremony): string
    {
        $expected = session()->pull(self::CHALLENGE.$ceremony);
        $json = self::decode($encoded);
        $data = json_decode($json, true);

        if (! is_array($expected) || ($expected['expires'] ?? 0) < time()) {
            throw new PasskeyException('The challenge expired');
        }

        if (! is_array($data) || ($data['type'] ?? null) !== 'webauthn.'.$ceremony) {
            throw new PasskeyException('Not a WebAuthn answer');
        }

        if (! hash_equals((string) $expected['value'], self::normalise((string) ($data['challenge'] ?? '')))) {
            throw new PasskeyException('Another challenge');
        }

        if (($data['origin'] ?? null) !== request()->getSchemeAndHttpHost() || ($data['crossOrigin'] ?? false) === true) {
            throw new PasskeyException('Another origin');
        }

        return $json;
    }

    /** The authenticator data's site, user present and verified flags; its signature counter. */
    private function checkAuthData(string $authData): int
    {
        if (strlen($authData) < 37 || ! hash_equals(hash('sha256', self::rpId(), true), substr($authData, 0, 32))) {
            throw new PasskeyException('Made for another site');
        }

        $flags = ord($authData[32]);

        if (($flags & 0x01) === 0 || ($flags & 0x04) === 0) {
            throw new PasskeyException('The user was not verified');
        }

        return unpack('N', substr($authData, 33, 4))[1];
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $text): string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        if ($bytes === false) {
            throw new PasskeyException('Not base64url');
        }

        return $bytes;
    }

    /** The same ID in base64url without padding, whichever form the browser used. */
    private static function normalise(string $text): string
    {
        return self::encode(self::decode($text));
    }
}
