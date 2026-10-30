<?php

namespace App\Ai;

use App\Enums\ServiceStatus;
use App\Models\KbArticle;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Collection;

/**
 * What the AI assistant is sent about a ticket (Assistant::ticketReply(), ticketSummary(), kbArticle()): its public
 * messages, never the admins' notes or files, with the client's name, company, email and phone and any other email
 * address or phone number replaced by placeholders; the client's services (type, name, status, paid until; never
 * passwords or servers) and the published knowledge base articles closest to it.
 */
class TicketContext
{
    /** Placeholders the AI service sees instead of the client's details; restore() puts the name back. */
    public const CUSTOMER = '[Customer]';

    public const EMAIL = '[email]';

    public const PHONE = '[phone]';

    public const DOMAIN = 'example.com';

    /** At most this many characters of one message, and of the whole conversation. */
    public const MAX_MESSAGE = 4000;

    public const MAX_THREAD = 12000;

    /** Knowledge base articles sent with a reply draft, and the characters of each. */
    public const ARTICLES = 3;

    public const MAX_ARTICLE = 2500;

    public function __construct(public readonly Ticket $ticket) {}

    /**
     * The conversation, oldest first, each message under "Customer" or "Support" and its date; the newest messages
     * when it is too long. $withDomains = false also hides the client's domain names (for a knowledge base article).
     */
    public function thread(bool $withDomains = true): string
    {
        $messages = $this->ticket->messages()->public()->orderByDesc('id')->get();
        $parts = [];
        $length = 0;

        foreach ($messages as $message) {
            /** @var TicketMessage $message */
            $text = mb_substr($this->redact(trim($message->body), $withDomains), 0, self::MAX_MESSAGE);
            $part = ($message->from_client ? 'Customer' : 'Support').' ('.$message->created_at?->toDateString().'):'."\n".$text;

            if ($parts !== [] && $length + mb_strlen($part) > self::MAX_THREAD) {
                break;
            }

            $parts[] = $part;
            $length += mb_strlen($part);
        }

        return 'Subject: '.$this->redact($this->ticket->subject, $withDomains)."\n\n".implode("\n\n", array_reverse($parts));
    }

    /** The client's services that are not over, one per line; the one the ticket is about marked. */
    public function services(): string
    {
        if ($this->ticket->client_id === null) {
            return '';
        }

        return Service::query()->with('product')
            ->where('client_id', $this->ticket->client_id)
            ->whereNotIn('status', [ServiceStatus::Terminated, ServiceStatus::Cancelled])
            ->orderBy('id')->limit(30)->get()
            ->map(fn (Service $service) => '- '.Assistant::typeName($service->type).': '.($service->domain ?: $service->label)
                .($service->product ? " ({$service->product->name})" : '')
                .', '.$service->status->value
                .($service->next_due_date ? ', paid until '.$service->next_due_date->toDateString() : '')
                .($service->id === $this->ticket->service_id ? ' (this ticket is about it)' : ''))
            ->implode("\n");
    }

    /**
     * The published articles that share the most words with the subject and the client's last message, in the
     * client's language: title => text.
     *
     * @return array<string, string>
     */
    public function articles(): array
    {
        $last = $this->ticket->messages()->public()->where('from_client', true)->latest('id')->value('body');
        $words = collect(explode(' ', KbArticle::searchable($this->ticket->subject.' '.$last)))
            ->filter(fn (string $word) => mb_strlen($word) >= 4)->unique()->take(30);

        if ($words->isEmpty()) {
            return [];
        }

        $locale = $this->ticket->client?->preferredLocale();

        return KbArticle::query()->published()->limit(500)->get()
            ->map(fn (KbArticle $article) => [$article, $words->filter(fn (string $word) => str_contains((string) $article->search_text, $word))->count()])
            ->filter(fn (array $scored) => $scored[1] > 0)
            ->sortByDesc(fn (array $scored) => $scored[1])
            ->take(self::ARTICLES)
            ->mapWithKeys(function (array $scored) use ($locale) {
                $text = $scored[0]->text($locale);

                return [$text['title'] => mb_substr($text['body'], 0, self::MAX_ARTICLE)];
            })
            ->all();
    }

    /** The AI's text with the client's first name where it wrote the placeholder. */
    public function restore(string $text): string
    {
        $name = $this->ticket->client?->first_name ?: trim(explode(' ', $this->ticket->requesterName())[0] ?? '');

        // Without a name the placeholder stays, for the admin to fill in
        return $name !== '' ? str_replace(self::CUSTOMER, $name, $text) : $text;
    }

    /** $text without the client's details (and, unless $withDomains, the client's domain names). */
    public function redact(string $text, bool $withDomains = true): string
    {
        $client = $this->ticket->client;

        if (! $withDomains && $client !== null) {
            $domains = Service::query()->where('client_id', $client->id)->whereNotNull('domain')->pluck('domain')
                ->filter()->sortByDesc(fn (string $domain) => mb_strlen($domain));

            // mail.example.gr → mail.example.com
            foreach ($domains as $domain) {
                $text = (string) preg_replace('/(?<![\p{L}\p{N}.-])((?:[\p{L}\p{N}-]+\.)*)'.preg_quote($domain, '/').'(?![\p{L}\p{N}-])/iu', '${1}'.self::DOMAIN, $text);
            }
        }

        $text = (string) preg_replace('/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.\p{L}{2,}/u', self::EMAIL, $text);

        // Ten digits or more, as phone numbers (and IBANs) have; dates and amounts have fewer
        $text = (string) preg_replace_callback('/(?<![\d\p{L}])\+?\d[\d\s().\/-]{6,}\d(?![\d\p{L}])/u',
            fn (array $m) => preg_match_all('/\d/', $m[0]) >= 10 ? self::PHONE : $m[0], $text);

        // Whole words only, so a domain name such as karali-bakery.gr stays as it is
        if (($names = $this->names())->isNotEmpty()) {
            $text = (string) preg_replace('/(?<![\p{L}\p{N}@.-])(?:'.$names->map(fn (string $name) => preg_quote($name, '/'))->implode('|').')(?![\p{L}\p{N}-])/iu', self::CUSTOMER, $text);
        }

        return $text;
    }

    /** The client's names and company, longest first, so "Maria Papadopoulou" goes before "Maria". @return Collection<int, string> */
    private function names(): Collection
    {
        $client = $this->ticket->client;
        $parts = $client !== null
            ? [$client->first_name.' '.$client->last_name, $client->first_name, $client->last_name, $client->company]
            : [$this->ticket->name, ...explode(' ', (string) $this->ticket->name)];

        return collect($parts)->map(fn ($part) => trim((string) $part))
            ->filter(fn (string $part) => mb_strlen($part) >= 2)
            ->unique()
            ->sortByDesc(fn (string $part) => mb_strlen($part))
            ->values();
    }
}
