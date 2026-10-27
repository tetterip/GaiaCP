{{-- Client\QuoteController: a quote's page from the Quote email, with Accept and Decline while it is open. --}}
@extends('client-area.page')

@section('title', __('Quote :number', ['number' => $quote->number]))

@section('width', '760px')

@section('head')
    <style>
        .meta { display: flex; flex-wrap: wrap; gap: 4px 24px; color: var(--muted); margin-bottom: 16px; }
        .intro, .footer { white-space: pre-line; margin: 16px 0; }
        .footer { color: var(--muted); font-size: 14px; }
        .lines { width: 100%; border-collapse: collapse; margin: 16px 0 8px; font-size: 14px; }
        .lines th { text-align: left; color: var(--muted); font-weight: 600; border-bottom: 1px solid var(--line); padding: 8px 6px; }
        .lines td { border-bottom: 1px solid var(--line); padding: 8px 6px; vertical-align: top; }
        .lines .num { text-align: right; white-space: nowrap; }
        .total { text-align: right; font-weight: 600; margin: 8px 6px 0; }
        .notice { padding: 12px 14px; border-radius: 8px; background: color-mix(in srgb, var(--accent) 12%, transparent); margin: 16px 0; }
        .notice.bad { background: color-mix(in srgb, #c0392b 14%, transparent); }
        .answer { margin-top: 20px; border-top: 1px solid var(--line); padding-top: 16px; }
        .answer label { display: flex; gap: 8px; align-items: flex-start; }
        .answer details { margin-top: 12px; color: var(--muted); }
        .answer textarea { width: 100%; margin-top: 8px; font: inherit; padding: 8px; border: 1px solid var(--line); border-radius: 8px; background: var(--card); color: var(--text); }
        .secondary { background: transparent; color: var(--text); border: 1px solid var(--line); }
        .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .row button { margin-top: 12px; }
        .terms { max-height: 240px; overflow: auto; font-size: 13px; border: 1px solid var(--line); border-radius: 8px; padding: 0 12px; margin-top: 8px; }
        @media (max-width: 560px) { .lines .cycle { display: none; } }
        @media print { .answer, .print { display: none; } body { background: #fff; } main { padding: 0; } .card { border: 0; } }
    </style>
@endsection

@section('card')
    <h1>{{ __('Quote :number', ['number' => $quote->number]) }}</h1>
    <div class="meta">
        <span>{{ $quote->recipientName() }}</span>
        <span>{{ __('Date: :date', ['date' => ($quote->sent_at ?? $quote->created_at)->format(\App\Providers\AppServiceProvider::dateFormat())]) }}</span>
        <span>{{ __('Valid until :date', ['date' => $quote->valid_until->format(\App\Providers\AppServiceProvider::dateFormat())]) }}</span>
    </div>

    @if ($quote->intro)
        <div class="intro">{{ $quote->intro }}</div>
    @endif

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('Item') }}</th>
                <th class="cycle">{{ __('Billing cycle') }}</th>
                <th class="num">{{ __('First payment') }}</th>
                <th class="num">{{ __('Renews at') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($quote->lines() as $line)
                <tr>
                    <td>{{ $line['description'] }}</td>
                    <td class="cycle">{{ $line['cycle'] ?? __('Once') }}</td>
                    <td class="num">{{ $quote->money($line['first_cents']) }}</td>
                    <td class="num">{{ $line['renews_cents'] === null ? '–' : $quote->money($line['renews_cents']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="total">{{ __('First payment: :total', ['total' => $quote->totalText()]) }}</p>

    @if ($quote->footer)
        <div class="footer">{{ $quote->footer }}</div>
    @endif

    @if ($errors->has('quote'))
        <div class="notice bad">{{ $errors->first('quote') }}</div>
    @endif

    @if ($quote->status === \App\Enums\QuoteStatus::Accepted)
        <div class="notice">
            {{ __('Thank you, you accepted this quote on :date. Your order is placed and its payment notice was emailed to you, with how to pay.', ['date' => $quote->accepted_at?->format(\App\Providers\AppServiceProvider::dateFormat())]) }}
            <a href="{{ $clientAreaUrl }}">{{ __('Go to the client area') }}</a>
        </div>
    @elseif ($quote->status === \App\Enums\QuoteStatus::Declined)
        <div class="notice">{{ __('You declined this quote. If you change your mind, just reply to our email.') }}</div>
    @elseif (! $quote->acceptable())
        <div class="notice">{{ __('This quote has expired. Reply to our email and we will send you a new one.') }}</div>
    @else
        <div class="answer">
            <form method="post" action="{{ route('quote.accept', ['token' => $quote->token]) }}">
                @csrf
                @if ($terms)
                    <label>
                        <input type="checkbox" name="accept_terms" value="1" required>
                        <span>{{ __('I accept the Terms of Service') }}
                            @if (\App\Support\TermsOfService::text() === null)
                                (<a href="{{ \App\Support\TermsOfService::url() }}" target="_blank" rel="noopener">{{ __('Read the Terms of Service') }}</a>)
                            @endif
                        </span>
                    </label>
                    @if (\App\Support\TermsOfService::text() !== null)
                        <div class="terms">{{ \App\Support\TermsOfService::html() }}</div>
                    @endif
                @endif
                <div class="row">
                    <button type="submit">{{ __('Accept the quote') }}</button>
                    <button type="button" class="secondary print" onclick="window.print()">{{ __('Print') }}</button>
                </div>
                <p style="margin-top: 8px; font-size: 13px">{{ __('Accepting places the order: you get a payment notice by email with how to pay, and access to our client area.') }}</p>
            </form>
            <details>
                <summary>{{ __('Decline the quote') }}</summary>
                <form method="post" action="{{ route('quote.decline', ['token' => $quote->token]) }}">
                    @csrf
                    <textarea name="reason" rows="2" maxlength="255" placeholder="{{ __('Why? (optional, it helps us)') }}"></textarea>
                    <button type="submit" class="secondary">{{ __('Decline') }}</button>
                </form>
            </details>
        </div>
    @endif
@endsection
