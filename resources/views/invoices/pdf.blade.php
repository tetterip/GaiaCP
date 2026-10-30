{{-- An invoice or credit note as a PDF (App\Invoicing\InvoicePdf, dompdf): tables only, DejaVu Sans for Greek and other
     scripts. A credit note prints its amounts negative. --}}
@php
    /** @var \App\Models\TaxInvoice $invoice */
    $seller = $invoice->seller;
    $to = (object) array_merge(array_fill_keys([...\App\Models\Invoice::BILL_TO, 'custom'], null), $invoice->bill_to);
    $clientName = trim($to->first_name.' '.$to->last_name);
    $creditNote = $invoice->isCreditNote();
    $money = fn (int $cents) => \App\Support\Money::format($creditNote && $cents !== 0 ? -$cents : $cents, $invoice->currency);
    $heading = $creditNote ? __('Credit note') : __('Invoice');
    $date = fn ($value) => $value?->format(\App\Providers\AppServiceProvider::dateFormat());
    $hasTax = $invoice->tax_cents > 0 || $invoice->items->contains(fn ($item) => $item->tax_cents > 0);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $creditNote ? __('Credit note :number', ['number' => $invoice->number]) : __('Invoice :number', ['number' => $invoice->number]) }}</title>
    <style>
        @page { margin: 18mm 16mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #1f2937; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; padding: 0; }
        h1 { font-size: 18pt; margin: 0 0 4pt; }
        h2 { font-size: 12pt; margin: 0 0 4pt; }
        h3 { font-size: 8.5pt; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; margin: 0 0 3pt; }
        .logo { max-height: 48pt; max-width: 180pt; margin-bottom: 6pt; }
        .meta td { padding: 1pt 0; }
        .meta td.label { color: #6b7280; padding-right: 8pt; }
        .end { text-align: right; }
        .parties { margin: 18pt 0; }
        .parties td { width: 50%; }
        .lines th { text-align: left; font-size: 8.5pt; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 4pt 4pt 4pt 0; }
        .lines td { border-bottom: 1px solid #e5e7eb; padding: 5pt 4pt 5pt 0; }
        .lines .end { padding-right: 0; }
        .period { color: #6b7280; font-size: 8.5pt; }
        .totals { width: 55%; margin: 10pt 0 0 45%; }
        .totals td { padding: 2pt 0; }
        .totals .total td { font-weight: bold; font-size: 11pt; border-top: 1px solid #1f2937; padding-top: 5pt; }
        .note { margin-top: 14pt; color: #4b5563; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td>
                @if ($logo)
                    <img class="logo" src="{{ $logo }}" alt=""><br>
                @endif
                <h2>{{ $seller['name'] ?: config('app.name') }}</h2>
                @if (filled($seller['address'] ?? null))
                    {!! nl2br(e($seller['address'])) !!}<br>
                @endif
                @if (filled($seller['vat_number'] ?? null))
                    {{ __('VAT number: :number', ['number' => $seller['vat_number']]) }}@if (filled($seller['tax_office'] ?? null)), {{ $seller['tax_office'] }}@endif<br>
                @endif
                @foreach (array_filter([$seller['email'] ?? null, $seller['phone'] ?? null, $seller['website'] ?? null]) as $contact)
                    {{ $contact }}<br>
                @endforeach
            </td>
            <td class="end" style="width: 42%">
                <h1>{{ $heading }}</h1>
                <table class="meta">
                    <tr><td class="label end">{{ __('Number') }}</td><td class="end"><strong>{{ $invoice->number }}</strong></td></tr>
                    <tr><td class="label end">{{ __('Date') }}</td><td class="end">{{ $date($invoice->date) }}</td></tr>
                    @if ($invoice->paid_on)
                        <tr><td class="label end">{{ __('Paid on') }}</td><td class="end">{{ $date($invoice->paid_on) }}</td></tr>
                    @endif
                    @if ($invoice->paymentMethods())
                        <tr><td class="label end">{{ __('Paid by') }}</td><td class="end">{{ $invoice->paymentMethods() }}</td></tr>
                    @endif
                    @if ($invoice->creditedInvoice)
                        <tr><td class="label end">{{ __('Credits invoice') }}</td><td class="end">{{ $invoice->creditedInvoice->number }}</td></tr>
                    @endif
                    @if ($invoice->notice)
                        <tr><td class="label end">{{ __('Payment notice') }}</td><td class="end">{{ $invoice->notice->number }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                <h3>{{ __('For') }}</h3>
                <strong>{{ $to->company ?: $clientName }}</strong><br>
                @if ($to->company)
                    {{ $clientName }}<br>
                @endif
                @foreach (array_filter([$to->address1, $to->address2, trim($to->postcode.' '.$to->city), $to->state, $to->country ? (\Symfony\Component\Intl\Countries::exists($to->country) ? \Symfony\Component\Intl\Countries::getName($to->country, app()->getLocale()) : $to->country) : null]) as $line)
                    {{ $line }}<br>
                @endforeach
                @if (filled($to->vat_number))
                    {{ __('VAT number: :number', ['number' => $to->vat_number]) }}@if (filled($to->tax_office)), {{ $to->tax_office }}@endif<br>
                @endif
                @foreach (\App\Support\ClientFields::noticeLines($to->custom) as $line)
                    {{ $line }}<br>
                @endforeach
            </td>
            <td></td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('Description') }}</th>
                <th class="end">{{ __('Amount') }}</th>
                @if ($hasTax)
                    <th class="end">{{ __('Tax') }}</th>
                    <th class="end">{{ __('Total') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>
                        {{ $item->description }}
                        @if ($item->period_start && $item->period_end)
                            <br><span class="period">{{ $date($item->period_start) }} - {{ $date($item->period_end) }}</span>
                        @endif
                    </td>
                    <td class="end">{{ $money($item->amount_cents) }}</td>
                    @if ($hasTax)
                        <td class="end">{{ $money($item->tax_cents) }}</td>
                        <td class="end">{{ $money($item->total_cents) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        @if ($hasTax)
            <tr>
                <td>{{ $invoice->tax_inclusive ? __('Before tax') : __('Subtotal') }}</td>
                <td class="end">{{ $money($invoice->subtotal_cents) }}</td>
            </tr>
            <tr>
                <td>{{ $invoice->taxLabel() ? ($invoice->tax_inclusive ? __(':tax (included)', ['tax' => $invoice->taxLabel()]) : $invoice->taxLabel()) : __('Tax') }}</td>
                <td class="end">{{ $money($invoice->tax_cents) }}</td>
            </tr>
        @endif
        <tr class="total">
            <td>{{ __('Total') }}</td>
            <td class="end">{{ $money($invoice->total_cents) }}</td>
        </tr>
    </table>

    @if (filled($invoice->reason))
        <p class="note">{{ __('Reason') }}: {{ __($invoice->reason) }}</p>
    @endif

    @if (filled($invoice->tax_note))
        <p class="note">{{ $invoice->tax_note }}</p>
    @endif
</body>
</html>
