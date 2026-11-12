<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every Eurobank card payment started for a payment notice (Plugins\Eurobank): what it asked for and what the
     * bank answered, so a payment is recorded once however often the answer arrives. status: open, processing, paid,
     * cancelled (on the bank's page), failed (refused), expired (no answer came), unmatched (paid at the bank but not
     * recorded here; `error` says why).
     */
    public function up(): void
    {
        Schema::create('eurobank_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->index()->constrained()->cascadeOnDelete();
            $table->string('reference', 50)->unique();
            $table->boolean('live')->default(false);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 20)->default('open')->index();
            $table->string('transaction_id')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('pay_method', 50)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eurobank_payments');
    }
};
