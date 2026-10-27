<?php

namespace Plugins\WhmcsImport;

/** What an import did (or, on a dry run, would do): counts per kind of record and the problems found. */
class WhmcsImportReport
{
    public const ENTITIES = [
        'server' => 'Servers',
        'product' => 'Products',
        'client' => 'Clients',
        'hosting' => 'Hosting and other services',
        'domain' => 'Domains',
        'invoice' => 'Unpaid invoices → payment notices',
    ];

    /** created: new here; matched: already here (same email, hostname, name or domain), linked; existing: imported before; skipped: not imported */
    public const OUTCOMES = ['created', 'matched', 'existing', 'skipped'];

    /** @var array<string, array<string, int>> */
    public array $counts = [];

    /** Problem groups, in the order the page shows them. */
    public const GROUPS = [
        'passwords' => 'Passwords',
        'registrars' => 'Domains at a registrar GaiaCP has no module for',
        'invoices' => 'WHMCS invoices',
        'other' => 'Other',
    ];

    /** @var list<string> */
    public array $problems = [];

    /** @var array<string, list<string>> the same problems by group (GROUPS) */
    public array $grouped = [];

    /** @param  list<string>  $parts  WhmcsImport::PARTS keys imported (empty = all) */
    public function __construct(public bool $dryRun, public array $parts = [])
    {
        foreach (array_keys(self::ENTITIES) as $entity) {
            $this->counts[$entity] = array_fill_keys(self::OUTCOMES, 0);
        }
    }

    public function count(string $entity, string $outcome): void
    {
        $this->counts[$entity][$outcome]++;
    }

    public function problem(string $message, string $group = 'other'): void
    {
        $this->problems[] = $message;
        $this->grouped[$group][] = $message;
    }

    public function created(): int
    {
        return array_sum(array_column($this->counts, 'created'));
    }

    /** @return list<array{entity: string, label: string, created: int, matched: int, existing: int, skipped: int}> the imported kinds */
    public function rows(): array
    {
        return array_map(
            fn (string $entity) => ['entity' => $entity, 'label' => self::ENTITIES[$entity], ...$this->counts[$entity]],
            $this->entities(),
        );
    }

    /** @return list<string> the ENTITIES rows this import covers (billable items count as services) */
    public function entities(): array
    {
        return array_values(array_filter(array_keys(self::ENTITIES), fn (string $entity) => $this->parts === []
            || in_array($entity, $this->parts, true)
            || ($entity === 'hosting' && in_array('billable', $this->parts, true))));
    }

    /** "12 clients, 3 domains created" style summary for the activity log and notifications. */
    public function summary(): string
    {
        $parts = [];

        foreach ($this->counts as $entity => $counts) {
            if ($counts['created'] > 0) {
                $parts[] = $counts['created'].' '.mb_strtolower(self::ENTITIES[$entity]);
            }
        }

        return ($parts ? implode(', ', $parts) : 'nothing new').', '.count($this->problems).' problem(s)';
    }

    /** @return array{dry_run: bool, counts: array<string, array<string, int>> (the imported kinds only), problems: array<string, list<string>>} for Livewire, problems by group in GROUPS order */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'counts' => array_intersect_key($this->counts, array_flip($this->entities())),
            'problems' => array_filter(array_map(fn (string $group) => $this->grouped[$group] ?? [], array_combine(array_keys(self::GROUPS), array_keys(self::GROUPS)))),
        ];
    }
}
