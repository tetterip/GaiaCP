<?php

namespace App\Http\Controllers\Api;

use App\Actions\CancelOrder;
use App\Actions\ClientOrder;
use App\Actions\CompleteOrder;
use App\Actions\PlaceOrder;
use App\Http\Requests\Api\StoreOrderRequest;
use App\Http\Resources\Api\OrderResource;
use App\Models\Client;
use App\Models\Order;
use App\Provisioning\ProvisioningException;
use App\Support\Coupons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Orders through PlaceOrder, priced like the client area's cart (ClientOrder: what clients can order, prices from
 * the catalogue and Settings → Domain pricing), with the same payment notice, emails and Auto setup.
 */
class OrderController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filter(Order::query()->with(['services.domainDetails', 'invoices']), $request, ['client_id', 'status']);

        return OrderResource::collection($query->orderBy('id')->paginate($this->perPage($request))->withQueryString());
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($order->load(['services.domainDetails', 'invoices']));
    }

    public function store(StoreOrderRequest $request, ClientOrder $catalogue, PlaceOrder $placer): JsonResponse
    {
        $client = Client::query()->findOrFail($request->validated('client_id'));

        $lines = collect($request->validated('items'))->map(fn (array $item) => [
            'product' => $item['product_id'] ?? ClientOrder::DOMAIN_ONLY,
            'billing_cycle' => $item['billing_cycle'] ?? null,
            'options' => $item['options'] ?? [],
            'addons' => $item['addons'] ?? [],
            'domain' => $item['domain'] ?? null,
            'domain_option' => $item['domain_option'] ?? (isset($item['product_id']) ? ClientOrder::OWN : ClientOrder::REGISTER),
            'years' => $item['years'] ?? 1,
            'epp_code' => $item['epp_code'] ?? null,
        ])->all();

        try {
            $items = $catalogue->cartItems($lines, $client);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['items' => collect($e->errors())->flatten()->all()]);
        }

        $coupon = null;

        if (filled($request->validated('coupon'))) {
            Coupons::check($coupon = Coupons::find($request->validated('coupon')), $client, $items);
        }

        $order = $placer->handle($client, $items, $request->validated('notes'), coupon: $coupon);

        return (new OrderResource($order->load(['services.domainDetails', 'invoices'])))
            ->additional(['setup_errors' => (object) $placer->setupErrors])
            ->response()
            ->setStatusCode(201);
    }

    /** POST /orders/{id}/complete: sets up what is pending (Complete order); what failed stays pending, with why. */
    public function complete(Order $order, CompleteOrder $complete): JsonResponse
    {
        try {
            $errors = $complete->handle($order);
        } catch (ProvisioningException $e) {
            return $this->conflict($e->getMessage());
        }

        return (new OrderResource($order->refresh()->load(['services.domainDetails', 'invoices'])))
            ->additional(['setup_errors' => (object) $errors])
            ->response();
    }

    /** POST /orders/{id}/cancel: only while nothing of it is set up. */
    public function cancel(Order $order, CancelOrder $cancel): JsonResponse|OrderResource
    {
        if (! CancelOrder::canCancel($order)) {
            return $this->conflict('Only a pending order with every item still pending can be cancelled; terminate its active items instead.');
        }

        $cancel->handle($order);

        return new OrderResource($order->refresh()->load(['services.domainDetails', 'invoices']));
    }
}
