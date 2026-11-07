<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordServicePayment;
use App\Actions\ReversePayment;
use App\Enums\ServiceStatus;
use App\Http\Requests\Api\StorePaymentRequest;
use App\Http\Resources\Api\PaymentResource;
use App\Models\Payment;
use App\Models\Service;
use App\Payments\PaymentMethods;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Payments recorded by hand, through RecordPayment as the service's Record payment does: one billing cycle, the due
 * date moves on, a service suspended for non-payment is unsuspended. Reverse (DELETE) undoes the last one.
 */
class PaymentController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['paid_from' => ['nullable', 'date'], 'paid_to' => ['nullable', 'date']]);

        $query = $this->filter(Payment::query(), $request, ['client_id', 'service_id', 'method', 'reference']);

        if ($request->filled('paid_from')) {
            $query->whereOnDate('paid_on', '>=', $request->query('paid_from'));
        }

        if ($request->filled('paid_to')) {
            $query->whereOnDate('paid_on', '<=', $request->query('paid_to'));
        }

        return PaymentResource::collection($query->orderBy('id')->paginate($this->perPage($request))->withQueryString());
    }

    public function show(Payment $payment): PaymentResource
    {
        return new PaymentResource($payment);
    }

    public function store(StorePaymentRequest $request, RecordServicePayment $recorder): JsonResponse
    {
        $service = Service::query()->with('client')->findOrFail($request->validated('service_id'));

        // As in the panel: a pending item already paid waits to be set up, not for another cycle's payment
        if (! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)
            && ! ($service->status === ServiceStatus::Pending && ! $service->payments()->exists())) {
            return $this->conflict('Payments are recorded for active or suspended services, and for a pending one not paid yet.');
        }

        // Without an amount: what its payment notice asks for the cycle, with tax. Another amount says what the difference is.
        $amount = (int) ($request->validated('amount_cents') ?? RecordServicePayment::suggested($service));
        $options = RecordServicePayment::options($service, $amount);
        $difference = $request->validated('difference');

        if ($options !== [] && ! array_key_exists((string) $difference, $options)) {
            return response()->json([
                'message' => sprintf('The payment notice asks for %d cents for this cycle. Send difference: %s.', RecordServicePayment::expected($service), implode(' or ', array_keys($options))),
                'errors' => ['difference' => ['Required when amount_cents differs from the payment notice, one of: '.implode(', ', array_keys($options)).'.']],
            ], 422);
        }

        $payment = $recorder->handle(
            $service,
            $amount,
            CarbonImmutable::parse($request->validated('paid_on', today()->toDateString())),
            $request->validated('method') ?? PaymentMethods::defaultFor($service->client),
            $request->validated('reference'),
            $request->validated('notes'),
            $options !== [] ? $difference : null,
        );

        return response()->json(['data' => $payment ? (new PaymentResource($payment))->resolve($request) : null] + array_filter([
            'credited_cents' => $recorder->credited ?: null,
            'renewal_error' => $recorder->recorder->renewalError,
            'setup_error' => $recorder->recorder->setupError,
        ], fn ($value) => $value !== null), 201);
    }

    /** DELETE /payments/{id}: reverses it (only a service's latest payment, while its due date is still the one it set). */
    public function destroy(Payment $payment, ReversePayment $reverse): JsonResponse
    {
        if (! ReversePayment::canReverse($payment)) {
            return $this->conflict('Only the latest payment of a service can be reversed, while its due date is still the one that payment set.');
        }

        try {
            $reverse->handle($payment);
        } catch (RuntimeException $e) {
            return $this->conflict($e->getMessage());
        }

        return response()->json(null, 204);
    }
}
