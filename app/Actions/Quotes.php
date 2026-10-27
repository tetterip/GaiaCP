<?php

namespace App\Actions;

use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\LeadStatus;
use App\Enums\MailTemplate;
use App\Enums\QuoteStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Mail\ClientMailer;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Quote;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Support\Currencies;
use App\Support\DomainName;
use App\Support\Locales;
use App\Support\MailTemplates;
use App\Support\TermsOfService;
use BackedEnum;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Quotes (Billing → Quotes): written for a lead or a client, sent by email with a link to their page, accepted or
 * declined there (or by an admin on their behalf). Accepting makes the lead a client (with the client area invitation)
 * and places the order through PlaceOrder at the quote's prices, its one-off lines as other services billed once;
 * from there it is an order like any other. A sent quote past its date expires (gaiacp:expire-quotes).
 */
class Quotes
{
    public function __construct(private ClientMailer $mailer, private PlaceOrder $placeOrder, private Leads $leads, private ClientOrder $clientOrder) {}

    /** The days a new quote is valid (Settings → Sales). */
    public static function validDays(): int
    {
        return max(1, (int) (config('gaiacp.sales.quote_valid_days') ?: 30));
    }

    /**
     * The attributes of a quote from the form: its items as New order made them (enums as their values, prices in
     * cents), the one-off lines, and the currency and language of who it is for.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function attributes(array $data): array
    {
        $client = filled($data['client_id'] ?? null) ? Client::query()->find($data['client_id']) : null;

        return [
            'client_id' => $client?->id,
            'lead_id' => $client ? null : ($data['lead_id'] ?? null),
            'currency' => Currencies::forClient($client),
            'locale' => $client?->locale ?? Locales::default(),
            'items' => collect($data['items'] ?? [])->values()->map(fn (array $item) => self::plain($item))->all(),
            'extras' => collect($data['extras'] ?? [])->values()
                ->filter(fn (array $extra) => filled($extra['description'] ?? null) && filled($extra['amount_cents'] ?? null))
                ->map(fn (array $extra) => ['description' => trim($extra['description']), 'amount_cents' => (int) $extra['amount_cents']])
                ->values()->all() ?: null,
            'intro' => filled($data['intro'] ?? null) ? trim($data['intro']) : null,
            'footer' => filled($data['footer'] ?? null) ? trim($data['footer']) : null,
            'valid_until' => $data['valid_until'] ?? today()->addDays(self::validDays()),
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function plain(array $item): array
    {
        return collect($item)
            ->map(fn ($value) => $value instanceof BackedEnum ? $value->value : $value)
            ->reject(fn ($value, $key) => $value === null || $value === '' || $key === 'years')
            ->all();
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Quote
    {
        $quote = Quote::query()->create(self::attributes($data) + ['user_id' => auth()->id()]);

        ActivityLog::record('quote.created', "Quote {$quote->number} for {$quote->recipientName()} created", client: $quote->client, properties: ['quote_id' => $quote->id, 'lead_id' => $quote->lead_id]);

        return $quote;
    }

    /**
     * Emails the quote with the link to its page; a draft is sent from then on. The lead it is for is now "Quote sent".
     *
     * @throws RuntimeException when it can't be sent
     */
    public function send(Quote $quote): void
    {
        if (! $quote->status->isOpen()) {
            throw new RuntimeException(__('Only a draft or sent quote can be sent.'));
        }

        if (blank($quote->recipientEmail())) {
            throw new RuntimeException(__('Add an email address to the lead or client first.'));
        }

        if (blank($quote->items) && blank($quote->extras)) {
            throw new RuntimeException(__('Add something to the quote first.'));
        }

        if ($quote->valid_until->lt(today())) {
            throw new RuntimeException(__('Its valid until date has passed: change it first.'));
        }

        $quote->update(['status' => QuoteStatus::Sent, 'sent_at' => now()]);

        if ($quote->lead && in_array($quote->lead->status, [LeadStatus::New, LeadStatus::Contacted], true)) {
            $quote->lead->update(['status' => LeadStatus::Quoted]);
        }

        $sent = $this->mailer->send(MailTemplate::Quote, $quote->recipient(), fn () => [
            'quote_number' => $quote->number,
            'quote_valid_until' => $quote->valid_until->format(AppServiceProvider::dateFormat()),
            'quote_intro' => $quote->intro,
            'quote_items' => MailTemplates::table([__('Item'), __('Billing cycle'), __('First payment'), __('Renews at')], collect($quote->lines())->map(fn (array $line) => [
                $line['description'],
                $line['cycle'],
                $quote->money($line['first_cents']),
                $line['renews_cents'] === null ? '' : $quote->money($line['renews_cents']),
            ])),
            'quote_total' => $quote->totalText(),
            'quote_url' => $quote->url(),
        ], evenIfOff: true);

        ActivityLog::record('quote.sent', "Quote {$quote->number} sent to {$quote->recipientEmail()}".($sent ? '' : ' (email not sent)'), client: $quote->client, properties: ['quote_id' => $quote->id, 'lead_id' => $quote->lead_id, 'number' => $quote->number, 'valid_until' => $quote->valid_until->toDateString()]);

        if (! $sent) {
            throw new RuntimeException(__('The quote is marked as sent, but the email could not go out (see Settings → Logs → Email log). Copy its link instead.'));
        }
    }

    /**
     * Accepts the quote: the lead becomes a client (or is linked to the client with its email), then the order is
     * placed at the quote's prices. Only once: a second acceptance finds it accepted already.
     *
     * @param  bool  $byAdmin  an admin accepts it for the client (e.g. they said yes on the phone): no terms asked
     *
     * @throws RuntimeException when it can't be accepted (its message is for the person accepting)
     */
    public function accept(Quote $quote, bool $termsAccepted = false, ?string $ip = null, bool $byAdmin = false): Quote
    {
        if (! $byAdmin && TermsOfService::required() && ! $termsAccepted) {
            throw new RuntimeException(__('Please accept the Terms of Service to continue.'));
        }

        if ($byAdmin ? ! $quote->status->isOpen() : ! $quote->acceptable()) {
            throw new RuntimeException(__('This quote can no longer be accepted. Please contact us for a new one.'));
        }

        $this->checkDomains($quote);

        // Claim it first, so two clicks never place two orders.
        $claimed = Quote::query()->whereKey($quote->id)->whereIn('status', $byAdmin ? [QuoteStatus::Draft, QuoteStatus::Sent] : [QuoteStatus::Sent])
            ->update(['status' => QuoteStatus::Accepted, 'accepted_at' => now()]);

        if ($claimed === 0) {
            throw new RuntimeException(__('This quote can no longer be accepted. Please contact us for a new one.'));
        }

        $previous = $quote->status;

        try {
            $client = $this->clientFor($quote);
            $terms = ! $byAdmin && TermsOfService::required() ? TermsOfService::acceptance() : [];
            $order = $this->placeOrder->handle($client, $this->orderItems($quote), "Quote {$quote->number}", $terms);
        } catch (Throwable $e) {
            $quote->newQuery()->whereKey($quote->id)->update(['status' => $previous, 'accepted_at' => null]);

            throw $e;
        }

        $quote->update([
            'status' => QuoteStatus::Accepted,
            'client_id' => $client->id,
            'order_id' => $order->id,
            'accepted_ip' => $byAdmin ? null : $ip,
            'terms_accepted_at' => $terms['terms_accepted_at'] ?? null,
            'terms_version' => $terms['terms_version'] ?? null,
        ]);

        ActivityLog::record('quote.accepted', "Quote {$quote->number} accepted".($byAdmin ? ' by an admin for the client' : '').", order #{$order->id}", client: $client, properties: ['quote_id' => $quote->id, 'order_id' => $order->id, 'lead_id' => $quote->lead_id]);
        $this->clientOrder->notifyAdmins($order);

        return $quote->refresh();
    }

    /** The client the order is for: the quote's, or its lead's (made a client now, with the client area invitation). */
    private function clientFor(Quote $quote): Client
    {
        if ($quote->client) {
            return $quote->client;
        }

        $lead = $quote->lead ?? throw new RuntimeException(__('This quote is not for anyone any more. Please contact us.'));

        if ($lead->client) {
            return $lead->client;
        }

        if (blank($lead->email)) {
            throw new RuntimeException(__('Add an email address to the lead first.'));
        }

        return $this->leads->convert($lead, [], true, Leads::existingClient($lead->email));
    }

    /**
     * A domain to register or transfer that is already with us can't be ordered again.
     *
     * @throws RuntimeException
     */
    private function checkDomains(Quote $quote): void
    {
        foreach ($quote->items ?? [] as $item) {
            if (($item['type'] ?? null) !== ServiceType::Domain->value || ($item['registration_type'] ?? null) === DomainRegistrationType::External->value) {
                continue;
            }

            $domain = DomainName::normalize($item['domain'] ?? null);

            if (Service::query()->where('type', ServiceType::Domain)->where('domain', $domain)->whereNotIn('status', [ServiceStatus::Cancelled, ServiceStatus::Terminated])->exists()) {
                throw new RuntimeException(__(':domain is already with us, so this quote can\'t be accepted as it is. Please contact us.', ['domain' => $domain]));
            }
        }
    }

    /**
     * The quote's items for PlaceOrder, then each one-off line as an other service billed once (BillingCycle::OneTime):
     * on the first payment notice with the rest, Completed once paid.
     *
     * @return list<array<string, mixed>>
     */
    public function orderItems(Quote $quote): array
    {
        $items = array_values($quote->items ?? []);

        foreach ($quote->extras ?? [] as $extra) {
            $items[] = [
                'type' => ServiceType::Other->value,
                'label' => mb_substr((string) $extra['description'], 0, 255),
                'billing_cycle' => BillingCycle::OneTime->value,
                'price_cents' => (int) $extra['amount_cents'],
            ];
        }

        if ($items === []) {
            throw new RuntimeException(__('This quote has nothing to order. Please contact us.'));
        }

        return $items;
    }

    public function decline(Quote $quote, ?string $reason = null): void
    {
        if ($quote->status !== QuoteStatus::Sent) {
            throw new RuntimeException(__('This quote can no longer be declined.'));
        }

        $quote->update(['status' => QuoteStatus::Declined, 'declined_at' => now(), 'decline_reason' => filled($reason) ? mb_substr(trim($reason), 0, 255) : null]);

        ActivityLog::record('quote.declined', "Quote {$quote->number} declined".(filled($reason) ? ": {$reason}" : ''), client: $quote->client, properties: ['quote_id' => $quote->id, 'lead_id' => $quote->lead_id]);
    }

    /** A new draft with the same lines, for the same lead or client, valid from today. */
    public function duplicate(Quote $quote): Quote
    {
        return $this->create([
            'client_id' => $quote->client_id ?? $quote->lead?->client_id,
            'lead_id' => $quote->lead_id,
            'items' => $quote->items,
            'extras' => $quote->extras,
            'intro' => $quote->intro,
            'footer' => $quote->footer,
            'notes' => $quote->notes,
        ]);
    }

    /**
     * Sent quotes whose valid until date has passed become Expired. Safe to re-run.
     *
     * @return Collection<int, Quote>
     */
    public function expire(bool $dryRun = false): Collection
    {
        $due = Quote::query()->where('status', QuoteStatus::Sent)->whereDate('valid_until', '<', today())->orderBy('valid_until')->get();

        if (! $dryRun) {
            foreach ($due as $quote) {
                $quote->update(['status' => QuoteStatus::Expired]);
                ActivityLog::record('quote.expired', "Quote {$quote->number} expired unanswered", client: $quote->client, properties: ['quote_id' => $quote->id, 'lead_id' => $quote->lead_id]);
            }
        }

        return $due;
    }
}
