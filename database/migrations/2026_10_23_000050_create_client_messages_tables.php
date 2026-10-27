<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clients → Messages to clients (App\Models\ClientMessage): one text per language, emailed to the clients its filters
 * match (a recipient row each, sent a few a minute by gaiacp:send-client-messages) and/or shown on client area Home.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_messages', function (Blueprint $table) {
            $table->id();
            $table->string('kind')->default('info'); // App\Enums\ClientMessageKind
            $table->json('texts'); // {locale: {subject, body}}
            $table->json('filters')->nullable(); // servers, products, tlds, types, locales
            $table->boolean('send_email')->default(true);
            $table->boolean('show_in_client_area')->default(false);
            $table->date('show_from')->nullable();
            $table->date('show_until')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('client_message_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            $table->unique(['client_message_id', 'client_id']);
            $table->index(['sent_at', 'failed_at']);
        });

        // "Informational emails" (Messages to clients of that kind); service messages reach everyone
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('informational_emails')->default(true)->after('overdue_notices');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('informational_emails'));
        Schema::dropIfExists('client_message_recipients');
        Schema::dropIfExists('client_messages');
    }
};
