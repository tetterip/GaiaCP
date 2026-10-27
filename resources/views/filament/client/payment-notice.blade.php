{{-- A payment notice as the client sees and prints it; styled by filament.themes.client. --}}
@php
    /** @var \App\Models\Invoice $notice */
    $notice = $getRecord()->loadMissing('items', 'client');
    $client = $notice->billTo(); // as it was when the notice was issued
    $clientName = trim($client->first_name.' '.$client->last_name);
    $company = config('gaiacp.company');
    $logo = \App\Support\Branding::url('logo');
    $darkLogo = \App\Support\Branding::url('logo-dark');
    $money = fn (int $cents) => \App\Support\Money::format($cents, $notice->currency);
    $date = fn ($value) => $value?->format(\App\Providers\AppServiceProvider::dateFormat());
    $hasTax = $notice->tax_cents > 0 || $notice->items->contains(fn ($item) => $item->tax_cents > 0);
    $instructions = $notice->status === \App\Enums\InvoiceStatus::Unpaid ? \App\Payments\PaymentMethods::instructions() : '';
@endphp


<div class="gcp-card gcp-notice">
    <div class="gcp-notice-head">
        <div>
            @if ($logo)
                <img class="gcp-notice-logo" src="{{ $logo }}" alt="{{ $company['name'] ?: 'GaiaCP' }}">
                @if ($darkLogo)
                    <img class="gcp-notice-logo gcp-notice-logo-dark" src="{{ $darkLogo }}" alt="{{ $company['name'] ?: 'GaiaCP' }}">
                @endif
            @endif
            <h2>{{ $company['name'] ?: 'GaiaCP' }}</h2>
            @if (filled($company['address'] ?? null))
                <div>{!! nl2br(e($company['address'])) !!}</div>
            @endif
            @if (filled($company['vat_number'] ?? null))
                <div>{{ __('VAT number: :number', ['number' => $company['vat_number']]) }}@if (filled($company['tax_office'] ?? null)), {{ $company['tax_office'] }}@endif</div>
            @endif
            @foreach (array_filter([$company['email'] ?? null, $company['phone'] ?? null, $company['website'] ?? null]) as $contact)
                <div>{{ $contact }}</div>
            @endforeach
        </div>

        <dl class="gcp-notice-meta">
            <dt>{{ __('Payment notice') }}</dt>
            <dd><strong>{{ $notice->number }}</strong></dd>
            <dt>{{ __('Date') }}</dt>
            <dd>{{ $date($notice->date) }}</dd>
            <dt>{{ __('Due') }}</dt>
            <dd @class(['gcp-notice-overdue' => $notice->isOverdue()])>{{ $date($notice->due_date) }}</dd>
            <dt>{{ __('Status') }}</dt>
            <dd>
                {{ $notice->status->getLabel() }}@if ($notice->paid_on), {{ $date($notice->paid_on) }}@endif
            </dd>
        </dl>
    </div>

    <h3>{{ __('For') }}</h3>
    <div>
        <div><strong>{{ $client->company ?: $clientName }}</strong></div>
        @if ($client->company)
            <div>{{ $clientName }}</div>
        @endif
        @foreach (array_filter([$client->address1, $client->address2, trim($client->postcode.' '.$client->city), $client->state]) as $line)
            <div>{{ $line }}</div>
        @endforeach
        @if (filled($client->vat_number))
            <div>{{ __('VAT number: :number', ['number' => $client->vat_number]) }}@if (filled($client->tax_office)), {{ $client->tax_office }}@endif</div>
        @endif
        @foreach (\App\Support\ClientFields::noticeLines($client->custom) as $line)
            <div>{{ $line }}</div>
        @endforeach
    </div>

    <table class="gcp-notice-lines">
        <thead>
            <tr>
                <th>{{ __('Description') }}</th>
                <th class="gcp-end">{{ __('Amount') }}</th>
                @if ($hasTax)
                    <th class="gcp-end">{{ __('Tax') }}</th>
                    <th class="gcp-end">{{ __('Total') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($notice->items as $item)
                <tr>
                    <td>
                        {{ $item->description }}
                        @if ($period = \App\Invoices\GenerateInvoices::period($item))
                            <span class="gcp-notice-period">{{ $period }}</span>
                        @endif
                    </td>
                    <td class="gcp-end">{{ $money($item->amount_cents) }}</td>
                    @if ($hasTax)
                        <td class="gcp-end">{{ $money($item->tax_cents) }}</td>
                        <td class="gcp-end">{{ $money($item->total_cents) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="gcp-notice-totals">
        @if ($hasTax)
            <span>{{ $notice->tax_inclusive ? __('Before tax') : __('Subtotal') }}</span>
            <span class="gcp-end">{{ $money($notice->subtotal_cents) }}</span>
            <span>{{ $notice->taxLabel() ? ($notice->tax_inclusive ? __(':tax (included)', ['tax' => $notice->taxLabel()]) : $notice->taxLabel()) : __('Tax') }}</span>
            <span class="gcp-end">{{ $money($notice->tax_cents) }}</span>
        @endif
        <span class="gcp-total">{{ __('Total') }}</span>
        <span class="gcp-total gcp-end">{{ $money($notice->total_cents) }}</span>
    </div>

    @if (filled($notice->tax_note))
        <p class="gcp-notice-tax-note">{{ $notice->tax_note }}</p>
    @endif

    @if (filled($instructions))
        <div class="gcp-notice-pay">
            <h3>{{ __('How to pay') }}</h3>
            {!! \Illuminate\Support\Str::markdown($instructions, ['html_input' => 'escape', 'allow_unsafe_links' => false, 'renderer' => ['soft_break' => "<br>\n"]]) !!}
        </div>
    @endif

    <p class="gcp-notice-note">{{ __('This is a payment notice, not a tax invoice.') }}</p>
</div>
