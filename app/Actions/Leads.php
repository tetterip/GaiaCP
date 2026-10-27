<?php

namespace App\Actions;

use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Support\Locales;
use App\Support\Region;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Leads (Clients → Leads): prospective clients until they buy. Adding, notes, losing one with its reason, turning one
 * into a client (or linking it to the client who already has its email), and the morning follow-ups: the bell rings
 * once for each open lead whose next contact day has come (gaiacp:lead-follow-ups).
 */
class Leads
{
    public function __construct(private ClientAreaAccess $access) {}

    /** The sources offered on a lead (Settings → Sales), in the admin's language until they are changed there. */
    public static function sources(): array
    {
        $sources = config('gaiacp.sales.lead_sources');

        return is_array($sources) && $sources !== []
            ? array_values($sources)
            : [__('Phone'), __('Email'), __('Website'), __('Referral'), __('Social media'), __('Other')];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Lead
    {
        $lead = Lead::query()->create($data);

        ActivityLog::record('lead.created', "Lead {$lead->displayName()} added".($lead->source ? " (source: {$lead->source})" : ''), properties: ['lead_id' => $lead->id]);

        return $lead;
    }

    public function addNote(Lead $lead, string $body): LeadNote
    {
        $note = $lead->leadNotes()->create(['user_id' => auth()->id(), 'body' => trim($body)]);

        // A note means someone talked to them: a new lead is now contacted.
        if ($lead->status === LeadStatus::New) {
            $lead->update(['status' => LeadStatus::Contacted]);
        }

        return $note;
    }

    public function markLost(Lead $lead, ?string $reason): void
    {
        $lead->update(['status' => LeadStatus::Lost, 'lost_reason' => filled($reason) ? trim($reason) : null, 'closed_at' => now(), 'next_contact_on' => null]);

        ActivityLog::record('lead.lost', "Lead {$lead->displayName()} lost".(filled($reason) ? ": {$reason}" : ''), properties: ['lead_id' => $lead->id]);
    }

    public function reopen(Lead $lead): void
    {
        $lead->update(['status' => LeadStatus::Contacted, 'lost_reason' => null, 'closed_at' => null]);

        ActivityLog::record('lead.reopened', "Lead {$lead->displayName()} reopened", properties: ['lead_id' => $lead->id]);
    }

    /** The client who already has this email (the lead would be linked to them instead of a new one). */
    public static function existingClient(?string $email): ?Client
    {
        return filled($email) ? Client::query()->where('email', strtolower(trim($email)))->first() : null;
    }

    /**
     * Makes the lead a client with the details given (the lead's, as checked in the window), or links it to $existing.
     * The lead is Won from then on and points to the client. With $invite the new client gets the client area invitation.
     *
     * @param  array<string, mixed>  $details  first_name, last_name, company, email, phone, country
     *
     * @throws RuntimeException when it was already converted
     */
    public function convert(Lead $lead, array $details, bool $invite = false, ?Client $existing = null): Client
    {
        if ($lead->client_id !== null) {
            throw new RuntimeException(__('This lead is already a client.'));
        }

        $client = DB::transaction(function () use ($lead, $details, $existing) {
            $client = $existing ?? Client::query()->create(array_filter([
                'first_name' => trim((string) ($details['first_name'] ?? $lead->first_name)),
                'last_name' => trim((string) ($details['last_name'] ?? $lead->last_name)),
                'company' => filled($details['company'] ?? null) ? trim($details['company']) : null,
                'email' => strtolower(trim((string) ($details['email'] ?? $lead->email))),
                'phone' => filled($details['phone'] ?? null) ? trim($details['phone']) : null,
                'country' => ($details['country'] ?? null) ?: ($lead->country ?: Region::country()),
                'locale' => Locales::multiple() ? app()->getLocale() : null,
            ], fn ($value) => $value !== null));

            $lead->update(['status' => LeadStatus::Won, 'client_id' => $client->id, 'closed_at' => now(), 'next_contact_on' => null]);

            if ($existing === null) {
                ActivityLog::record('client.created', "Client {$client->name} created from lead {$lead->displayName()}", client: $client, properties: ['lead_id' => $lead->id]);
            }

            ActivityLog::record('lead.converted', $existing
                ? "Lead {$lead->displayName()} linked to the existing client {$client->name}"
                : "Lead {$lead->displayName()} became a client", client: $client, properties: ['lead_id' => $lead->id]);

            return $client;
        });

        if ($invite && $existing === null) {
            // The client is made either way: an invitation that can't go out (another login has the email) is only logged.
            try {
                $this->access->invite($client);
            } catch (RuntimeException $e) {
                ActivityLog::record('client_area.invite_failed', "Client area invitation for {$client->email} not sent: {$e->getMessage()}", client: $client);
            }
        }

        return $client;
    }

    /**
     * The open leads whose next contact day has come and the bell hasn't rung for yet: one `lead.follow_up` each
     * (the bell of the admin it is assigned to, else of everyone in Sales). Safe to re-run: once per contact day.
     *
     * @return Collection<int, Lead>
     */
    public function followUps(bool $dryRun = false): Collection
    {
        $due = Lead::query()->toContact()
            ->where(fn ($query) => $query->whereNull('follow_up_sent_on')->orWhereColumn('follow_up_sent_on', '!=', 'next_contact_on'))
            ->orderBy('next_contact_on')
            ->get();

        if ($dryRun) {
            return $due;
        }

        foreach ($due as $lead) {
            $lead->update(['follow_up_sent_on' => $lead->next_contact_on]);
            ActivityLog::record('lead.follow_up', "Contact lead {$lead->displayName()} (planned for {$lead->next_contact_on->toDateString()})", properties: ['lead_id' => $lead->id, 'assigned_to' => $lead->assigned_to]);
        }

        return $due;
    }
}
