<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A row can now hold only the on/off switch; a null subject or body means the built-in text.
        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('subject')->nullable()->change();
            $table->text('body')->nullable()->change();
            $table->boolean('enabled')->nullable()->after('body'); // null = MailTemplate::enabledByDefault()
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
    }
};
