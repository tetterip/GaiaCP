<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Debugging logs under Settings → System: the module log (requests to and responses from cPanel/WHM, Plesk,
 * registrars and ΑΑΔΕ, secrets masked) and the email log (every email sent, or that failed to send).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_logs', function (Blueprint $table) {
            $table->id();
            $table->string('module'); // cpanel, plesk, easy, aade
            $table->string('action');
            $table->string('url', 2048);
            $table->longText('request')->nullable();
            $table->unsignedSmallInteger('status')->nullable(); // HTTP status; null when nothing came back
            $table->longText('response')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->index();
        });

        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template')->nullable(); // MailTemplate value; null for admin emails (backup, test)
            $table->string('to', 1024);
            $table->string('cc', 1024)->nullable();
            $table->string('bcc', 1024)->nullable();
            $table->string('subject')->nullable();
            $table->longText('body')->nullable(); // HTML as sent
            $table->string('status'); // sent, failed
            $table->text('error')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('module_logs');
    }
};
