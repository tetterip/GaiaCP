<?php

namespace App\Privacy;

use App\Enums\ClientStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Erasure on request (GDPR article 17) for a client whose services have ended: their details are replaced by
 * "Anonymized #id", and their login, sign-ins, tickets, emails, leads and requests are deleted. Payment notices,
 * invoices, payments and credit stay as they were, with the name and address they were issued to, because the law
 * keeps them for years; so do the services' names and prices. The activity log stays too, with the client's name,
 * email and phone taken out of its texts. Cannot be undone.
 */
class AnonymizeClient
{
    public const EMAIL_DOMAIN = 'anonymized.invalid';

    /** @return list<string> why this client cannot be anonymized yet; empty when it can */
    public static function blockers(Client $client): array
    {
        $blockers = [];

        if (self::done($client)) {
            return [__('This client is already anonymized.')];
        }

        if ($client->services()->whereIn('status', [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended])->exists()) {
            $blockers[] = __('It has services that are pending, active or suspended: terminate or cancel them first.');
        }

        if ($client->invoices()->where('status', InvoiceStatus::Unpaid)->exists()) {
            $blockers[] = __('It has unpaid payment notices: record the payment or cancel them first.');
        }

        if ((int) $client->credit_cents !== 0) {
            $blockers[] = __('It has credit: refund or use it first.');
        }

        return $blockers;
    }

    public static function done(Client $client): bool
    {
        return str_ends_with((string) $client->email, '@'.self::EMAIL_DOMAIN);
    }

    public function __invoke(Client $client): void
    {
        if ($blockers = self::blockers($client)) {
            throw new RuntimeException(implode(' ', $blockers));
        }

        // What identifies the client in free text (activity log descriptions), longest first so a name inside the company is caught too
        $identifiers = collect([$client->email, $client->name, $client->company, $client->phone, $client->vat_number, $client->first_name, $client->last_name])
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => mb_strlen($value) >= 3)
            ->unique()
            ->sortByDesc(fn (string $value) => mb_strlen($value))
            ->values()
            ->all();

        DB::transaction(function () use ($client, $identifiers) {
            $id = $client->id;

            foreach (User::query()->where('client_id', $id)->get() as $user) {
                if ($user->role === UserRole::Client) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                    $user->delete(); // its devices, passkeys and push subscriptions go with it
                } else {
                    $user->forceFill(['client_id' => null])->save(); // an admin linked to the client stays an admin
                }
            }

            $tickets = Ticket::query()->where('client_id', $id)->pluck('id');
            TicketAttachment::query()->whereHas('message', fn ($query) => $query->whereIn('ticket_id', $tickets))->get()->each->delete(); // the files too
            Ticket::query()->whereKey($tickets)->delete();

            foreach (['client_logins', 'client_change_requests', 'client_message_recipients', 'cancellation_requests', 'upgrade_requests', 'email_logs', 'leads'] as $table) {
                DB::table($table)->where('client_id', $id)->delete();
            }

            $services = DB::table('services')->where('client_id', $id);
            (clone $services)->update(['password' => null, 'notes' => null]);
            DB::table('domains')->whereIn('service_id', $services->pluck('id'))->update(['epp_code' => null]);
            DB::table('quotes')->where('client_id', $id)->update(['accepted_ip' => null]);

            DB::table('activity_logs')->where('client_id', $id)->orderBy('id')->each(function (object $log) use ($identifiers) {
                $description = $this->scrub((string) $log->description, $identifiers);
                $properties = $log->properties === null ? null : $this->scrub((string) $log->properties, $identifiers);

                if ($description !== $log->description || $properties !== $log->properties) {
                    DB::table('activity_logs')->where('id', $log->id)->update(['description' => $description, 'properties' => $properties]);
                }
            });

            $client->forceFill([
                'first_name' => 'Anonymized',
                'last_name' => "#{$id}",
                'company' => null,
                'email' => "client-{$id}@".self::EMAIL_DOMAIN,
                'phone' => null,
                'address1' => null,
                'address2' => null,
                'city' => null,
                'state' => null,
                'postcode' => null,
                'vat_number' => null,
                'profession' => null,
                'tax_office' => null,
                'notes' => null,
                'custom_fields' => null,
                'status' => ClientStatus::Closed,
                'renewal_reminders' => false,
                'overdue_notices' => false,
                'informational_emails' => false,
            ])->save();

            ActivityLog::record('client.anonymized', "Client #{$id} anonymized on request", client: $client);
        });
    }

    /** @param  list<string>  $identifiers */
    private function scrub(string $text, array $identifiers): string
    {
        foreach ($identifiers as $identifier) {
            $text = str_ireplace([$identifier, trim((string) json_encode($identifier), '"')], '[removed]', $text);
        }

        return $text;
    }
}
