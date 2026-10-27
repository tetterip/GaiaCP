@php
    use App\Support\Money;
    $today = $summary->until->startOfDay();
@endphp
<x-admin-mail :message="$message ?? null">
# {{ __('Daily summary :date', ['date' => $summary->until->format(\App\Providers\AppServiceProvider::dateFormat())]) }}

{{ __('Since :time', ['time' => $summary->since->format(\App\Providers\AppServiceProvider::dateFormat().' H:i')]) }}

@foreach ($summary->updates as $log)
**{{ __('GaiaCP :version is out', ['version' => $log->properties['version'] ?? '']) }}** {{ ($log->properties['git'] ?? false)
    ? __('Installed here: :version. This copy is deployed from Git: deploy to update it.', ['version' => $log->properties['installed'] ?? ''])
    : __('Installed here: :version. Update it under Settings → Updates.', ['version' => $log->properties['installed'] ?? '']) }}

@endforeach
@if ($summary->unmatchedPayments->isNotEmpty())
## {{ __('Online payments to record by hand') }} ({{ $summary->unmatchedPayments->count() }})

@foreach ($summary->unmatchedPayments as $log)
- {{ $log->client?->name }}: {{ __(':amount for payment notice :number was paid online but not recorded (:reason). Record it by hand or refund it.', [
    'amount' => Money::format((int) ($log->properties['amount_cents'] ?? 0)),
    'number' => $log->properties['invoice_number'] ?? '',
    'reason' => __((string) ($log->properties['reason'] ?? '')),
]) }}
@endforeach

@endif
## {{ __('New payment notices') }} ({{ $summary->newNotices->count() }})

@if ($summary->newNotices->isEmpty())
{{ __('None.') }}
@else
<x-mail::table>
| {{ __('Number') }} | {{ __('Client') }} | {{ __('Due date') }} | {{ __('Total') }} |
| :-- | :-- | :-- | --: |
@foreach ($summary->newNotices as $invoice)
| [{{ $invoice->number }}]({{ $invoiceUrl($invoice) }}) | {{ $invoice->client?->name }} | {{ $invoice->due_date->format(\App\Providers\AppServiceProvider::dateFormat()) }} | {{ Money::format($invoice->total_cents, $invoice->currency) }} |
@endforeach
</x-mail::table>
@endif

## {{ __('Overdue payment notices') }} ({{ $summary->overdueNotices->count() }})

@if ($summary->overdueNotices->isEmpty())
{{ __('None.') }}
@else
<x-mail::table>
| {{ __('Number') }} | {{ __('Client') }} | {{ __('Due date') }} | {{ __('Days overdue') }} | {{ __('Total') }} |
| :-- | :-- | :-- | --: | --: |
@foreach ($summary->overdueNotices as $invoice)
| [{{ $invoice->number }}]({{ $invoiceUrl($invoice) }}) | {{ $invoice->client?->name }} | {{ $invoice->due_date->format(\App\Providers\AppServiceProvider::dateFormat()) }} | {{ (int) $invoice->due_date->diffInDays($today) }} | {{ Money::format($invoice->total_cents, $invoice->currency) }} |
@endforeach
</x-mail::table>

**{{ __('Total overdue: :amount', ['amount' => Money::format($summary->overdueNotices->sum(fn ($invoice) => \App\Support\Currencies::toBase($invoice->total_cents, $invoice->currency)))]) }}**
@endif

## {{ __('Domain sync') }}

@if ($summary->domainFailures->isEmpty() && $summary->domainChanges->isEmpty() && $summary->domainsInSync === 0)
{{ __('No domain was synced.') }}
@else
@foreach ($summary->domainFailures as $log)
- **[{{ $log->service?->domain }}]({{ $serviceUrl($log->service) }})**: {{ __('sync failed: :error', ['error' => \Illuminate\Support\Str::after($log->description, 'failed: ')]) }}
@endforeach
@foreach ($summary->domainChanges as $log)
- [{{ $log->service?->domain }}]({{ $serviceUrl($log->service) }}): {{ $describe($log) }}
@endforeach

{{ __('Domains in sync, nothing changed: :count', ['count' => $summary->domainsInSync]) }}
@endif

{{ __('You get this email because Receive system emails is on for you under Settings → Admin users. It is switched off for everyone under Settings → Automation.') }}
</x-admin-mail>
