<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Concerns\StoresTimesInUtc;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A support ticket (App\Actions\Tickets): a client's question with its messages, in a department, maybe about one of
 * their services. Numbered T-000123 from its id. One that came by email keeps the sender's `email` and `name`; from an
 * unknown sender it has no client until an admin links one. One from the public area's contact form
 * (Tickets::openFromVisitor()) has no client either, and a secret `access_token`: its sender reads and answers it at
 * publicUrl() without a login, until an admin links a client.
 *
 * @property CarbonImmutable|null $last_reply_at
 * @property CarbonImmutable|null $closed_at
 */
#[Hidden(['access_token'])]
#[Fillable(['client_id', 'email', 'name', 'access_token', 'service_id', 'department_id', 'assigned_to', 'subject', 'summary', 'status', 'priority', 'last_reply_at', 'closed_at'])]
class Ticket extends Model
{
    use StoresTimesInUtc;

    protected $attributes = [
        'status' => 'open',
        'priority' => 'medium',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'last_reply_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** The sender's page for a ticket without a client (Client\Pages\VisitorTicket); null once it has a client. */
    public function publicUrl(): ?string
    {
        return $this->client_id === null && filled($this->access_token)
            ? route('filament.client.pages.support-ticket', ['token' => $this->access_token])
            : null;
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<TicketDepartment, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(TicketDepartment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<TicketMessage, $this> oldest first */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    /** The client's name, else the name (or address) it was emailed from. */
    public function requesterName(): string
    {
        return $this->client?->name ?? ($this->name ?: (string) $this->email);
    }

    /**
     * The thread id its emails carry (Message-ID, References), so an emailed answer finds the ticket again. It holds the
     * ticket's secret (emailToken()), so an answer can't be pointed at another ticket by writing its number.
     */
    public function emailThreadId(): string
    {
        return "ticket-{$this->id}.{$this->emailToken()}@".self::emailHost();
    }

    /** A new Message-ID for one of its emails. */
    public function newEmailMessageId(): string
    {
        return "ticket-{$this->id}.{$this->emailToken()}.".Str::lower(Str::random(12)).'@'.self::emailHost();
    }

    /** The ticket's secret in its emails' ids: made from its id and APP_KEY, so nothing is stored and no one can guess it. */
    public function emailToken(): string
    {
        return self::tokenFor((int) $this->id);
    }

    /**
     * The ticket an answer's In-Reply-To / References point to, when it answers one of our ticket emails, and whether
     * the reference carries that ticket's secret (`signed`). Unsigned: an id without it, as emails sent before the secret was
     * added have, or one written by hand. A signed reference wins over an unsigned one.
     *
     * @param  list<string>  $messageIds
     * @return array{id: int, signed: bool}|null
     */
    public static function fromEmailReferences(array $messageIds): ?array
    {
        $host = preg_quote(self::emailHost(), '/');
        $found = null;

        foreach ($messageIds as $messageId) {
            if (! preg_match('/^<?ticket-(\d{1,18})((?:\.[a-z0-9]+)*)@'.$host.'>?$/i', trim($messageId), $match)) {
                continue;
            }

            $id = (int) $match[1];
            $token = strtolower(explode('.', ltrim($match[2], '.'))[0]);

            if (strlen($token) === 16 && hash_equals(self::tokenFor($id), $token)) {
                return ['id' => $id, 'signed' => true];
            }

            $found ??= ['id' => $id, 'signed' => false];
        }

        return $found;
    }

    private static function tokenFor(int $id): string
    {
        return substr(hash_hmac('sha256', "ticket-email:{$id}", (string) config('app.key')), 0, 16);
    }

    private static function emailHost(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'gaiacp.local';
    }

    public function getNumberAttribute(): string
    {
        return 'T-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /** @param Builder<Ticket> $query waiting on an admin */
    public function scopeWaiting(Builder $query): void
    {
        $query->where('status', TicketStatus::Open);
    }

    /** @param Builder<Ticket> $query not closed */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', '!=', TicketStatus::Closed);
    }
}
