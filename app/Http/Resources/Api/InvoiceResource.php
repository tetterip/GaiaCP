<?php

namespace App\Http\Resources\Api;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'client_id' => $this->client_id,
            'order_id' => $this->order_id,
            'status' => $this->status?->value,
            'date' => $this->date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'paid_on' => $this->paid_on?->toDateString(),
            'currency' => $this->currency,
            'subtotal_cents' => $this->subtotal_cents,
            'tax_name' => $this->tax_name,
            'tax_rate' => $this->tax_rate,
            'tax_inclusive' => (bool) $this->tax_inclusive,
            'tax_cents' => $this->tax_cents,
            'total_cents' => $this->total_cents,
            'bill_to' => $this->bill_to ?: (object) [],
            'emailed_at' => $this->emailed_at?->toIso8601String(),
            'items' => $this->items->map(fn (InvoiceItem $item) => [
                'id' => $item->id,
                'service_id' => $item->service_id,
                'fee' => $item->fee,
                'description' => $item->description,
                'due_date' => $item->due_date?->toDateString(),
                'period_end' => $item->period_end?->toDateString(),
                'amount_cents' => $item->amount_cents,
                'tax_cents' => $item->tax_cents,
                'total_cents' => $item->total_cents,
                'payment_id' => $item->payment_id,
            ])->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
