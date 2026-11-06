<?php

namespace App\Privacy;

use App\Models\ActivityLog;
use App\Models\Client;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Everything GaiaCP keeps about one client, for a data access request (GDPR articles 15 and 20): a zip with one JSON file
 * per kind of record and the files the client attached to tickets. Secrets stay out (passwords, EPP codes, tokens, keys),
 * and so do the admins' internal ticket notes.
 */
class ClientDataExport
{
    /** Columns never exported, whatever the table. */
    private const SECRET_COLUMNS = [
        'password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes', 'token_hash',
        'epp_code', 'private_key', 'access_token', 'credential_id', 'public_key', 'token', 'payload',
    ];

    public function __construct(private Client $client) {}

    /** @return array<string, list<array<string, mixed>>> file name (without .json) => rows */
    public function sections(): array
    {
        $id = $this->client->id;
        $userIds = DB::table('users')->where('client_id', $id)->pluck('id')->all();
        $serviceIds = DB::table('services')->where('client_id', $id)->pluck('id')->all();
        $invoiceIds = DB::table('invoices')->where('client_id', $id)->pluck('id')->all();
        $taxInvoiceIds = DB::table('tax_invoices')->where('client_id', $id)->pluck('id')->all();
        $ticketIds = DB::table('tickets')->where('client_id', $id)->pluck('id')->all();

        return array_filter([
            'client' => $this->rows(DB::table('clients')->where('id', $id)),
            'client-area-login' => $this->rows(DB::table('users')->whereIn('id', $userIds)
                ->select(['id', 'name', 'email', 'locale', 'email_verified_at', 'last_login_at', 'created_at'])),
            'sign-ins' => $this->rows(DB::table('client_logins')->where('client_id', $id)),
            'devices' => $this->rows(DB::table('client_sessions')->whereIn('user_id', $userIds)),
            'passkeys' => $this->rows(DB::table('passkeys')->whereIn('user_id', $userIds)->select(['name', 'user_agent', 'last_used_at', 'created_at'])),
            'services' => $this->rows(DB::table('services')->where('client_id', $id)),
            'domains' => $this->rows(DB::table('domains')->whereIn('service_id', $serviceIds)),
            'orders' => $this->rows(DB::table('orders')->where('client_id', $id)),
            'payment-notices' => $this->rows(DB::table('invoices')->where('client_id', $id)),
            'payment-notice-lines' => $this->rows(DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)),
            'invoices' => $this->rows(DB::table('tax_invoices')->where('client_id', $id)),
            'invoice-lines' => $this->rows(DB::table('tax_invoice_items')->whereIn('tax_invoice_id', $taxInvoiceIds)),
            'payments' => $this->rows(DB::table('payments')->where('client_id', $id)->select(['id', 'service_id', 'amount_cents', 'currency', 'paid_on', 'method', 'reference', 'created_at'])),
            'credit' => $this->rows(DB::table('credit_transactions')->where('client_id', $id)->select(['id', 'type', 'amount_cents', 'balance_cents', 'currency', 'description', 'invoice_id', 'payment_id', 'method', 'reference', 'created_at'])),
            'quotes' => $this->rows(DB::table('quotes')->where('client_id', $id)->where('status', '!=', 'draft')),
            'tickets' => $this->rows(DB::table('tickets')->where('client_id', $id)->select(['id', 'service_id', 'subject', 'status', 'email', 'name', 'last_reply_at', 'closed_at', 'created_at'])),
            'ticket-messages' => $this->rows(DB::table('ticket_messages')->whereIn('ticket_id', $ticketIds)->where('internal', false)
                ->select(['id', 'ticket_id', 'from_client', 'body', 'created_at'])),
            'ticket-files' => $this->rows($this->attachments($ticketIds)->select(['ticket_attachments.ticket_message_id', 'ticket_attachments.name', 'ticket_attachments.path', 'ticket_attachments.size', 'ticket_attachments.created_at'])),
            'emails' => $this->rows(DB::table('email_logs')->where('client_id', $id)->where('to_client', true)
                ->select(['id', 'to', 'subject', 'body', 'status', 'created_at'])),
            'messages-to-clients' => $this->rows(DB::table('client_message_recipients')->where('client_id', $id)->select(['client_message_id', 'email', 'sent_at', 'created_at'])),
            'requests-to-change-details' => $this->rows(DB::table('client_change_requests')->where('client_id', $id)),
            'cancellation-requests' => $this->rows(DB::table('cancellation_requests')->where('client_id', $id)),
            'upgrade-requests' => $this->rows(DB::table('upgrade_requests')->where('client_id', $id)),
            'activity' => $this->rows(DB::table('activity_logs')->where('client_id', $id)->select(['id', 'service_id', 'event', 'description', 'created_at'])),
        ]);
    }

    /** Writes the zip to a private temporary file and gives its path; the caller deletes it once sent. */
    public function zip(): string
    {
        $dir = storage_path('app/private/exports');
        File::ensureDirectoryExists($dir, 0700);
        $path = $dir.'/client-'.$this->client->id.'-'.Str::random(16).'.zip';

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write {$path}.");
        }

        $zip->addFromString('README.txt', $this->readme());

        foreach ($this->sections() as $name => $rows) {
            $zip->addFromString("{$name}.json", json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $disk = Storage::disk('local');
        $ticketIds = DB::table('tickets')->where('client_id', $this->client->id)->pluck('id')->all();

        foreach ($this->attachments($ticketIds)->whereNull('ticket_attachments.removed_at')->get(['ticket_attachments.path']) as $file) {
            if ($disk->exists($file->path)) {
                $zip->addFile($disk->path($file->path), 'ticket-files/'.basename($file->path));
            }
        }

        $zip->close();

        ActivityLog::record('client.data_exported', "Data of {$this->client->displayName()} exported", client: $this->client);

        return $path;
    }

    public function fileName(): string
    {
        return 'client-'.$this->client->id.'-data-'.now()->format('Y-m-d').'.zip';
    }

    private function attachments(array $ticketIds): Builder
    {
        return DB::table('ticket_attachments')
            ->join('ticket_messages', 'ticket_messages.id', '=', 'ticket_attachments.ticket_message_id')
            ->whereIn('ticket_messages.ticket_id', $ticketIds)
            ->where('ticket_messages.internal', false);
    }

    /** @return list<array<string, mixed>> */
    private function rows(Builder $query): array
    {
        return $query->get()
            ->map(function (object $row) {
                $row = array_diff_key((array) $row, array_flip(self::SECRET_COLUMNS));

                // JSON columns come back as text: give them as JSON, not as a string inside it
                return array_map(fn ($value) => is_string($value) && ($value[0] ?? '') !== '' && in_array($value[0], ['{', '['], true) && json_validate($value)
                    ? json_decode($value, true) : $value, $row);
            })
            ->values()
            ->all();
    }

    private function readme(): string
    {
        $name = $this->client->displayName();

        return <<<TXT
        The data {$name} has with us (client {$this->client->id}), exported on {$this->now()}.

        Each .json file is one kind of record: client.json your details, services.json and domains.json what you have
        with us, payment-notices.json, invoices.json and payments.json your billing, tickets.json and ticket-messages.json
        your support requests (the files you attached are in ticket-files/), emails.json the emails we sent you, sign-ins.json
        and devices.json where your account was used from, activity.json what was done on your account.
        Amounts are in cents; times (created_at, sent_at and the like) are in UTC, dates (due_date) as they are.
        Passwords, EPP codes and other secrets are not included.

        TXT;
    }

    private function now(): string
    {
        return now()->toDateTimeString();
    }
}
