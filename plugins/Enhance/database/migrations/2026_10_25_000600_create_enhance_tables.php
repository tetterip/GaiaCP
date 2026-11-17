<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enhance names things by ids, not usernames: the customer organisation of each client on each server, and the
     * subscription (and website) of each hosting service.
     */
    public function up(): void
    {
        Schema::create('enhance_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('org_id');
            $table->timestamps();

            $table->unique(['server_id', 'client_id']);
        });

        Schema::create('enhance_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('org_id');
            $table->string('subscription_id');
            $table->string('website_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enhance_accounts');
        Schema::dropIfExists('enhance_customers');
    }
};
