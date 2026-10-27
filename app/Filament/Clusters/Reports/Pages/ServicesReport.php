<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Clusters\Reports\ServiceReportPage;
use App\Models\Service;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\BooleanConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\NumberConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/** Hosting, maintenance and other services (domains have their own report), like WHMCS's Services report. */
class ServicesReport extends ServiceReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?int $navigationSort = 12;

    protected static ?string $title = 'Services report';

    protected static ?string $navigationLabel = 'Services';

    protected static ?string $slug = 'services';

    public static function reportDescription(): string
    {
        return __('Hosting and other services with the fields you pick, filtered, as a table or CSV.');
    }

    protected function csvName(): string
    {
        return 'services-'.today()->toDateString().'.csv';
    }

    protected function query(): Builder
    {
        return Service::query()
            ->where('type', '!=', ServiceType::Domain)
            ->with(['client', 'product', 'server'])
            ->withSum('payments', 'amount_cents');
    }

    protected function fields(): array
    {
        return [
            'id' => ['label' => __('ID'), 'value' => fn (Service $s) => $s->id, 'column' => fn (TextColumn $c) => $c->sortable()],
            'client.last_name' => ['label' => __('Client'), 'default' => true, 'value' => fn (Service $s) => $s->client->name,
                'column' => fn (TextColumn $c) => $c->formatStateUsing(fn (Service $record) => $record->client->name)->searchable(['first_name', 'last_name', 'company'])->sortable()],
            'client.company' => ['label' => __('Company'), 'value' => fn (Service $s) => $s->client->company],
            'client.email' => ['label' => __('Client email'), 'value' => fn (Service $s) => $s->client->email],
            'order_id' => ['label' => __('Order'), 'value' => fn (Service $s) => $s->order_id, 'column' => fn (TextColumn $c) => $c->sortable()],
            'product.name' => ['label' => __('Product'), 'value' => fn (Service $s) => $s->product?->name],
            'type' => ['label' => __('Type'), 'default' => true, 'value' => fn (Service $s) => static::label($s->type), 'column' => fn (TextColumn $c) => $c->badge()],
            'server.name' => ['label' => __('Server'), 'default' => true, 'value' => fn (Service $s) => $s->server?->name],
            'label' => ['label' => __('Service'), 'default' => true, 'value' => fn (Service $s) => $s->label, 'column' => fn (TextColumn $c) => $c->searchable()->sortable()],
            'domain' => ['label' => __('Domain'), 'default' => true, 'value' => fn (Service $s) => $s->domain, 'column' => fn (TextColumn $c) => $c->searchable()->sortable()],
            'username' => ['label' => __('Username'), 'value' => fn (Service $s) => $s->username, 'column' => fn (TextColumn $c) => $c->searchable()],
            'package' => ['label' => __('Package'), 'value' => fn (Service $s) => $s->package],
            'start_date' => ['label' => __('Start date'), 'default' => true, 'value' => fn (Service $s) => static::date($s->start_date), 'column' => fn (TextColumn $c) => $c->date()->sortable()],
            'first_payment_cents' => ['label' => __('First payment amount'), 'value' => fn (Service $s) => static::money($s->firstPaymentCents()),
                'column' => static::moneyColumn(fn (Service $record) => $record->firstPaymentCents())],
            'price_cents' => ['label' => __('Recurring amount'), 'default' => true, 'value' => fn (Service $s) => static::money($s->price_cents),
                'column' => fn (TextColumn $c) => static::moneyColumn(fn (Service $record) => $record->price_cents)($c)->sortable()],
            'billing_cycle' => ['label' => __('Billing cycle'), 'default' => true, 'value' => fn (Service $s) => static::label($s->billing_cycle)],
            'next_due_date' => ['label' => __('Next due date'), 'default' => true, 'value' => fn (Service $s) => static::date($s->next_due_date),
                'column' => fn (TextColumn $c) => $c->date()->sortable()->color(fn (Service $record) => $record->isOverdue() ? 'danger' : null)],
            'status' => ['label' => __('Status'), 'default' => true, 'value' => fn (Service $s) => static::label($s->status), 'column' => fn (TextColumn $c) => $c->badge()],
            'suspension_reason' => ['label' => __('Suspension reason'), 'value' => fn (Service $s) => $s->suspension_reason],
            'auto_terminate' => ['label' => __('Auto-terminate'), 'value' => fn (Service $s) => static::yesNo($s->auto_terminate),
                'column' => fn (TextColumn $c) => $c->state(fn (Service $record) => static::yesNo($record->auto_terminate))],
            'payments_sum_amount_cents' => ['label' => __('Paid to date'), 'value' => fn (Service $s) => static::money((int) $s->payments_sum_amount_cents),
                'column' => fn (TextColumn $c) => static::moneyColumn(fn (Service $record) => (int) $record->payments_sum_amount_cents)($c)->sortable()],
            'notes' => ['label' => __('Notes'), 'value' => fn (Service $s) => $s->notes, 'column' => fn (TextColumn $c) => $c->limit(60)->wrap()],
        ];
    }

    protected function filters(): array
    {
        return [
            SelectFilter::make('status')->options(ServiceStatus::class)->multiple(),
            SelectFilter::make('type')->multiple()
                ->options(collect(ServiceType::cases())->reject(fn (ServiceType $t) => $t === ServiceType::Domain)->mapWithKeys(fn (ServiceType $t) => [$t->value => $t->getLabel()])->all()),
            SelectFilter::make('product')->relationship('product', 'name')->multiple()->preload(),
            SelectFilter::make('server')->relationship('server', 'name')->multiple()->preload(),
            SelectFilter::make('billing_cycle')->options(BillingCycle::class)->multiple(),
            QueryBuilder::make('rules')
                ->label(__('More filters'))
                ->constraints([
                    TextConstraint::make('label')->label(__('Service')),
                    TextConstraint::make('domain')->label(__('Domain'))->nullable(),
                    TextConstraint::make('username')->label(__('Username'))->nullable(),
                    TextConstraint::make('package')->label(__('Package'))->nullable(),
                    TextConstraint::make('notes')->label(__('Notes'))->nullable(),
                    NumberConstraint::make('price_cents')->label(__('Recurring amount (cents)')),
                    SelectConstraint::make('suspension_reason')->label(__('Suspension reason'))
                        ->options(fn () => Service::query()->whereNotNull('suspension_reason')->distinct()->orderBy('suspension_reason')->pluck('suspension_reason', 'suspension_reason')->all()),
                    BooleanConstraint::make('auto_terminate')->label(__('Auto-terminate')),
                    DateConstraint::make('start_date')->label(__('Start date')),
                    DateConstraint::make('next_due_date')->label(__('Next due date')),
                ])
                ->columnSpanFull(),
        ];
    }

    protected function dateRanges(): array
    {
        return [
            'start_date' => __('Start date'),
            'next_due_date' => __('Next due date'),
        ];
    }
}
