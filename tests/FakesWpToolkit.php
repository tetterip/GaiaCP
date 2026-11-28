<?php

namespace Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * An in-memory WP Toolkit REST API, as Plesk (/api/modules/wp-toolkit/v1/) and WHM (/cpsess…/cgi/wpt/index.php/v1/)
 * serve it, plus the WHM session URL that gives its cookie. Use it with fakeWhm() or fakePlesk(), called first.
 */
trait FakesWpToolkit
{
    /** @var list<array<string, mixed>> installations as GET /v1/installations lists them */
    protected array $wpInstallations = [];

    /** @var list<array<string, mixed>> bodies of POST /v1/installations */
    protected array $wpInstalls = [];

    /** @var list<array{0: string, 1: string, 2: array<string, string>}> method, path after v1/, headers */
    protected array $wpCalls = [];

    /** No WP Toolkit on the server: every call is a 404. */
    protected bool $wpMissing = false;

    protected function fakeWpToolkit(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            // WHM: opening a create_user_session URL sets the session cookie
            if (preg_match('#/cpsess\d+/login/#', $url)) {
                return Http::response('', 302, ['Location' => '/cpsess456/', 'Set-Cookie' => 'whostmgrsession=sess%3aabc; HttpOnly; path=/; secure']);
            }

            if (! preg_match('#(?:/api/modules/wp-toolkit|/cgi/wpt/index\.php)/v1/(.+)$#', $url, $m)) {
                return null;
            }

            $path = $m[1];
            $this->wpCalls[] = [$request->method(), $path, array_map(fn (array $v) => implode(', ', $v), $request->headers())];

            if ($this->wpMissing) {
                return Http::response(['message' => 'Not found'], 404);
            }

            if ($request->method() === 'GET' && $path === 'installations') {
                return Http::response($this->wpInstallations);
            }

            if ($request->method() === 'POST' && $path === 'installations') {
                $this->wpInstalls[] = $request->data();

                return Http::response(['taskId' => 7, 'taskCode' => 'install', 'url' => '/v1/background-tasks/install/7'], 201);
            }

            if ($request->method() === 'POST' && preg_match('#^installations/(\w+)/login$#', $path, $l)) {
                $site = collect($this->wpInstallations)->firstWhere('id', (int) $l[1]);

                return $site
                    ? Http::response('', 307, ['Location' => rtrim($site['url'], '/').'/wp-admin/?wpt_login=one-time-'.$l[1]])
                    : Http::response(['message' => 'Installation not found'], 404);
            }

            return Http::response(['message' => "Unknown {$request->method()} {$path}"], 400);
        });
    }

    /** @return array<string, mixed> */
    protected function wpInstallation(int $id, string $domain, string $path = '', string $title = 'My site', string $version = '6.8.1'): array
    {
        return [
            'id' => $id, 'title' => $title, 'displayTitle' => $title, 'version' => $version, 'path' => '/'.$path,
            'url' => 'https://'.$domain.($path !== '' ? '/'.$path : ''),
            'domain' => ['name' => $domain, 'url' => 'https://'.$domain],
        ];
    }
}
