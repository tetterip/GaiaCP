<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A client asking from the client area to move a service to another product (App\Actions\UpgradeRequests); an admin decides. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upgrade_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // The price per cycle the client was shown, in the service's currency
            $table->unsignedBigInteger('price_cents');
            $table->text('notes')->nullable();
            $table->string('status')->default('pending'); // App\Enums\ChangeRequestStatus
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upgrade_requests');
    }
};
