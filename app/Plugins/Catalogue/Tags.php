<?php

namespace App\Plugins\Catalogue;

use Illuminate\Support\Str;

/**
 * The tags plugins carry in the catalogue (`tags` in catalogue.json; the list is TAGS in gaiacp-plugins' bin/catalogue),
 * named in the admin's language. A tag added to the catalogue after this release shows as its slug, made readable.
 */
final class Tags
{
    public static function label(string $tag): string
    {
        return match ($tag) {
            'ai' => __('AI'),
            'bank-transfer' => __('Bank transfer'),
            'cards' => __('Card payments'),
            'chat' => __('Chat'),
            'domains' => __('Domains'),
            'email' => __('Email'),
            'example' => __('Example'),
            'greece' => __('Greece'),
            'hosting' => __('Hosting'),
            'import' => __('Import'),
            'invoicing' => __('Invoicing'),
            'security' => __('Security'),
            'sign-in' => __('Sign-in'),
            'ssl' => __('SSL'),
            'tax' => __('Tax'),
            'uptime' => __('Uptime'),
            default => Str::of($tag)->replace('-', ' ')->ucfirst()->toString(),
        };
    }

    /**
     * Every tag the given plugins carry, slug => name, sorted by name.
     *
     * @param  iterable<Listing>  $listings
     * @return array<string, string>
     */
    public static function options(iterable $listings): array
    {
        return collect($listings)->flatMap(fn (Listing $listing) => $listing->tags)->unique()
            ->mapWithKeys(fn (string $tag) => [$tag => self::label($tag)])
            ->sort(fn (string $a, string $b) => strcoll($a, $b))
            ->all();
    }
}
