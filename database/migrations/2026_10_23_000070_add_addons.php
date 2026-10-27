<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add-ons: a product marked addon is sold on top of a service of the products it fits (product_addon); its service
 * points at that one (services.parent_service_id), shares its cycle and due date and follows its status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('addon')->default(false)->after('type');
        });

        Schema::create('product_addon', function (Blueprint $table) {
            $table->foreignId('addon_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->primary(['addon_id', 'product_id']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('parent_service_id')->nullable()->after('order_id')->constrained('services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_service_id');
        });
        Schema::dropIfExists('product_addon');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('addon');
        });
    }
};
