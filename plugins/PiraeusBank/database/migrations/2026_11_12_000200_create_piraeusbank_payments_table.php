<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every Piraeus Bank payment started for a payment notice (Plugins\PiraeusBank): the bank's transaction ticket
     * (encrypted: it signs the bank's answer), what it asked for and what the bank answered, so a payment is recorded
     * once. status: open, processing, paid, pending (an IRIS transfer not confirmed yet), cancelled (on the bank's
     * page), failed (refused), expired (no answer came), unmatched (paid at the bank but not recorded; `error` says why).
     */
    public function up(): void
    {
        Schema::create('piraeusbank_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->index()->constrained()->cascadeOnDelete();
            $table->string('reference', 50)->unique();
            $table->text('ticket')->nullable();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 20)->default('open')->index();
            $table->string('transaction_id')->nullable();
            $table->string('support_reference')->nullable();
            $table->string('pay_method', 50)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('piraeusbank_payments');
    }
};
