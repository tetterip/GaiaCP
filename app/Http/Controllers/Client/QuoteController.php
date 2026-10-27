<?php

namespace App\Http\Controllers\Client;

use App\Actions\Quotes;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteAnswerRequest;
use App\Models\Quote;
use App\Support\TermsOfService;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * A quote's page (/quote/{token}, from the Quote email, no login): the quote in the language it was written for, with
 * Accept (and the terms of service when Settings → Client area asks for them) and Decline while it is open. A draft is
 * not shown: only a sent quote's link works.
 */
class QuoteController extends Controller
{
    public function show(string $token): Response
    {
        $quote = $this->find($token);

        return response()->view('client-area.quote', [
            'quote' => $quote,
            'terms' => TermsOfService::required(),
            'clientAreaUrl' => Filament::getPanel('client')->getLoginUrl(),
        ]);
    }

    public function accept(QuoteAnswerRequest $request, string $token, Quotes $quotes): RedirectResponse
    {
        $quote = $this->find($token);

        try {
            $quotes->accept($quote, $request->boolean('accept_terms'), $request->ip());
        } catch (RuntimeException $e) {
            return back()->withErrors(['quote' => $e->getMessage()]);
        }

        return redirect()->route('quote.show', ['token' => $token]);
    }

    public function decline(QuoteAnswerRequest $request, string $token, Quotes $quotes): RedirectResponse
    {
        $quote = $this->find($token);

        try {
            $quotes->decline($quote, $request->input('reason'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['quote' => $e->getMessage()]);
        }

        return redirect()->route('quote.show', ['token' => $token]);
    }

    private function find(string $token): Quote
    {
        $quote = Quote::query()->where('token', $token)->where('status', '!=', QuoteStatus::Draft)->firstOrFail();
        app()->setLocale($quote->preferredLocale());

        return $quote;
    }
}
