<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Email to ticket 1.3.0 counts an email as the client's only when the receiving mail server confirmed the sender
 * ("verified"). A mailbox (or webhook address) set up before keeps what it did until now ("forged"), so answers from
 * clients whose server adds no Authentication-Results don't suddenly open tickets without a client. Settings →
 * Support → Email to ticket changes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $configured = DB::table('settings')->whereIn('key', ['ticket_mail.host', 'ticket_mail.inbound_token'])
            ->whereNotNull('value')->where('value', '!=', 'null')->exists();

        if ($configured && ! DB::table('settings')->where('key', 'ticket_mail.sender_check')->exists()) {
            DB::table('settings')->insert(['key' => 'ticket_mail.sender_check', 'value' => json_encode('forged'), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // The value stays: it is what the install used before
    }
};
