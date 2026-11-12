<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every Mastercard Gateway card payment started for a payment notice (Plugins\MastercardGateway): what it asked for
     * and what the gateway said of it, so a payment is recorded once however often it is looked up. status: open,
     * processing, paid, failed (declined), expired (never finished), unmatched (paid at the gateway but not recorded
     * here; `error` says why).
     */
    public function up(): void
    {
        Schema::create('mastercard_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->index()->constrained()->cascadeOnDelete();
            $table->string('order_id', 40)->unique();
            $table->boolean('live')->default(false);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('session_id')->nullable();
            $table->text('success_indicator')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->string('transaction_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mastercard_payments');
    }
};
