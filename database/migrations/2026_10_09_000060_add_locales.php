<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // null = the default language (config app.locale); see App\Support\Locales.
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 12)->nullable()->after('email');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('locale', 12)->nullable()->after('email');
        });

        // An email's text in a language other than the default one; `email_templates` holds the
        // default language's text and the on/off switch. No row = the default language's text.
        Schema::create('email_template_translations', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('locale', 12);
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->timestamps();

            $table->unique(['key', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_translations');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('locale');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
