<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A client asking from the client area to cancel a service (App\Actions\CancellationRequests); an admin decides. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // App\Enums\CancellationType
            $table->text('reason')->nullable();
            // The domain service of the same name the client also asked not to renew
            $table->foreignId('domain_service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('status')->default('pending'); // App\Enums\ChangeRequestStatus
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_requests');
    }
};
