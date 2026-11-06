<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REST API calls sent with an Idempotency-Key header (App\Http\Middleware\IdempotentApiRequest): the answer each key got,
 * so a call sent again (a retry after a timeout) gets the same answer instead of recording a second payment.
 * Kept a day (gaiacp:prune-logs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key');
            $table->char('fingerprint', 64); // sha256 of method, path and body: the same key with another call is refused
            $table->unsignedSmallInteger('status')->nullable(); // null while the first call is still running
            $table->longText('response')->nullable();
            $table->timestamp('created_at')->index();

            $table->unique(['api_key_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
