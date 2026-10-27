<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordInvoicePayment;
use App\Enums\InvoiceStatus;
use App\Enums\MailTemplate;
use App\Http\Requests\Api\SendInvoiceRequest;
use App\Http\Requests\Api\StorePaymentRequest;
use App\Http\Resources\Api\InvoiceResource;
use App\Http\Resources\Api\PaymentResource;
use App\Invoices\SendInvoiceEmails;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Payments\PaymentMethods;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/** Payment notices: made by orders and the daily run; paid, emailed or cancelled here as on the notice's page. */
class InvoiceController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filter(Invoice::query()->with('items'), $request, ['client_id', 'order_id', 'status', 'number']);

        return InvoiceResource::collection($query->orderBy('id')->paginate($this->perPage($request))->withQueryString());
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice->load('items'));
    }

    /** POST /invoices/{id}/pay: records the payment of every unpaid line (Record payment on the notice). */
    public function pay(StorePaymentRequest $request, Invoice $invoice, RecordInvoicePayment $recorder): JsonResponse
    {
        try {
            $payments = $recorder->handle(
                $invoice,
                CarbonImmutable::parse($request->validated('paid_on', today()->toDateString())),
                $request->validated('method') ?? PaymentMethods::defaultFor($invoice->client),
                $request->validated('reference'),
            );
        } catch (RuntimeException $e) {
            return $this->conflict($e->getMessage());
        }

        return response()->json([
            'data' => new InvoiceResource($invoice->refresh()->load('items')),
            'payments' => PaymentResource::collection($payments),
            'renewal_errors' => (object) $recorder->renewalErrors,
        ], 201);
    }

    /** POST /invoices/{id}/send: emails it to the client again (Send email on the notice). */
    public function send(SendInvoiceRequest $request, Invoice $invoice, SendInvoiceEmails $emails): JsonResponse
    {
        $template = MailTemplate::from($request->validated('template') ?? ($invoice->emailed_at ? MailTemplate::InvoiceModified : MailTemplate::InvoiceCreated)->value);

        if (! $emails->send($invoice->load('items', 'client'), $template, evenIfOff: true)) {
            return $this->failed('The email was not sent; the email log says why.');
        }

        return response()->json(['message' => 'Sent.']);
    }

    /** POST /invoices/{id}/cancel: an unpaid one, like Cancel on the notice. */
    public function cancel(Invoice $invoice): JsonResponse|InvoiceResource
    {
        if ($invoice->status !== InvoiceStatus::Unpaid) {
            return $this->conflict('Only an unpaid payment notice can be cancelled.');
        }

        $invoice->update(['status' => InvoiceStatus::Cancelled]);
        ActivityLog::record('invoice.cancelled', "Payment notice {$invoice->number} cancelled", client: $invoice->client, properties: ['invoice_id' => $invoice->id]);

        return new InvoiceResource($invoice->refresh()->load('items'));
    }
}
