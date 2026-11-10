@php
    use App\Notifications\AdminAlerts;
    use App\Providers\AppServiceProvider;
    use App\Support\Money;
    use Illuminate\Support\Str;

    /** @var \App\Reports\DailySummary $summary */
    $dateFormat = AppServiceProvider::dateFormat();
@endphp
{{ __('Daily summary :date', ['date' => $summary->until->format($dateFormat)]) }}

{{ __('From :since to :until', ['since' => $summary->since->format($dateFormat.' H:i'), 'until' => $summary->until->format($dateFormat.' H:i')]) }}

@foreach ($summary->updates as $log)
{{ __('GaiaCP :version is out', ['version' => $log->properties['version'] ?? '']) }} {{ ($log->properties['git'] ?? false)
    ? __('Installed here: :version. This copy is deployed from Git: deploy to update it.', ['version' => $log->properties['installed'] ?? ''])
    : __('Installed here: :version. Update it under Settings → Updates.', ['version' => $log->properties['installed'] ?? '']) }}

@endforeach
{{ __('Overdue amount') }}: {{ Money::format($summary->overdueTotal()) }} ({{ trans_choice(':count payment notice|:count payment notices', $summary->overdueNotices->count()) }})
{{ __('Received yesterday') }}: {{ Money::format($summary->collectedYesterday()) }} ({{ trans_choice(':count payment|:count payments', $summary->paymentsYesterday) }})
{{ __('Due in the next :days days', ['days' => $summary::UPCOMING_DAYS]) }}: {{ Money::format($summary->upcomingTotal()) }} ({{ trans_choice(':count payment notice|:count payment notices', $summary->upcomingNotices->count()) }})
{{ __('Received in the last :days days', ['days' => $summary::CHART_DAYS]) }}: {{ Money::format($summary->collectedInChart()) }}
@if ($summary->unmatchedPayments->isNotEmpty())

{{ __('Online payments to record by hand') }} ({{ $summary->unmatchedPayments->count() }})
@foreach ($summary->unmatchedPayments as $log)
- {{ $log->client?->name }}: {{ __(':amount for payment notice :number was paid online but not recorded (:reason). Record it by hand or refund it.', [
    'amount' => Money::format((int) ($log->properties['amount_cents'] ?? 0)),
    'number' => $log->properties['invoice_number'] ?? '',
    'reason' => __((string) ($log->properties['reason'] ?? '')),
]) }}
@endforeach
@endif
@if ($summary->moduleQueue->isNotEmpty())

{{ __('Module queue') }} ({{ $summary->moduleQueue->count() }}): {{ $queueUrl }}
@foreach ($summary->moduleQueue as $item)
- {{ $item->service?->domain ?: $item->service?->label }} ({{ $item->service?->client?->name }}): {{ $item->actionLabel() }}, {{ __(':count tries', ['count' => $item->attempts]) }}. {{ Str::limit($item->error, 160) }}
@endforeach
@endif

{{ __('Overdue payment notices') }} ({{ $summary->overdueNotices->count() }})
@forelse ($summary->overdueNotices as $invoice)
- {{ $invoice->number }}, {{ $invoice->client?->name }}, {{ __('due :date', ['date' => $invoice->due_date->format($dateFormat)]) }}, {{ trans_choice(':count day|:count days', $summary->daysOverdue($invoice)) }}: {{ Money::format((int) $invoice->owed_cents, $invoice->currency) }}
@empty
{{ __('None.') }}
@endforelse

{{ __('New payment notices') }} ({{ $summary->newNotices->count() }})
@forelse ($summary->newNotices as $invoice)
- {{ $invoice->number }}, {{ $invoice->client?->name }}, {{ __('due :date', ['date' => $invoice->due_date->format($dateFormat)]) }}: {{ Money::format($invoice->total_cents, $invoice->currency) }}
@empty
{{ __('None.') }}
@endforelse
@if ($summary->pluginUpdates->isNotEmpty())

{{ __('Plugin updates') }} ({{ $summary->pluginUpdates->count() }}): {{ $catalogueUrl }}
@foreach ($summary->pluginUpdates as $log)
- {{ __(':plugin :version is out', ['plugin' => $log->properties['name'] ?? '', 'version' => $log->properties['version'] ?? '']) }} {{ $log->event === 'plugin.update_waiting'
    ? AdminAlerts::pluginWaiting($log->properties ?? [])
    : __('Installed here: :version. Update it from the plugin catalogue.', ['version' => $log->properties['installed'] ?? '']) }}
@endforeach
@endif

{{ __('Domain sync') }}
@if ($summary->domainFailures->isEmpty() && $summary->domainChanges->isEmpty() && $summary->domainsInSync === 0)
{{ __('No domain was synced.') }}
@else
@foreach ($summary->domainFailures as $log)
- {{ $log->service?->domain }}: {{ __('sync failed: :error', ['error' => Str::after($log->description, 'failed: ')]) }}
@endforeach
@foreach ($summary->domainChanges as $log)
- {{ $log->service?->domain }}: {{ $describe($log) }}
@endforeach
{{ __('Domains in sync, nothing changed: :count', ['count' => $summary->domainsInSync]) }}
@endif

{{ __('You get this email because Receive system emails is on for you under Settings → Admin users. It is switched off for everyone under Settings → Automation.') }}
