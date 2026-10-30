<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A product's name and description in the other languages (`name` / `description` stay the default language's),
    // and its public page (/<group>/<slug>, App\Filament\Client\Pages\ProductPage): off until switched on, with a title,
    // a search result description and a text (Markdown) per language.
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('names')->nullable();
            $table->json('descriptions')->nullable();
            $table->string('slug', 100)->nullable()->unique();
            $table->boolean('page')->default(false);
            $table->json('page_titles')->nullable();
            $table->json('page_descriptions')->nullable();
            $table->json('page_texts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['names', 'descriptions', 'slug', 'page', 'page_titles', 'page_descriptions', 'page_texts']);
        });
    }
};
