<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Product tags (Settings → Product tags, App\Models\ProductTag): free labels such as New or SSD, each with a name per
    // language and a colour. Visible ones are badges on the order pages; every one filters the admin lists and Messages
    // to clients.
    public function up(): void
    {
        Schema::create('product_tags', function (Blueprint $table) {
            $table->id();
            $table->json('names');
            $table->string('color', 20)->default('gray');
            $table->boolean('visible')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('product_product_tag', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'product_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_product_tag');
        Schema::dropIfExists('product_tags');
    }
};
