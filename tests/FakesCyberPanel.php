<?php

namespace Tests;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A tiny in-memory CyberPanel: /api/<endpoint> (adminUser / adminPass in the JSON body) and /cloudAPI/ (controller,
 * the token from /api/verifyConn in the Authorization header), answering as CyberPanel's api/ and cloudAPI/ views do.
 * $cpCalls records [endpoint or controller, body].
 */
trait FakesCyberPanel
{
    /** @var array<string, array{admin: string, package: string, state: string, email?: string}> domain => website */
    protected array $cpWebsites = [];

    /** @var array<string, string> username => password (the website owners) */
    protected array $cpUsers = [];

    /** @var array<string, array<string, mixed>> domain => fetchWebsiteData fields */
    protected array $cpUsage = [];

    /** @var list<string> */
    protected array $cpPackages = ['admin_Default', 'admin_Pro'];

    protected string $cpPassword = 'AdminSecret/1';

    protected string $cpToken = 'Basic cp_TOKEN0123456789';

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    protected array $cpCalls = [];

    /** @var array<string, string> endpoint or controller => error message */
    protected array $cpFailures = [];

    /** Answered to every request instead (e.g. the login page on a wrong port). */
    protected ?PromiseInterface $cpRawResponse = null;

    protected function fakeCyberPanel(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (! str_starts_with($path, '/api/') && $path !== '/cloudAPI/') {
                return null;
            }

            $body = (array) $request->data();
            $action = $path === '/cloudAPI/' ? (string) ($body['controller'] ?? '') : substr($path, strlen('/api/'));
            $this->cpCalls[] = [$action, $body];

            if ($this->cpRawResponse !== null) {
                return $this->cpRawResponse;
            }

            if (isset($this->cpFailures[$action])) {
                return Http::response(['status' => 0, 'error_message' => $this->cpFailures[$action]]);
            }

            if ($path === '/cloudAPI/') {
                if (($body['serverUserName'] ?? null) !== 'admin') {
                    return Http::response(['status' => 0, 'error_message' => 'Only administrator can access API.']);
                }
                if (($request->header('Authorization')[0] ?? null) !== $this->cpToken) {
                    return Http::response(['status' => 0, 'error_message' => 'Invalid login information.']);
                }

                return $this->cpCloud($action, $body);
            }

            $user = $action === 'cyberPanelVersion' ? ($body['username'] ?? null) : ($body['adminUser'] ?? null);
            $password = $action === 'cyberPanelVersion' ? ($body['password'] ?? null) : ($body['adminPass'] ?? null);
            if ($password !== $this->cpPassword) {
                return Http::response(['status' => 0, 'error_message' => 'Could not authorize access to API.'], $action === 'verifyConn' ? 401 : 200);
            }

            return $this->cpApi($action, $body, (string) $user);
        });
    }

    /** @param  array<string, mixed>  $body */
    private function cpApi(string $endpoint, array $body, string $user): PromiseInterface
    {
        switch ($endpoint) {
            case 'verifyConn':
                return Http::response(['verifyConn' => 1, 'apiToken' => $this->cpToken]);
            case 'cyberPanelVersion':
                return Http::response(['getVersion' => 1, 'error_message' => 'none', 'currentVersion' => '2.4', 'build' => 3]);
            case 'listPackage':
                return Http::response($this->cpPackages);
            case 'createWebsite':
                $domain = (string) $body['domainName'];
                if (isset($this->cpWebsites[$domain])) {
                    return Http::response(['existsStatus' => 0, 'createWebSiteStatus' => 0, 'error_message' => 'This website already exists.']);
                }
                if (! in_array($body['packageName'], $this->cpPackages, true)) {
                    return Http::response(['createWebSiteStatus' => 0, 'error_message' => 'Package matching query does not exist.']);
                }
                $owner = strtolower((string) $body['websiteOwner']);
                $this->cpUsers[$owner] ??= (string) $body['ownerPassword'];
                $this->cpWebsites[$domain] = ['admin' => $owner, 'package' => (string) $body['packageName'], 'state' => 'Active', 'email' => (string) $body['ownerEmail']];

                return Http::response(['status' => 1, 'createWebSiteStatus' => 1, 'error_message' => 'None', 'tempStatusPath' => '/home/cyberpanel/1234']);
            case 'submitWebsiteStatus':
                if (! isset($this->cpWebsites[$body['websiteName']])) {
                    return Http::response(['websiteStatus' => 0, 'error_message' => 'Websites matching query does not exist.']);
                }
                $this->cpWebsites[$body['websiteName']]['state'] = $body['state'] === 'Suspend' ? 'Suspended' : 'Active';

                return Http::response(['websiteStatus' => 1, 'error_message' => 'None']);
            case 'deleteWebsite':
                if (! isset($this->cpWebsites[$body['domainName']])) {
                    return Http::response(['websiteDeleteStatus' => 0, 'error_message' => 'Websites matching query does not exist.']);
                }
                unset($this->cpWebsites[$body['domainName']]);

                return Http::response(['status' => 1, 'websiteDeleteStatus' => 1, 'state' => 'completed', 'error_message' => 'None']);
            case 'getUserInfo':
                return isset($this->cpUsers[$body['username']])
                    ? Http::response(['status' => 1, 'email' => 'x@example.com', 'error_message' => 'None'])
                    : Http::response(['status' => 0, 'error_message' => 'User does not exists.']);
            case 'submitUserDeletion':
                unset($this->cpUsers[$body['accountUsername']]);

                return Http::response(['status' => 1, 'deleteStatus' => 1, 'error_message' => 'None']);
            case 'changePackageAPI':
                if (! in_array($body['packageName'], $this->cpPackages, true)) {
                    return Http::response(['changePackage' => 0, 'error_message' => 'Package matching query does not exist.']);
                }
                $this->cpWebsites[$body['websiteName']]['package'] = (string) $body['packageName'];

                return Http::response(['changePackage' => 1, 'error_message' => 'None']);
            case 'changeUserPassAPI':
                $this->cpUsers[$body['websiteOwner']] = (string) $body['ownerPassword'];

                return Http::response(['changeStatus' => 1, 'error_message' => 'None']);
        }

        return Http::response('<html>Not Found</html>', 404, ['Content-Type' => 'text/html']);
    }

    /** @param  array<string, mixed>  $body */
    private function cpCloud(string $controller, array $body): PromiseInterface
    {
        switch ($controller) {
            case 'verifyLogin':
                return Http::response(['status' => 1, 'error_message' => null]);
            case 'fetchWebsites':
                $all = [];
                foreach ($this->cpWebsites as $domain => $site) {
                    $all[] = ['domain' => $domain, 'adminEmail' => $site['email'] ?? '', 'ipAddress' => '203.0.113.20', 'admin' => $site['admin'],
                        'package' => $site['package'], 'state' => $site['state'], 'diskUsed' => '0MB'];
                }
                $pages = max(1, (int) ceil(count($all) / 10));

                return Http::response(['status' => 1, 'listWebSiteStatus' => 1, 'error_message' => 'None',
                    'data' => json_encode(array_slice($all, ((int) $body['page'] - 1) * 10, 10)),
                    'pagination' => array_fill(0, $pages, '<li><a href="#"></a></li>')]);
            case 'fetchWebsiteData':
                if (! isset($this->cpWebsites[$body['domainName']])) {
                    return Http::response(['status' => 0, 'error_message' => 'Websites matching query does not exist.']);
                }

                return Http::response(['status' => 1] + ($this->cpUsage[$body['domainName']] ?? ['diskInMB' => 0, 'diskInMBTotal' => 1000, 'bwInMB' => 0, 'bwInMBTotal' => 0]));
        }

        return Http::response(['status' => 0, 'error_message' => "Unknown controller {$controller}"]);
    }

    /** @return list<string> the endpoints and controllers called, in order */
    protected function cpActions(): array
    {
        return array_column($this->cpCalls, 0);
    }
}
