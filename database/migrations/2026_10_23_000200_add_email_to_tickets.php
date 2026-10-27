<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tickets that arrive by email: who sent it (a ticket from an unknown sender has no client until an admin links
    // one), and each message's Message-ID, so a message read twice is added once.
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('email')->nullable()->after('client_id');
            $table->string('name')->nullable()->after('email');
        });

        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->string('email_message_id')->nullable()->index()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropIndex(['email_message_id']);
            $table->dropColumn('email_message_id');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['email', 'name']);
        });
    }
};
