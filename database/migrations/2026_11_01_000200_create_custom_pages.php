<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Pages of your own (Support → Pages, App\Models\CustomPage): About us, Terms, a guide... written with the editor,
    // a title, text and description per language, at /pages/{slug} and added to the client area's menus.
    public function up(): void
    {
        Schema::create('custom_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->json('texts');
            $table->boolean('published')->default(false);
            $table->boolean('clients_only')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_pages');
    }
};
