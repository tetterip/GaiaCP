@php($payments = $this->payments())

@if ($payments->isNotEmpty())
    <div class="gcp-report-card">
        <table class="gcp-report">
            <thead>
                <tr>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Client') }}</th>
                    <th>{{ __('Service') }}</th>
                    <th>{{ __('Method') }}</th>
                    <th>{{ __('Reference') }}</th>
                    <th class="gcp-end">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($payments as $payment)
                    <tr>
                        <td>{{ $payment->paid_on->format(\App\Providers\AppServiceProvider::dateFormat()) }}</td>
                        <td>{{ $payment->service->client->name }}</td>
                        <td>
                            <a href="{{ \App\Filament\Resources\Services\ServiceResource::getUrl('edit', ['record' => $payment->service_id]) }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $payment->service->label }}</a>
                            @if ($payment->service->domain && $payment->service->domain !== $payment->service->label)
                                <span style="color: var(--gray-500)">· {{ $payment->service->domain }}</span>
                            @endif
                        </td>
                        <td>{{ \App\Payments\PaymentMethods::label($payment->method) }}</td>
                        <td>{{ $payment->reference ?? '—' }}</td>
                        <td class="gcp-end">{{ \App\Support\Money::format($payment->amount_cents, $payment->currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
