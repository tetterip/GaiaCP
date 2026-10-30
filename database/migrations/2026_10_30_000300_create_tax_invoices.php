<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Invoices (Settings → Invoices, off by default): issued from a payment notice once it is paid, with their own
        // number, and never changed or deleted after. Everything printed on one is a copy taken when it was issued.
        Schema::create('tax_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('invoice'); // credit notes later
            $table->string('number')->unique();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete(); // the payment notice
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->date('paid_on')->nullable();
            $table->char('currency', 3);
            $table->json('seller');
            $table->json('bill_to');
            $table->unsignedInteger('subtotal_cents');
            $table->string('tax_name')->nullable();
            $table->decimal('tax_rate', 6, 3)->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->string('tax_note')->nullable();
            $table->unsignedInteger('total_cents');
            $table->string('payment_methods')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'date']);
        });

        Schema::create('tax_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents');
        });

        // The last number given in each series ("invoice" or "invoice:2026" when the format has {YEAR}), locked while
        // the next one is taken so two payments at once never get the same number or leave a gap.
        Schema::create('invoice_series', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_series');
        Schema::dropIfExists('tax_invoice_items');
        Schema::dropIfExists('tax_invoices');
    }
};
