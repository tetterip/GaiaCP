<?php

namespace Plugins\GoogleLogin;

use App\Support\ModuleLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Google's OAuth 2.0 / OpenID Connect web flow: the consent page, then the code exchanged for a token (with the
 * client secret, server to server) and the signed-in account read from the userinfo endpoint over TLS, so the
 * ID token needs no signature check of its own. Calls are in the module log (google_login) with secrets masked.
 */
class GoogleOAuth
{
    public const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    /** Whether the client ID and secret are filled in (no button until they are). */
    public static function configured(): bool
    {
        return filled(config('gaiacp.google_login.client_id')) && filled(config('gaiacp.google_login.client_secret'));
    }

    /** Whether admins may log in to /admin with Google too (the plugin's settings). */
    public static function forAdmins(): bool
    {
        return (bool) config('gaiacp.google_login.admins');
    }

    /** An "Authorized redirect URI" to register on the OAuth client in Google Cloud Console: $panel's (client, admin). */
    public static function redirectUri(string $panel = 'client'): string
    {
        return route("filament.{$panel}.google-login.callback");
    }

    public function authorizationUrl(string $state, string $panel = 'client'): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('gaiacp.google_login.client_id'),
            'redirect_uri' => self::redirectUri($panel),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);
    }

    /**
     * @return array{sub: string, email: string, email_verified: bool, given_name: ?string, family_name: ?string, name: ?string}
     *
     * @throws GoogleLoginException when Google refuses the code or can't be reached
     */
    public function profile(string $code, string $panel = 'client'): array
    {
        $secret = (string) config('gaiacp.google_login.client_secret');

        try {
            $token = $this->http('token', [$secret, $code])->asForm()->post(self::TOKEN_URL, [
                'code' => $code,
                'client_id' => config('gaiacp.google_login.client_id'),
                'client_secret' => $secret,
                'redirect_uri' => self::redirectUri($panel),
                'grant_type' => 'authorization_code',
            ]);

            if (! $token->successful() || blank($token->json('access_token'))) {
                throw new GoogleLoginException(__('Google did not accept the sign-in (:error). Please try again.', ['error' => $token->json('error') ?: $token->status()]));
            }

            $info = $this->http('userinfo', [$token->json('access_token')])->withToken($token->json('access_token'))->get(self::USERINFO_URL);
        } catch (ConnectionException) {
            throw new GoogleLoginException(__('Google could not be reached. Please try again.'));
        }

        if (! $info->successful() || blank($info->json('sub')) || blank($info->json('email'))) {
            throw new GoogleLoginException(__('Google did not send your email address. Please try again.'));
        }

        return [
            'sub' => (string) $info->json('sub'),
            'email' => (string) $info->json('email'),
            'email_verified' => filter_var($info->json('email_verified'), FILTER_VALIDATE_BOOLEAN),
            'given_name' => $info->json('given_name'),
            'family_name' => $info->json('family_name'),
            'name' => $info->json('name'),
        ];
    }

    /** @param list<string|null> $secrets */
    private function http(string $action, array $secrets): PendingRequest
    {
        return Http::timeout(15)->acceptJson()
            ->withMiddleware(ModuleLogger::middleware('google_login', $action, $secrets, ['access_token', 'id_token', 'refresh_token']));
    }
}
