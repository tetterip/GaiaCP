<?php

namespace App\Provisioning;

use App\Support\DomainName;

/**
 * The parts of WP Toolkit's REST API (/v1/…) that are the same on cPanel and Plesk: which installations belong to an
 * account, and the body that installs one. Each plugin sends the requests its own way.
 */
final class WpToolkit
{
    /**
     * The sites of GET /v1/installations on the account's main domain or one of its subdomains, by their
     * domain.name (WP Toolkit lists every site the login can see, with no filter for one account).
     *
     * @param  mixed  $installations  the decoded response
     * @return list<WordPressSite>
     */
    public static function sitesOf(mixed $installations, string $domain): array
    {
        $domain = mb_strtolower(DomainName::toAscii($domain));
        $sites = [];

        foreach (is_array($installations) ? $installations : [] as $installation) {
            if (! is_array($installation) || ! isset($installation['id'])) {
                continue;
            }

            $name = mb_strtolower(DomainName::toAscii((string) ($installation['domain']['name'] ?? '')));

            if ($name !== $domain && ! str_ends_with($name, '.'.$domain)) {
                continue;
            }

            $url = (string) ($installation['url'] ?? '') ?: 'https://'.$name.'/'.ltrim((string) ($installation['path'] ?? ''), '/');

            $sites[] = new WordPressSite(
                id: (string) $installation['id'],
                url: $url,
                title: filled($installation['displayTitle'] ?? null) ? (string) $installation['displayTitle'] : (filled($installation['title'] ?? null) ? (string) $installation['title'] : null),
                version: filled($installation['version'] ?? null) ? (string) $installation['version'] : null,
            );
        }

        return $sites;
    }

    /**
     * The body of POST /v1/installations.
     *
     * @return array<string, mixed>
     */
    public static function installBody(string $domain, WordPressInstall $install): array
    {
        return [
            'domain' => DomainName::toAscii($domain),
            'installationPath' => trim($install->path, '/'),
            'language' => $install->language,
            'version' => 'latest',
            'title' => $install->title,
            'protocol' => 'https',
            'overwrite' => false,
            'admin' => ['login' => $install->username, 'password' => $install->password, 'email' => $install->email],
        ];
    }

    /** The login link in the answer of POST /v1/installations/{id}/login: its Location, or a url / link member. */
    public static function loginLink(?string $location, mixed $json): ?string
    {
        $link = filled($location) ? $location : (is_array($json) ? ($json['url'] ?? $json['link'] ?? $json['loginUrl'] ?? null) : (is_string($json) ? $json : null));

        return is_string($link) && preg_match('#^https?://#i', $link) ? $link : null;
    }
}
