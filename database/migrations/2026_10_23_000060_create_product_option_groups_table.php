<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product options (Settings → Product options): groups of options attached to products, each option priced per choice
 * and billing cycle (App\Support\ProductOptions). A service keeps what was picked, with its price then, in options.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_option_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('options')->nullable();
            $table->timestamps();
        });

        Schema::create('product_product_option_group', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_option_group_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'product_option_group_id']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->json('options')->nullable()->after('package');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('options');
        });
        Schema::dropIfExists('product_product_option_group');
        Schema::dropIfExists('product_option_groups');
    }
};
