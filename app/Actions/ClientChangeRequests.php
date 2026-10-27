<?php

namespace App\Actions;

use App\Enums\AdminArea;
use App\Enums\ChangeRequestStatus;
use App\Mail\ClientChangeRequestMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientChangeRequest;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Changes a client asks for from the client area. Their email and phone save at once (the profile page); the details
 * below are on payment notices and domain registrations, so they wait for an admin to approve. A client has at most
 * one pending request: asking again replaces it.
 */
class ClientChangeRequests
{
    /** Fields that need approval => their label (translated where shown). */
    public const FIELDS = [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'company' => 'Company name',
        'vat_number' => 'VAT number',
        'tax_office' => 'Tax office',
        'profession' => 'Profession',
        'address1' => 'Address 1',
        'address2' => 'Address 2',
        'city' => 'City',
        'state' => 'State / region',
        'postcode' => 'Postcode',
        'country' => 'Country',
    ];

    /**
     * Records what differs from the client's current details; null when nothing does (a pending request is then
     * withdrawn, since the client went back to what is saved).
     *
     * @param  array<string, mixed>  $values
     */
    public function request(Client $client, array $values): ?ClientChangeRequest
    {
        $changes = [];

        foreach (array_keys(self::FIELDS) as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }

            $old = self::normalize($client->{$field});
            $new = self::normalize($values[$field]);

            if ($old !== $new) {
                $changes[$field] = [$old, $new];
            }
        }

        $pending = $client->changeRequests()->pending()->first();

        if ($changes === []) {
            if ($pending) {
                $pending->delete();
                ActivityLog::record('client.change_withdrawn', "{$client->name} withdrew their change request", client: $client);
            }

            return null;
        }

        if ($pending && $pending->fields === $changes) {
            return $pending; // asked for the same again: nothing new to tell the admins
        }

        $request = $pending ?? new ClientChangeRequest(['client_id' => $client->id]);
        $request->fill(['fields' => $changes])->save();

        ActivityLog::record('client.change_requested', "{$client->name} asked to change: ".implode(', ', array_keys($changes)), client: $client, properties: ['changes' => $changes]);

        $this->notifyAdmins($request);

        return $request;
    }

    /** Saves the asked-for values on the client (their login follows the name, Client::booted()). */
    public function approve(ClientChangeRequest $request): void
    {
        if ($request->status !== ChangeRequestStatus::Pending) {
            return;
        }

        $request->client->update($request->requestedValues());
        $this->decide($request, ChangeRequestStatus::Approved);

        ActivityLog::record('client.change_approved', 'Change request approved: '.implode(', ', array_keys($request->fields)), client: $request->client);
    }

    public function reject(ClientChangeRequest $request): void
    {
        if ($request->status !== ChangeRequestStatus::Pending) {
            return;
        }

        $this->decide($request, ChangeRequestStatus::Rejected);

        ActivityLog::record('client.change_rejected', 'Change request declined: '.implode(', ', array_keys($request->fields)), client: $request->client);
    }

    /** "Company name", in the language in use. */
    public static function label(string $field): string
    {
        return __(self::FIELDS[$field] ?? $field);
    }

    private function decide(ClientChangeRequest $request, ChangeRequestStatus $status): void
    {
        $request->update(['status' => $status, 'decided_by' => auth()->guard('web')->id(), 'decided_at' => now()]);
    }

    /** To the admins who open clients and receive system emails, while Settings → Automation allows it. */
    private function notifyAdmins(ClientChangeRequest $request): void
    {
        if (! config('gaiacp.automation.change_request_email')) {
            return;
        }

        $admins = User::query()->admins()->where('active', true)->where('system_emails', true)->get()
            ->filter(fn (User $admin) => $admin->canUseArea(AdminArea::Clients));

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->locale(Locales::normalize($admin->locale))->send(new ClientChangeRequestMail($request));
            } catch (Throwable $e) {
                Log::error('Change request email failed', ['user_id' => $admin->id, 'error' => $e->getMessage()]);
                ActivityLog::record('email.failed', "Change request email to {$admin->email} failed: {$e->getMessage()}", client: $request->client);
            }
        }
    }

    private static function normalize(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
