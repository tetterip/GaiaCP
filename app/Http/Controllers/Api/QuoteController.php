<?php

namespace App\Http\Controllers\Api;

use App\Actions\Quotes;
use App\Enums\QuoteStatus;
use App\Http\Requests\Api\DeclineQuoteRequest;
use App\Http\Requests\Api\StoreQuoteRequest;
use App\Http\Resources\Api\QuoteResource;
use App\Models\ActivityLog;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/** Quotes (Billing → Quotes) through Quotes: written as drafts, then sent, accepted for the client or declined. */
class QuoteController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filter(Quote::query()->with(['client', 'lead']), $request, ['status', 'lead_id', 'client_id', 'number', 'order_id']);

        return QuoteResource::collection($query->orderBy('id')->paginate($this->perPage($request))->withQueryString());
    }

    public function show(Quote $quote): QuoteResource
    {
        return new QuoteResource($quote);
    }

    public function store(StoreQuoteRequest $request, Quotes $quotes): JsonResponse
    {
        return (new QuoteResource($quotes->create($request->validated())->refresh()))->response()->setStatusCode(201);
    }

    /** POST /quotes/{id}/send: the Quote email with the link to its page; a draft is Sent from then on. */
    public function send(Quote $quote, Quotes $quotes): JsonResponse|QuoteResource
    {
        return $this->attempt(fn () => $quotes->send($quote), $quote);
    }

    /** POST /quotes/{id}/accept: the client said yes another way; the order is placed (a lead becomes a client). */
    public function accept(Quote $quote, Quotes $quotes): JsonResponse|QuoteResource
    {
        return $this->attempt(fn () => $quotes->accept($quote, byAdmin: true), $quote);
    }

    /** POST /quotes/{id}/decline */
    public function decline(DeclineQuoteRequest $request, Quote $quote, Quotes $quotes): JsonResponse|QuoteResource
    {
        return $this->attempt(fn () => $quotes->decline($quote, $request->validated('reason')), $quote);
    }

    /** Not an accepted one: its order points to it. */
    public function destroy(Quote $quote): JsonResponse
    {
        if ($quote->status === QuoteStatus::Accepted) {
            return $this->conflict('An accepted quote is not deleted.');
        }

        ActivityLog::record('quote.deleted', "Quote {$quote->number} deleted through the API", client: $quote->client);
        $quote->delete();

        return response()->json(null, 204);
    }

    private function attempt(callable $action, Quote $quote): JsonResponse|QuoteResource
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return $this->conflict($e->getMessage());
        }

        return new QuoteResource($quote->refresh());
    }
}
