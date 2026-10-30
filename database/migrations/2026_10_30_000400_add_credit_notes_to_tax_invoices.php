<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Credit notes are rows of tax_invoices too (type credit_note), with their own series. They keep the amounts
        // positive like an invoice; credited_invoice_id is the invoice they undo, and each line names the invoice line
        // it credits, so no line is credited twice.
        Schema::table('tax_invoices', function (Blueprint $table) {
            $table->foreignId('credited_invoice_id')->nullable()->after('invoice_id')->constrained('tax_invoices')->restrictOnDelete();
            $table->string('reason')->nullable()->after('tax_note');
        });

        Schema::table('tax_invoice_items', function (Blueprint $table) {
            $table->foreignId('credited_item_id')->nullable()->after('tax_invoice_id')->constrained('tax_invoice_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tax_invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credited_item_id');
        });

        Schema::table('tax_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credited_invoice_id');
            $table->dropColumn('reason');
        });
    }
};
