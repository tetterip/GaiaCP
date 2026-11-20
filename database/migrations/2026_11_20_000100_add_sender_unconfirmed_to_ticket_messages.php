<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An emailed message whose From address the receiving mail server did not confirm (Email to ticket), marked for the admins. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->boolean('sender_unconfirmed')->default(false)->after('email_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropColumn('sender_unconfirmed');
        });
    }
};
