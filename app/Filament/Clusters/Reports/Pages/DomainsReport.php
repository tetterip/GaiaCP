<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Clusters\Reports\ServiceReportPage;
use App\Models\Registrar;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\BooleanConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\NumberConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/** Domains with their registrar details, like WHMCS's Domains report. */
class DomainsReport extends ServiceReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?int $navigationSort = 11;

    protected static ?string $title = 'Domains report';

    protected static ?string $navigationLabel = 'Domains';

    protected static ?string $slug = 'domains';

    public static function reportDescription(): string
    {
        return __('Domains with the fields you pick (registrar, expiry, nameservers…), filtered, as a table or CSV.');
    }

    protected function csvName(): string
    {
        return 'domains-'.today()->toDateString().'.csv';
    }

    protected function query(): Builder
    {
        return Service::query()
            ->where('type', ServiceType::Domain)
            ->with(['client', 'domainDetails.registrar']);
    }

    /** The registrar account a domain uses: its own, else the one that sells its TLD. */
    private static function registrar(Service $service): ?string
    {
        if ($service->domainDetails?->registration_type === DomainRegistrationType::External) {
            return null;
        }

        return $service->domainDetails?->registrar?->name ?? self::tldRegistrar((string) $service->domain)?->name;
    }

    /** @var array<string, Registrar|null> */
    private static array $tldRegistrars = [];

    /** Registrar::forDomain(), once per domain in a request (it reads every TLD). */
    private static function tldRegistrar(string $domain): ?Registrar
    {
        return array_key_exists($domain, self::$tldRegistrars) ? self::$tldRegistrars[$domain] : self::$tldRegistrars[$domain] = Registrar::forDomain($domain);
    }

    private static function years(int $years): string
    {
        return $years === 1 ? __('1 year') : __(':count years', ['count' => $years]);
    }

    protected function fields(): array
    {
        return [
            'id' => ['label' => __('ID'), 'value' => fn (Service $s) => $s->id, 'column' => fn (TextColumn $c) => $c->sortable()],
            'client.last_name' => ['label' => __('Client'), 'default' => true, 'value' => fn (Service $s) => $s->client->name,
                'column' => fn (TextColumn $c) => $c->formatStateUsing(fn (Service $record) => $record->client->name)->searchable(['first_name', 'last_name', 'company'])->sortable()],
            'client.email' => ['label' => __('Client email'), 'value' => fn (Service $s) => $s->client->email],
            'order_id' => ['label' => __('Order'), 'value' => fn (Service $s) => $s->order_id, 'column' => fn (TextColumn $c) => $c->sortable()],
            'domain' => ['label' => __('Domain'), 'default' => true, 'value' => fn (Service $s) => $s->domain, 'column' => fn (TextColumn $c) => $c->searchable()->sortable()],
            'domainDetails.registration_type' => ['label' => __('Registration type'), 'value' => fn (Service $s) => static::label($s->domainDetails?->registration_type)],
            'first_payment_cents' => ['label' => __('First payment amount'), 'value' => fn (Service $s) => static::money($s->firstPaymentCents()),
                'column' => static::moneyColumn(fn (Service $record) => $record->firstPaymentCents())],
            'price_cents' => ['label' => __('Recurring amount'), 'value' => fn (Service $s) => static::money($s->price_cents),
                'column' => fn (TextColumn $c) => static::moneyColumn(fn (Service $record) => $record->price_cents)($c)->sortable()],
            'billing_cycle' => ['label' => __('Registration period'), 'default' => true, 'value' => fn (Service $s) => static::years($s->registrationYears()),
                'column' => fn (TextColumn $c) => $c->state(fn (Service $record) => static::years($record->registrationYears()))],
            'start_date' => ['label' => __('Registration date'), 'value' => fn (Service $s) => static::date($s->domainDetails?->registered_at ?? $s->start_date),
                'column' => fn (TextColumn $c) => $c->state(fn (Service $record) => $record->domainDetails?->registered_at ?? $record->start_date)->date()->sortable()],
            'domainDetails.expires_at' => ['label' => __('Expiry date'), 'default' => true, 'value' => fn (Service $s) => static::date($s->domainDetails?->expires_at),
                'column' => fn (TextColumn $c) => $c->date()->color(fn (Service $record) => $record->domainDetails?->expires_at?->lessThan($record->next_due_date) ? 'danger' : null)],
            'next_due_date' => ['label' => __('Next due date'), 'default' => true, 'value' => fn (Service $s) => static::date($s->next_due_date),
                'column' => fn (TextColumn $c) => $c->date()->sortable()->color(fn (Service $record) => $record->isOverdue() ? 'danger' : null)],
            'registrar' => ['label' => __('Registrar'), 'default' => true, 'value' => fn (Service $s) => static::registrar($s)],
            'status' => ['label' => __('Status'), 'default' => true, 'value' => fn (Service $s) => static::label($s->status), 'column' => fn (TextColumn $c) => $c->badge()],
            'auto_terminate' => ['label' => __('Auto renew'), 'value' => fn (Service $s) => static::yesNo($s->autoRenew),
                'column' => fn (TextColumn $c) => $c->state(fn (Service $record) => static::yesNo($record->autoRenew))],
            'domainDetails.nameservers' => ['label' => __('Nameservers'), 'value' => fn (Service $s) => implode(', ', $s->domainDetails?->nameservers ?? []),
                'column' => fn (TextColumn $c) => $c->state(fn (Service $record) => implode(', ', $record->domainDetails?->nameservers ?? []))->wrap()],
            'domainDetails.last_synced_at' => ['label' => __('Last synced'), 'value' => fn (Service $s) => $s->domainDetails?->last_synced_at?->format(AppServiceProvider::dateFormat().' H:i'),
                'column' => fn (TextColumn $c) => $c->dateTime()],
            'notes' => ['label' => __('Notes'), 'value' => fn (Service $s) => $s->notes, 'column' => fn (TextColumn $c) => $c->limit(60)->wrap()],
        ];
    }

    protected function filters(): array
    {
        return [
            SelectFilter::make('status')->options(ServiceStatus::class)->multiple(),
            SelectFilter::make('registrar')
                ->options(fn () => Registrar::query()->orderBy('name')->pluck('name', 'id')->all())
                ->multiple()
                ->query(fn (Builder $query, array $data) => filled($data['values'] ?? null) ? $query->whereIn('services.id', $this->idsAtRegistrars($data['values'])) : $query),
            SelectFilter::make('registration_type')->label(__('Registration type'))->options(DomainRegistrationType::class)->multiple()
                ->query(fn (Builder $query, array $data) => filled($data['values'] ?? null) ? $query->whereHas('domainDetails', fn (Builder $q) => $q->whereIn('registration_type', $data['values'])) : $query),
            SelectFilter::make('billing_cycle')->label(__('Registration period'))->options(BillingCycle::class)->multiple(),
            QueryBuilder::make('rules')
                ->label(__('More filters'))
                ->constraints([
                    TextConstraint::make('domain')->label(__('Domain')),
                    TextConstraint::make('notes')->label(__('Notes'))->nullable(),
                    NumberConstraint::make('price_cents')->label(__('Recurring amount (cents)')),
                    BooleanConstraint::make('auto_terminate')->label(__('Auto-terminate (auto renew off)')),
                    DateConstraint::make('next_due_date')->label(__('Next due date')),
                    DateConstraint::make('expires_at')->label(__('Expiry date'))->relationship('domainDetails', 'expires_at'),
                    TextConstraint::make('nameservers')->label(__('Nameservers'))->relationship('domainDetails', 'nameservers'),
                ])
                ->columnSpanFull(),
        ];
    }

    protected function dateRanges(): array
    {
        return [
            'start_date' => __('Registration date'),
            'domainDetails.expires_at' => __('Expiry date'),
            'next_due_date' => __('Next due date'),
        ];
    }

    /**
     * Domains at these registrar accounts: chosen for the domain, or (none chosen) the account that sells its TLD.
     * Worked out in PHP like Registrar::forDomain(); tracked domains are at no registrar of ours.
     *
     * @param  list<int|string>  $registrarIds
     * @return list<int>
     */
    private function idsAtRegistrars(array $registrarIds): array
    {
        $ids = array_map('intval', $registrarIds);

        return Service::query()->where('type', ServiceType::Domain)->with('domainDetails.registrar')->get()
            ->filter(function (Service $service) use ($ids) {
                $domain = $service->domainDetails;
                if ($domain?->registration_type === DomainRegistrationType::External) {
                    return false;
                }
                $id = $domain?->registrar_id ?? self::tldRegistrar((string) $service->domain)?->id;

                return in_array($id, $ids, true);
            })
            ->modelKeys();
    }
}
