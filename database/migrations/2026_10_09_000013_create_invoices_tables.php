<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('tax_exempt')->default(false)->after('tax_office');
        });

        // Payment notices (proformas), not legal invoices: those are issued in the accounting tool.
        // One per client and due date; the tax is a snapshot of the rule that applied when it was created.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // PF-2026-0001
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('unpaid');
            $table->date('date');
            $table->date('due_date');
            $table->char('currency', 3);
            $table->unsignedInteger('subtotal_cents');
            $table->string('tax_name')->nullable();
            $table->decimal('tax_rate', 6, 3)->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->date('paid_on')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_date']);
        });

        // One line per service and due date. payment_id is set when that cycle is paid.
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->date('due_date');
            $table->date('period_end');
            $table->unsignedInteger('amount_cents'); // the service price, as entered (net or gross per tax_inclusive)
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();

            $table->index(['service_id', 'due_date']);
        });

        // Reminders and overdue notices sent for an invoice; the unique key makes the daily run safe to re-run.
        Schema::create('invoice_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // "before:7", "overdue:1"
            $table->string('sent_to');
            $table->timestamp('sent_at');

            $table->unique(['invoice_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_reminders');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('tax_exempt');
        });
    }
};
