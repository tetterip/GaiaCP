<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A product group's public page (/<slug>, App\Filament\Client\Pages\ProductGroupPage), for search engines and visitors:
    // off until switched on, with a title and a search result description, a text (Markdown) and questions with their
    // answers, each per language.
    public function up(): void
    {
        Schema::table('product_groups', function (Blueprint $table) {
            $table->boolean('page')->default(false);
            $table->json('page_titles')->nullable();
            $table->json('page_descriptions')->nullable();
            $table->json('page_texts')->nullable();
            $table->json('faq')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_groups', fn (Blueprint $table) => $table->dropColumn(['page', 'page_titles', 'page_descriptions', 'page_texts', 'faq']));
    }
};
