<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Support → Downloads: files for visitors or clients, in categories, a title and description per language. */
    public function up(): void
    {
        Schema::create('download_categories', function (Blueprint $table) {
            $table->id();
            $table->json('names'); // {locale: name}
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('download_category_id')->nullable()->constrained('download_categories')->nullOnDelete();
            $table->json('texts'); // {locale: {title, description}}
            $table->string('path'); // on the private disk (storage/app/private/downloads)
            $table->string('file_name'); // the name it is downloaded as
            $table->unsignedBigInteger('size')->default(0);
            $table->string('visibility', 20)->default('clients'); // App\Enums\DownloadVisibility
            $table->json('product_ids')->nullable(); // for visibility "products": an active service of one of these
            $table->boolean('published')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();

            $table->index(['published', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downloads');
        Schema::dropIfExists('download_categories');
    }
};
