<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A catalog of what you sell (e.g. "Hosting Basic", "Maintenance"). Picking a
 * product on a new service fills in its type, label, price, cycle and package;
 * the service keeps its own copy, so changing a product's price later doesn't
 * touch existing services.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // App\Enums\ServiceType
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents');
            $table->char('currency', 3)->default('EUR');
            $table->string('billing_cycle'); // App\Enums\BillingCycle
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('package')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('type')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });

        Schema::dropIfExists('products');
    }
};
