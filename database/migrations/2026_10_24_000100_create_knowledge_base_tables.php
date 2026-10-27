<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Support → Knowledge base: help articles for the client area, in categories, a text per language. */
    public function up(): void
    {
        Schema::create('kb_categories', function (Blueprint $table) {
            $table->id();
            $table->json('names'); // {locale: name}
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('kb_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kb_category_id')->nullable()->constrained('kb_categories')->nullOnDelete();
            $table->string('slug', 120)->unique();
            $table->json('texts'); // {locale: {title, body}}
            $table->longText('search_text')->nullable(); // every title and body, lower case without accents (KbArticle::searchable())
            $table->boolean('published')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('helpful_yes')->default(0);
            $table->unsignedInteger('helpful_no')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['published', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_articles');
        Schema::dropIfExists('kb_categories');
    }
};
