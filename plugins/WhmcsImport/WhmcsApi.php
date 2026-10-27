<?php

namespace Plugins\WhmcsImport;

use App\Support\ModuleLogger;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WHMCS's API (includes/api.php), used only for DecryptPassword: WHMCS's own decryption for the passwords
 * WhmcsCrypt can't read. It changes nothing in WHMCS. Needs an API credential (Setup → Staff → Manage API
 * Credentials) whose role allows DecryptPassword, and this server's IP in WHMCS's API IP allow list.
 */
class WhmcsApi
{
    public function __construct(private string $url, private string $identifier, private string $secret) {}

    /** Null unless all three are filled in. */
    public static function make(?string $url, ?string $identifier, ?string $secret): ?self
    {
        return filled($url) && filled($identifier) && filled($secret) ? new self($url, $identifier, $secret) : null;
    }

    /** The plain text WHMCS returns; throws when the API can't be reached or refuses. */
    public function decryptPassword(string $encrypted): string
    {
        $response = Http::asForm()
            ->timeout(20)
            ->withMiddleware(ModuleLogger::middleware('whmcs', 'DecryptPassword', [$this->secret, $encrypted], ['password']))
            ->post(rtrim($this->url, '/').'/includes/api.php', [
                'action' => 'DecryptPassword',
                'identifier' => $this->identifier,
                'secret' => $this->secret,
                'password2' => $encrypted,
                'responsetype' => 'json',
            ]);

        $data = $response->json();

        if (! is_array($data) || ($data['result'] ?? null) !== 'success' || ! isset($data['password'])) {
            throw new RuntimeException('WHMCS API: '.(is_array($data) ? ($data['message'] ?? 'unexpected reply') : "HTTP {$response->status()}"));
        }

        return (string) $data['password'];
    }
}
