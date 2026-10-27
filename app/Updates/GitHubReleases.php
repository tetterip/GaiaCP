<?php

namespace App\Updates;

use App\Support\ModuleLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The releases of GaiaCP on GitHub (`gaiacp.updates.repository`): the latest one and its files. A private
 * repository needs `gaiacp.updates.token` (GAIACP_UPDATE_TOKEN in .env).
 */
class GitHubReleases
{
    /** API calls go to the module log; downloads (a zip) don't. */
    private function http(?string $action): PendingRequest
    {
        $token = config('gaiacp.updates.token');

        return Http::baseUrl((string) config('gaiacp.updates.api'))
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->when(filled($token), fn (PendingRequest $http) => $http->withToken($token))
            ->timeout(30)
            ->when($action !== null, fn (PendingRequest $http) => $http->withMiddleware(ModuleLogger::middleware('updates', $action, array_filter([$token]))));
    }

    /** The newest published release (not a draft or pre-release), or null when there is none yet. */
    public function latest(): ?Release
    {
        $repository = (string) config('gaiacp.updates.repository');
        $response = $this->http('latest release')->accept('application/vnd.github+json')->get("repos/{$repository}/releases/latest");

        if ($response->status() === 404) {
            if (blank(config('gaiacp.updates.token')) && $this->http('repository')->get("repos/{$repository}")->status() === 404) {
                throw new RuntimeException("Can't see {$repository}: a private repository needs GAIACP_UPDATE_TOKEN in .env.");
            }

            return null; // no release published yet
        }

        try {
            $response->throw();
        } catch (RequestException $e) {
            throw new RuntimeException("GitHub answered {$response->status()} for {$repository}'s releases.", previous: $e);
        }

        $version = ltrim((string) $response->json('tag_name'), 'v');
        $assets = collect($response->json('assets', []));
        $zip = $assets->firstWhere('name', "gaiacp-{$version}.zip");

        if ($zip === null) {
            throw new RuntimeException("Release {$version} has no gaiacp-{$version}.zip.");
        }

        return new Release(
            version: $version,
            notes: (string) $response->json('body'),
            publishedAt: $response->json('published_at') ? CarbonImmutable::parse($response->json('published_at')) : null,
            zipUrl: $zip['url'],
            checksumUrl: $assets->firstWhere('name', "gaiacp-{$version}.zip.sha256")['url'] ?? null,
            pageUrl: (string) $response->json('html_url'),
            signatureUrl: $assets->firstWhere('name', "gaiacp-{$version}.zip.sha256.sig")['url'] ?? null,
        );
    }

    /** Downloads a release file (its API url) to a path. */
    public function download(string $url, string $to): void
    {
        try {
            $this->http(null)->accept('application/octet-stream')->timeout(300)->sink($to)->get($url)->throw();
        } catch (RequestException $e) {
            throw new RuntimeException("Download failed ({$e->response->status()}).", previous: $e);
        }
    }

    public function text(string $url, string $action = 'checksum'): string
    {
        try {
            return $this->http($action)->accept('application/octet-stream')->get($url)->throw()->body();
        } catch (RequestException $e) {
            throw new RuntimeException("Download failed ({$e->response->status()}).", previous: $e);
        }
    }
}
