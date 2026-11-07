<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lines of deleted services left on unpaid payment notices (before a deleted service took its lines off): they kept
 * the notice's total up, and kept it unpaid when nothing else was owed. Each comes off, the totals follow, a notice left
 * with no lines is cancelled and one whose other lines are all paid is marked paid (Issue invoice on it makes its
 * invoice, when Settings → Invoices is on).
 */
return new class extends Migration
{
    public function up(): void
    {
        $notices = DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.status', 'unpaid')->whereNull('invoice_items.service_id')->where('invoice_items.credit', false)
            ->distinct()->pluck('invoice_items.invoice_id');

        foreach ($notices as $id) {
            DB::transaction(function () use ($id) {
                DB::table('invoice_items')->where('invoice_id', $id)->whereNull('service_id')->where('credit', false)->delete();

                $items = DB::table('invoice_items')->where('invoice_id', $id)->get();

                if ($items->isEmpty()) {
                    DB::table('invoices')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);

                    return;
                }

                $tax = (int) $items->sum('tax_cents');
                $total = (int) $items->sum('total_cents');
                $owed = $items->contains(fn ($item) => $item->payment_id === null && $item->credit_transaction_id === null);
                $paidOn = DB::table('payments')->whereIn('id', $items->pluck('payment_id')->filter())->max('paid_on');

                DB::table('invoices')->where('id', $id)->update(array_merge([
                    'subtotal_cents' => $total - $tax,
                    'tax_cents' => $tax,
                    'total_cents' => $total,
                    'updated_at' => now(),
                ], $owed ? [] : ['status' => 'paid', 'paid_on' => $paidOn ?? now()->toDateString()]));
            });
        }
    }

    public function down(): void
    {
        // The lines named no service any more: nothing to put back.
    }
};
