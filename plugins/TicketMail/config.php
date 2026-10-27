<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // The support mailbox read over IMAP, edited under Settings → Email to ticket. The password is stored encrypted.
    'gaiacp.ticket_mail' => [
        'host' => null,
        'port' => 993,
        'encryption' => 'ssl', // ssl, starttls or none
        'verify_tls' => true,
        'username' => null,
        'password' => null,
        'folder' => 'INBOX',
        'after' => 'seen', // what happens to a message once read: seen (marked as read) or delete
        'department_id' => null, // department of a new ticket sent to an address no department has
        'unknown_senders' => 'open', // email from someone who is not a client: open (a ticket without a client) or ignore
        'max_per_hour' => 10, // new tickets one sender may open by email in an hour
        // The secret in the address a mail provider posts emails to (POST /webhooks/ticket-mail/{token}); null = off.
        // Made with New webhook address under Settings → Email to ticket, stored encrypted.
        'inbound_token' => null,
    ],
];
