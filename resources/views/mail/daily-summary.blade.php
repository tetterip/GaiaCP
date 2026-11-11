@php
    use App\Notifications\AdminAlerts;
    use App\Providers\AppServiceProvider;
    use App\Support\Money;
    use Illuminate\Support\Str;

    /**
     * The admins' daily summary. Hand-written HTML with inline styles, not Markdown: the tiles, the bar chart and the
     * age bar are table cells with background colours (no images, SVG or scripts), so Gmail, Outlook and Apple Mail
     * all draw them. The <style> block only adds the phone layout and dark colours where a mail client reads it.
     */
    /** @var \App\Reports\DailySummary $summary */
    $dateFormat = AppServiceProvider::dateFormat();
    $font = "-apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $ages = [
        ['bar' => '#fbbf24', 'chip' => '#fef3c7', 'ink' => '#92400e'],
        ['bar' => '#f97316', 'chip' => '#ffedd5', 'ink' => '#9a3412'],
        ['bar' => '#dc2626', 'chip' => '#fee2e2', 'ink' => '#991b1b'],
    ];
    $overdueTotal = $summary->overdueTotal();
    $byAge = $summary->overdueByAge();
    $chartMax = max([1, ...array_values($summary->collections)]);
    $chartDays = array_keys($summary->collections);
    $shown = 20; // rows per list; the rest are a link away
    $attention = array_filter([
        $summary->overdueNotices->count() ? trans_choice(':count overdue payment notice|:count overdue payment notices', $summary->overdueNotices->count()) : null,
        $summary->moduleQueue->count() ? trans_choice(':count failed action in the module queue|:count failed actions in the module queue', $summary->moduleQueue->count()) : null,
        $summary->unmatchedPayments->count() ? trans_choice(':count online payment to record|:count online payments to record', $summary->unmatchedPayments->count()) : null,
        $summary->domainFailures->count() ? trans_choice(':count domain sync failed|:count domain syncs failed', $summary->domainFailures->count()) : null,
    ]);
    $title = __('Daily summary :date', ['date' => $summary->until->format($dateFormat)]);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $title }}</title>
<style>
    @media (max-width: 520px) {
        .gcp-pad { padding-left: 16px !important; padding-right: 16px !important; }
        .gcp-tile { display: block !important; width: 100% !important; box-sizing: border-box; margin-bottom: 8px; }
        .gcp-gap { display: none !important; }
        .gcp-big { font-size: 24px !important; }
    }
    @media (prefers-color-scheme: dark) {
        .gcp-bg { background: #0f1513 !important; }
        .gcp-card { background: #18211e !important; border-color: #26332f !important; }
        .gcp-ink { color: #e6ece9 !important; }
        .gcp-muted { color: #93a39d !important; }
        .gcp-tilebg { background: #1e2a26 !important; }
        .gcp-rule { border-color: #26332f !important; }
        .gcp-row { background: #1b2522 !important; }
        .gcp-empty { background: #26332f !important; }
    }
</style>
</head>
<body class="gcp-bg" style="margin: 0; padding: 0; background: #f1f4f3; font-family: {!! $font !!}; color: #1f2a27;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="gcp-bg" style="background: #f1f4f3;">
<tr><td align="center" style="padding: 28px 12px;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px;">
    <tr><td align="center" style="padding-bottom: 20px;">
        <a href="{{ config('app.url') }}"><img src="{{ \App\Mail\MailLayout::gaiacpLogo($message ?? null) }}" alt="GaiaCP" height="40" style="height: 40px; max-width: 100%; border: 0;"></a>
    </td></tr>
</table>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="gcp-card" style="max-width: 600px; background: #ffffff; border: 1px solid #e3e9e6; border-radius: 14px; overflow: hidden; font-family: {!! $font !!};">

    {{-- The day at a glance --}}
    <tr><td class="gcp-pad" bgcolor="#0f766e" style="background: #0f766e; background-image: linear-gradient(135deg, #0f766e, #14532d); padding: 26px 32px;">
        <div style="font-size: 13px; letter-spacing: .06em; text-transform: uppercase; color: #a7f3d0;">{{ __('Daily summary') }}</div>
        <div style="font-size: 26px; font-weight: 700; color: #ffffff; margin-top: 4px;">{{ Str::ucfirst($summary->until->locale(app()->getLocale())->isoFormat('dddd, LL')) }}</div>
        <div style="font-size: 14px; color: #d1fae5; margin-top: 6px;">{{ __('From :since to :until', ['since' => $summary->since->format($dateFormat.' H:i'), 'until' => $summary->until->format($dateFormat.' H:i')]) }}</div>
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top: 16px;"><tr>
            @if ($attention)
                <td style="background: #fef3c7; color: #92400e; font-size: 14px; font-weight: 600; border-radius: 999px; padding: 7px 14px;">&#9679; {{ implode(' · ', $attention) }}</td>
            @else
                <td style="background: #dcfce7; color: #166534; font-size: 14px; font-weight: 600; border-radius: 999px; padding: 7px 14px;">&#10003; {{ __('All is well, nothing needs you today') }}</td>
            @endif
        </tr></table>
    </td></tr>

    {{-- New releases of GaiaCP --}}
    @foreach ($summary->updates as $log)
        <tr><td class="gcp-pad" style="padding: 20px 32px 0;">
            <div class="gcp-tilebg" style="background: #eff6ff; border-left: 4px solid #2563eb; border-radius: 8px; padding: 12px 16px; font-size: 14px; line-height: 1.5;">
                <strong class="gcp-ink" style="color: #1e3a8a;">{{ __('GaiaCP :version is out', ['version' => $log->properties['version'] ?? '']) }}</strong>
                <span class="gcp-ink" style="color: #1f2a27;">{{ ($log->properties['git'] ?? false)
                    ? __('Installed here: :version. This copy is deployed from Git: deploy to update it.', ['version' => $log->properties['installed'] ?? ''])
                    : __('Installed here: :version. Update it under Settings → Updates.', ['version' => $log->properties['installed'] ?? '']) }}</span>
            </div>
        </td></tr>
    @endforeach

    {{-- Online payments to record by hand --}}
    @if ($summary->unmatchedPayments->isNotEmpty())
        <tr><td class="gcp-pad" style="padding: 20px 32px 0;">
            <div class="gcp-tilebg" style="background: #fef2f2; border-left: 4px solid #dc2626; border-radius: 8px; padding: 12px 16px;">
                <div style="font-size: 15px; font-weight: 700; color: #991b1b;">{{ __('Online payments to record by hand') }} ({{ $summary->unmatchedPayments->count() }})</div>
                @foreach ($summary->unmatchedPayments as $log)
                    <div class="gcp-ink" style="font-size: 14px; line-height: 1.5; color: #1f2a27; margin-top: 6px;">{{ $log->client?->name }}: {{ __(':amount for payment notice :number was paid online but not recorded (:reason). Record it by hand or refund it.', [
                        'amount' => Money::format((int) ($log->properties['amount_cents'] ?? 0)),
                        'number' => $log->properties['invoice_number'] ?? '',
                        'reason' => __((string) ($log->properties['reason'] ?? '')),
                    ]) }}</div>
                @endforeach
            </div>
        </td></tr>
    @endif

    {{-- Module queue --}}
    @if ($summary->moduleQueue->isNotEmpty())
        <tr><td class="gcp-pad" style="padding: 20px 32px 0;">
            <div class="gcp-tilebg" style="background: #fef2f2; border-left: 4px solid #dc2626; border-radius: 8px; padding: 12px 16px;">
                <a href="{{ $queueUrl }}" style="font-size: 15px; font-weight: 700; color: #991b1b; text-decoration: none;">{{ __('Module queue') }} ({{ $summary->moduleQueue->count() }}) &rarr;</a>
                @foreach ($summary->moduleQueue->take($shown) as $item)
                    <div class="gcp-ink" style="font-size: 14px; line-height: 1.5; color: #1f2a27; margin-top: 6px;">
                        <a href="{{ $serviceUrl($item->service) }}" style="color: #0f766e; font-weight: 600; text-decoration: none;">{{ $item->service?->domain ?: $item->service?->label }}</a>
                        ({{ $item->service?->client?->name }}): {{ $item->actionLabel() }}, {{ __(':count tries', ['count' => $item->attempts]) }}.
                        <span class="gcp-muted" style="color: #5b6b66;">{{ Str::limit($item->error, 160) }}</span>
                    </div>
                @endforeach
            </div>
        </td></tr>
    @endif

    {{-- The numbers --}}
    <tr><td class="gcp-pad" style="padding: 24px 32px 8px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            @foreach ([
                ['label' => __('Overdue amount'), 'amount' => $overdueTotal, 'note' => trans_choice(':count payment notice|:count payment notices', $summary->overdueNotices->count()), 'bg' => '#fef2f2', 'edge' => '#dc2626', 'ink' => '#b91c1c', 'soft' => '#991b1b'],
                ['label' => __('Received yesterday'), 'amount' => $summary->collectedYesterday(), 'note' => trans_choice(':count payment|:count payments', $summary->paymentsYesterday), 'bg' => '#f0fdf4', 'edge' => '#16a34a', 'ink' => '#15803d', 'soft' => '#166534'],
                ['label' => __('Due in the next :days days', ['days' => $summary::UPCOMING_DAYS]), 'amount' => $summary->upcomingTotal(), 'note' => trans_choice(':count payment notice|:count payment notices', $summary->upcomingNotices->count()), 'bg' => '#eff6ff', 'edge' => '#2563eb', 'ink' => '#1d4ed8', 'soft' => '#1e40af'],
            ] as $i => $tile)
                @if ($i > 0)<td class="gcp-gap" width="3%">&nbsp;</td>@endif
                <td class="gcp-tile gcp-tilebg" width="31%" valign="top" bgcolor="{{ $tile['bg'] }}" style="background: {{ $tile['bg'] }}; border-radius: 10px; padding: 14px 16px; border-left: 4px solid {{ $tile['edge'] }};">
                    <div class="gcp-muted" style="font-size: 12px; color: {{ $tile['soft'] }};">{{ $tile['label'] }}</div>
                    <div class="gcp-big gcp-ink" style="font-size: 24px; font-weight: 700; color: {{ $tile['ink'] }}; margin-top: 2px; white-space: nowrap;">{{ Money::format($tile['amount']) }}</div>
                    <div class="gcp-muted" style="font-size: 12px; color: {{ $tile['soft'] }};">{{ $tile['note'] }}</div>
                </td>
            @endforeach
        </tr></table>
    </td></tr>

    {{-- What came in: one bar a day, yesterday darker --}}
    <tr><td class="gcp-pad" style="padding: 18px 32px 4px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            <td class="gcp-ink" style="font-size: 15px; font-weight: 700; color: #1f2a27;">{{ __('Received in the last :days days', ['days' => $summary::CHART_DAYS]) }}</td>
            <td align="right" class="gcp-muted" style="font-size: 13px; color: #5b6b66;">{{ __('Total :amount', ['amount' => Money::format($summary->collectedInChart())]) }}</td>
        </tr></table>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top: 12px;">
            <tr valign="bottom" style="height: 92px;">
                @foreach ($summary->collections as $day => $cents)
                    @php
                        $height = $cents > 0 ? max(6, (int) round($cents / $chartMax * 88)) : 3;
                        $colour = $cents <= 0 ? '#d7e2de' : ($loop->last ? '#0f766e' : '#5eead4');
                    @endphp
                    <td valign="bottom" style="padding: 0 2px; height: 92px; vertical-align: bottom;" title="{{ \Carbon\CarbonImmutable::parse($day)->format($dateFormat) }}: {{ Money::format($cents) }}">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                            <td height="{{ $height }}" bgcolor="{{ $colour }}" class="{{ $cents <= 0 ? 'gcp-empty' : '' }}" style="height: {{ $height }}px; background: {{ $colour }}; border-radius: 4px 4px 0 0; font-size: 0; line-height: 0;">&nbsp;</td>
                        </tr></table>
                    </td>
                @endforeach
            </tr>
        </table>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="gcp-rule" style="border-top: 1px solid #e3e9e6;"><tr>
            <td class="gcp-muted" style="font-size: 11px; color: #8a9a95; padding-top: 4px;">{{ \Carbon\CarbonImmutable::parse($chartDays[0])->format($dateFormat) }}</td>
            <td align="right" style="font-size: 11px; color: #0f766e; font-weight: 600; padding-top: 4px;">{{ __('yesterday :amount', ['amount' => Money::format($summary->collectedYesterday())]) }}</td>
        </tr></table>
    </td></tr>

    {{-- Overdue payment notices: how old, then each one --}}
    @if ($summary->overdueNotices->isNotEmpty())
        <tr><td class="gcp-pad" style="padding: 26px 32px 6px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                <td class="gcp-ink" style="font-size: 15px; font-weight: 700; color: #1f2a27;">{{ __('Overdue payment notices') }} ({{ $summary->overdueNotices->count() }})</td>
                <td align="right" style="font-size: 14px; font-weight: 700; color: #b91c1c; white-space: nowrap;">{{ Money::format($overdueTotal) }}</td>
            </tr></table>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top: 10px; border-radius: 6px; overflow: hidden;"><tr>
                @foreach ($byAge as $i => $group)
                    @if ($group['cents'] > 0)
                        <td width="{{ max(1, (int) round($group['cents'] / max(1, $overdueTotal) * 100)) }}%" height="10" bgcolor="{{ $ages[$i]['bar'] }}" style="height: 10px; background: {{ $ages[$i]['bar'] }}; font-size: 0; line-height: 0;">&nbsp;</td>
                    @endif
                @endforeach
            </tr></table>
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top: 6px;"><tr>
                @foreach ($byAge as $i => $group)
                    <td class="gcp-muted" style="font-size: 12px; color: #5b6b66; padding-right: 14px;"><span style="color: {{ $ages[$i]['bar'] }};">&#9632;</span>
                        {{ $group['to'] === null ? __('over :days days', ['days' => $group['from'] - 1]) : __(':from-:to days', ['from' => $group['from'], 'to' => $group['to']]) }}
                        <span style="white-space: nowrap;">{{ Money::format($group['cents']) }}</span></td>
                @endforeach
            </tr></table>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top: 14px;">
                @foreach ($summary->overdueNotices->take($shown) as $invoice)
                    @php
                        $days = $summary->daysOverdue($invoice);
                        $age = $ages[$summary->ageGroup($days)];
                    @endphp
                    @unless ($loop->first)<tr><td colspan="2" style="height: 6px; font-size: 0; line-height: 0;">&nbsp;</td></tr>@endunless
                    <tr class="gcp-row" style="background: #f7faf9;">
                        <td style="padding: 12px; border-radius: 8px 0 0 8px;">
                            <a href="{{ $invoiceUrl($invoice) }}" style="font-size: 14px; font-weight: 600; color: #0f766e; text-decoration: none;">{{ $invoice->number }}</a>
                            <div class="gcp-ink" style="font-size: 14px; color: #1f2a27;">{{ $invoice->client?->name }}</div>
                            <div class="gcp-muted" style="font-size: 12px; color: #8a9a95;">{{ __('due :date', ['date' => $invoice->due_date->format($dateFormat)]) }}</div>
                        </td>
                        <td align="right" style="padding: 12px; border-radius: 0 8px 8px 0; white-space: nowrap; vertical-align: top;">
                            <div class="gcp-ink" style="font-size: 15px; font-weight: 700; color: #1f2a27;">{{ Money::format((int) $invoice->owed_cents, $invoice->currency) }}</div>
                            <span style="display: inline-block; margin-top: 4px; background: {{ $age['chip'] }}; color: {{ $age['ink'] }}; font-size: 12px; font-weight: 600; border-radius: 999px; padding: 2px 9px;">{{ trans_choice(':count day|:count days', $days) }}</span>
                        </td>
                    </tr>
                @endforeach
            </table>
            @if ($summary->overdueNotices->count() > $shown)
                <div class="gcp-muted" style="font-size: 13px; color: #5b6b66; margin-top: 8px;">{{ __('and :count more', ['count' => $summary->overdueNotices->count() - $shown]) }}</div>
            @endif

            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top: 16px;"><tr>
                <td bgcolor="#0f766e" style="background: #0f766e; border-radius: 8px;"><a href="{{ $overdueUrl }}" style="display: inline-block; padding: 10px 18px; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none;">{{ __('Open the overdue notices') }} &rarr;</a></td>
            </tr></table>
        </td></tr>
    @endif

    {{-- New payment notices --}}
    @if ($summary->newNotices->isNotEmpty())
        <tr><td class="gcp-pad" style="padding: 26px 32px 6px;">
            <div class="gcp-ink" style="font-size: 15px; font-weight: 700; color: #1f2a27;">{{ __('New payment notices') }} ({{ $summary->newNotices->count() }})</div>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top: 12px;">
                @foreach ($summary->newNotices->take($shown) as $invoice)
                    <tr>
                        <td class="gcp-rule" style="padding: 9px 0; border-top: 1px solid #e3e9e6;">
                            <a href="{{ $invoiceUrl($invoice) }}" style="font-size: 14px; font-weight: 600; color: #0f766e; text-decoration: none;">{{ $invoice->number }}</a>
                            <span class="gcp-ink" style="font-size: 14px; color: #1f2a27;">&nbsp;{{ $invoice->client?->name }}</span>
                            <div class="gcp-muted" style="font-size: 12px; color: #8a9a95;">{{ __('due :date', ['date' => $invoice->due_date->format($dateFormat)]) }}</div>
                        </td>
                        <td align="right" class="gcp-ink gcp-rule" style="padding: 9px 0; border-top: 1px solid #e3e9e6; font-size: 14px; font-weight: 600; color: #1f2a27; white-space: nowrap;">{{ Money::format($invoice->total_cents, $invoice->currency) }}</td>
                    </tr>
                @endforeach
            </table>
            @if ($summary->newNotices->count() > $shown)
                <div class="gcp-muted" style="font-size: 13px; color: #5b6b66; margin-top: 8px;">{{ __('and :count more', ['count' => $summary->newNotices->count() - $shown]) }}</div>
            @endif
        </td></tr>
    @endif

    {{-- Plugin updates --}}
    @if ($summary->pluginUpdates->isNotEmpty())
        <tr><td class="gcp-pad" style="padding: 26px 32px 6px;">
            <a href="{{ $catalogueUrl }}" class="gcp-ink" style="font-size: 15px; font-weight: 700; color: #1f2a27; text-decoration: none;">{{ __('Plugin updates') }} ({{ $summary->pluginUpdates->count() }}) &rarr;</a>
            @foreach ($summary->pluginUpdates as $log)
                <div class="gcp-ink" style="font-size: 14px; line-height: 1.5; color: #1f2a27; margin-top: 6px;">
                    <strong>{{ __(':plugin :version is out', ['plugin' => $log->properties['name'] ?? '', 'version' => $log->properties['version'] ?? '']) }}</strong>
                    {{ $log->event === 'plugin.update_waiting'
                        ? AdminAlerts::pluginWaiting($log->properties ?? [])
                        : __('Installed here: :version. Update it from the plugin catalogue.', ['version' => $log->properties['installed'] ?? '']) }}
                </div>
            @endforeach
        </td></tr>
    @endif

    {{-- The quiet parts, one line each --}}
    @php
        $ok = 'background: #dcfce7; color: #15803d;';
        $bad = 'background: #fee2e2; color: #b91c1c;';
        $none = 'background: #e2e8f0; color: #475569;';
        $domainsSynced = $summary->domainFailures->isNotEmpty() || $summary->domainChanges->isNotEmpty() || $summary->domainsInSync > 0;
        $rows = array_filter([
            $summary->newNotices->isEmpty() ? ['mark' => '0', 'style' => $none, 'label' => __('New payment notices'), 'value' => __('None.'), 'colour' => '#8a9a95'] : null,
            $summary->overdueNotices->isEmpty() ? ['mark' => '&#10003;', 'style' => $ok, 'label' => __('Overdue payment notices'), 'value' => __('None.'), 'colour' => '#15803d'] : null,
            ['mark' => $summary->domainFailures->isNotEmpty() ? '!' : ($domainsSynced ? '&#10003;' : '–'), 'style' => $summary->domainFailures->isNotEmpty() ? $bad : ($domainsSynced ? $ok : $none), 'label' => __('Domain sync'),
                'value' => $domainsSynced ? trans_choice(':count in sync, nothing changed|:count in sync, nothing changed', $summary->domainsInSync) : __('No domain was synced.'),
                'colour' => $domainsSynced ? '#15803d' : '#8a9a95', 'domains' => true],
            $summary->moduleQueue->isEmpty() ? ['mark' => '&#10003;', 'style' => $ok, 'label' => __('Module queue'), 'value' => __('Empty'), 'colour' => '#15803d'] : null,
        ]);
    @endphp
    <tr><td class="gcp-pad" style="padding: 24px 32px 8px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            @foreach ($rows as $row)
                <tr>
                    <td width="30" valign="top" class="gcp-rule" style="padding: 12px 0; border-top: 1px solid #e3e9e6;"><span style="display: inline-block; width: 20px; height: 20px; line-height: 20px; text-align: center; border-radius: 50%; font-size: 12px; font-weight: 700; {{ $row['style'] }}">{!! $row['mark'] !!}</span></td>
                    <td valign="top" class="gcp-ink gcp-rule" style="padding: 12px 0; border-top: 1px solid #e3e9e6; font-size: 14px; color: #1f2a27;">
                        {{ $row['label'] }}
                        @if ($row['domains'] ?? false)
                            @foreach ($summary->domainFailures as $log)
                                <div style="font-size: 13px; line-height: 1.5; color: #b91c1c; margin-top: 4px;"><a href="{{ $serviceUrl($log->service) }}" style="color: #b91c1c; font-weight: 600;">{{ $log->service?->domain }}</a>: {{ __('sync failed: :error', ['error' => Str::after($log->description, 'failed: ')]) }}</div>
                            @endforeach
                            @foreach ($summary->domainChanges as $log)
                                <div class="gcp-muted" style="font-size: 13px; line-height: 1.5; color: #5b6b66; margin-top: 4px;"><a href="{{ $serviceUrl($log->service) }}" style="color: #0f766e; font-weight: 600;">{{ $log->service?->domain }}</a>: {{ $describe($log) }}</div>
                            @endforeach
                        @endif
                    </td>
                    <td align="right" valign="top" class="gcp-rule" style="padding: 12px 0 12px 8px; border-top: 1px solid #e3e9e6; font-size: 13px; color: {{ $row['colour'] }};">{{ $row['value'] }}</td>
                </tr>
            @endforeach
        </table>
    </td></tr>

    <tr><td class="gcp-pad gcp-muted" style="padding: 16px 32px 26px; font-size: 12px; line-height: 1.5; color: #8a9a95;">
        {{ __('You get this email because Receive system emails is on for you under Settings → Admin users. It is switched off for everyone under Settings → Automation.') }}
    </td></tr>
</table>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px;">
    <tr><td align="center" class="gcp-muted" style="padding: 18px; font-size: 12px; color: #8a9a95; font-family: {!! $font !!};">© {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}</td></tr>
</table>

</td></tr>
</table>
</body>
</html>
