<?php

namespace Plugins\TicketMail;

use App\Models\ActivityLog;
use App\Plugins\PluginManager;
use DirectoryTree\ImapEngine\FileMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * POST /webhooks/ticket-mail/{token}: an email a mail provider received for the support address, posted as the whole
 * message (MIME), becomes a ticket or an answer like one read over IMAP (InboundMail). The secret in the address is
 * Settings → Email to ticket → Receive by webhook. The raw message is read from Mailgun's body-mime (a route that
 * forwards to a URL ending in /mime), SendGrid Inbound Parse's email ("POST the raw, full MIME message"), Postmark's
 * RawEmail ("Include raw email content"), or a body sent as message/rfc822. A message posted twice is added once.
 */
class InboundWebhook
{
    /** The largest message taken, in bytes (attachments over Settings → Support's limit are left out anyway). */
    public const MAX_BYTES = 25 * 1024 * 1024;

    public function __invoke(Request $request, string $token, PluginManager $plugins, InboundMail $inbound): JsonResponse
    {
        $secret = (string) config('gaiacp.ticket_mail.inbound_token');

        if (! $plugins->enabled('ticket-mail') || $secret === '' || ! hash_equals($secret, $token)) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $raw = self::raw($request);

        if ($raw === '') {
            // 406: Mailgun and the others don't retry a call that can never work.
            return response()->json(['error' => 'No raw email (MIME) in the call'], 406);
        }

        if (strlen($raw) > self::MAX_BYTES) {
            ActivityLog::record('ticket_mail.skipped', 'Email posted to the ticket webhook not added: larger than 25 MB');

            return response()->json(['error' => 'Too large'], 406);
        }

        try {
            [$outcome, $detail] = $inbound->process(new FileMessage($raw));
        } catch (Throwable $e) {
            Log::error('Email to ticket (webhook) failed', ['error' => $e->getMessage()]);
            ActivityLog::record('ticket_mail.failed', Str::limit("Email posted to the ticket webhook could not be added to a ticket: {$e->getMessage()}", 250));

            // The provider tries again later; a message added in between is not added twice.
            return response()->json(['error' => 'Failed'], 500);
        }

        return response()->json(['outcome' => $outcome, 'detail' => $detail]);
    }

    private static function raw(Request $request): string
    {
        foreach (['body-mime', 'email', 'RawEmail'] as $field) {
            if (is_string($value = $request->input($field)) && trim($value) !== '') {
                return $value;
            }
        }

        $type = strtolower((string) $request->header('Content-Type'));

        return str_starts_with($type, 'message/rfc822') || str_starts_with($type, 'text/plain') ? (string) $request->getContent() : '';
    }
}
