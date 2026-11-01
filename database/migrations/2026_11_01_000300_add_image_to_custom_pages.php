<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A page's featured image (App\Support\PageImages): above its text and in link previews (og:image)
    public function up(): void
    {
        Schema::table('custom_pages', function (Blueprint $table) {
            $table->string('image', 120)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('custom_pages', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
