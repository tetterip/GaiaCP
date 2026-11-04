<?php

namespace App\Support;

use App\Models\Tld;
use Illuminate\Support\Collection;

/**
 * Finds the TLD row of a domain (the longest match: "x.com.gr" → ".com.gr") from the TLD table read once per request
 * (a scoped binding; saving or deleting a TLD forgets it). A domain is looked up by its suffixes, longest first, in a
 * map keyed by each TLD as stored and in its ASCII form, so an IDN TLD matches entered either way (".ελ" or
 * ".xn--qxam"). Used by Tld::match(), which the reports and daily jobs call once per domain.
 */
final class TldLookup
{
    /** @var Collection<int, Tld>|null */
    private ?Collection $tlds = null;

    /** @var array<string, array{0: array<string, list<Tld>>, 1: array<string, list<Tld>>}> 'all' / 'active' => [stored, ascii] */
    private array $maps = [];

    public static function find(?string $domain, bool $activeOnly = true): ?Tld
    {
        return app(self::class)->match($domain, $activeOnly);
    }

    public static function flush(): void
    {
        $self = app(self::class);
        $self->tlds = null;
        $self->maps = [];
    }

    public function match(?string $domain, bool $activeOnly = true): ?Tld
    {
        $domain = DomainName::normalize($domain);

        if ($domain === '') {
            return null;
        }

        [$stored, $ascii] = $this->maps($activeOnly);
        $found = [];

        foreach (self::suffixes($domain) as $suffix) {
            array_push($found, ...$stored[$suffix] ?? []);
        }

        foreach (self::suffixes(DomainName::toAscii($domain)) as $suffix) {
            array_push($found, ...$ascii[$suffix] ?? []);
        }

        return collect($found)->unique(fn (Tld $t) => $t->getKey())->sortByDesc(fn (Tld $t) => strlen($t->tld))->first();
    }

    /** @return array{0: array<string, list<Tld>>, 1: array<string, list<Tld>>} */
    private function maps(bool $activeOnly): array
    {
        $key = $activeOnly ? 'active' : 'all';

        if (! isset($this->maps[$key])) {
            $this->tlds ??= Tld::query()->get();
            $rows = $activeOnly ? $this->tlds->where('active', true) : $this->tlds;

            $this->maps[$key] = [self::keyed($rows, fn (Tld $t) => $t->tld), self::keyed($rows, fn (Tld $t) => '.'.DomainName::toAscii(ltrim($t->tld, '.')))];
        }

        return $this->maps[$key];
    }

    /**
     * @param  Collection<int, Tld>  $tlds
     * @return array<string, list<Tld>>
     */
    private static function keyed(Collection $tlds, callable $key): array
    {
        $map = [];

        foreach ($tlds as $tld) {
            $map[$key($tld)][] = $tld;
        }

        return $map;
    }

    /** @return list<string> ".b.com.gr", ".com.gr", ".gr" for "a.b.com.gr": every suffix from a dot, shorter than the name */
    private static function suffixes(string $domain): array
    {
        $suffixes = [];

        for ($at = strpos($domain, '.', 1); $at !== false; $at = strpos($domain, '.', $at + 1)) {
            $suffixes[] = substr($domain, $at);
        }

        return $suffixes;
    }
}
