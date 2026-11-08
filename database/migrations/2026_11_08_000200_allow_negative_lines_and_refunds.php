<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A notice line can be negative (a Discount, App\Actions\RecordServicePayment), and so can the invoice and credit note
 * lines copied from it, and a payment (a refund, App\Actions\RefundPayment). MySQL and MariaDB refused those in the
 * unsigned columns; SQLite never checked.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'invoice_items' => ['amount_cents', 'tax_cents', 'total_cents'],
        'tax_invoice_items' => ['amount_cents', 'tax_cents', 'total_cents'],
        'payments' => ['amount_cents'],
    ];

    public function up(): void
    {
        $this->change(signed: true);
    }

    public function down(): void
    {
        // Negative rows would not fit back; leave the columns signed.
    }

    private function change(bool $signed): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $signed) {
                foreach ($columns as $column) {
                    $definition = $blueprint->integer($column, unsigned: ! $signed);

                    if ($column === 'tax_cents') {
                        $definition->default(0);
                    }

                    $definition->change();
                }
            });
        }
    }
};
