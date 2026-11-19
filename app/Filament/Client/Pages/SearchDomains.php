<?php

namespace App\Filament\Client\Pages;

use App\Actions\ClientOrder;
use App\Ai\AiException;
use App\Ai\Assistant;
use App\Captcha\Captcha;
use App\Enums\DomainAvailability;
use App\Filament\Client\Concerns\OpenToVisitors;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Tld;
use App\Support\Currencies;
use App\Support\DomainName;
use App\Support\DomainSearch;
use App\Support\DomainSsl;
use App\Support\Money;
use App\Support\PublicArea;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * Domains → Search for a domain: the client types a name and sees it with each TLD sold, free or taken
 * (App\Support\DomainSearch), with the registration price; a free one goes in the cart for its shortest term, a taken
 * one with a transfer price opens the Order page to transfer it. With an SSL certificate offered with domains
 * (DomainSsl), a free one also goes in with it for their first payment together. Shown while clients can order and a TLD has a
 * registration price. With the captcha on for it (Settings → General → Captcha), the first search asks for it, and a
 * solved one lets the client search for VERIFIED_MINUTES; RateLimiter caps searches either way.
 * Visitors search too while Settings → Public area says so; they add to the cart only while the public order page is on.
 * With Settings → AI assistant → Suggest domain names on, Suggest other names lists free names close to the one searched.
 * With Domain names from a description on, a few words about a business (describe()) list free names for it instead.
 */
class SearchDomains extends Page
{
    use OpenToVisitors;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?string $title = 'Search for a domain';

    protected static ?string $navigationLabel = 'Domain search';

    protected static ?string $slug = 'domain-search';

    protected static ?int $navigationSort = 2; // right after Domains (child items show only on their parent's page)

    protected string $view = 'filament.client.search-domains';

    /** Searches a client may make in a minute (each checks up to DomainSearch::MAX_RESULTS domains). */
    public const PER_MINUTE = DomainSearch::PER_MINUTE;

    /** How often a client (or a visitor's address) may press Suggest other names in an hour. */
    public const AI_PER_HOUR = 5;

    /** How long a solved captcha lets the client search without another. */
    public const VERIFIED_MINUTES = 15;

    private const VERIFIED = 'domain_search.verified_until';

    /** The captcha's token, from the widget. */
    public ?string $captcha = null;

    #[Url(as: 'q', except: '')]
    public string $query = '';

    /** @var list<array{domain: string, status: string, price: ?string, term: ?string, transfer: bool, with_ssl: ?string}> */
    public array $results = [];

    /** @var list<array{domain: string, status: string, price: ?string, term: ?string, transfer: bool, with_ssl: ?string}> the free names Suggest other names found */
    public array $suggestions = [];

    /** @var list<array{domain: string, status: string, price: ?string, term: ?string, transfer: bool, with_ssl: ?string}> the free names found for a description */
    public array $ideas = [];

    public ?string $unsupported = null;

    /** Words that make the search box's text a description of a business rather than a name. */
    public const DESCRIPTION_WORDS = 3;

    public static function canAccess(): bool
    {
        if (self::visitor()) {
            return PublicArea::domainSearch();
        }

        return Order::canAccess() && DomainSearch::tlds() !== [];
    }

    /** Signed-in clients can always order from here; visitors while the public order page is on. */
    public function canOrder(): bool
    {
        return ! self::visitor() || ClientOrder::publicEnabled();
    }

    public function mount(): void
    {
        // A description in the link is not sent to the AI service until the client presses Search
        if (filled($this->query) && ! $this->needsCaptcha() && ! $this->isDescription()) {
            $this->search();
        }
    }

    public function search(): void
    {
        $this->resetErrorBag();
        $this->results = [];
        $this->ideas = [];
        $this->unsupported = null;
        $candidates = DomainSearch::candidates($this->query);

        if (blank(trim($this->query))) {
            return;
        }

        if ($this->isDescription() && $this->canDescribe()) {
            $this->describe();

            return;
        }

        if ($candidates['domains'] === []) {
            throw ValidationException::withMessages(['query' => $this->canDescribe()
                ? __('Enter a name like example or example.gr, or describe your business in a few words.')
                : __('Enter a name like example or example.gr, with letters, digits and hyphens.')]);
        }

        $this->passCaptcha();

        if (! RateLimiter::attempt(DomainSearch::limiterKey(), self::PER_MINUTE, fn () => true)) {
            throw ValidationException::withMessages(['query' => __('Too many searches. Try again in a minute.')]);
        }

        $this->suggestions = [];
        $this->unsupported = $candidates['unsupported'];
        $this->results = array_map(fn (string $domain) => $this->row($domain), $candidates['domains']);
    }

    /** Whether the search box's text describes a business (DESCRIPTION_WORDS words or more) rather than naming a domain. */
    public function isDescription(): bool
    {
        return count(preg_split('/\s+/u', trim($this->query), -1, PREG_SPLIT_NO_EMPTY) ?: []) >= self::DESCRIPTION_WORDS;
    }

    /**
     * Whether a description gets names (Settings → AI assistant → Domain names from a description): signed-in clients,
     * and visitors only with Also for visitors without an account and a captcha plugin on (they always solve it first).
     */
    public function canDescribe(): bool
    {
        if (! Assistant::enabled('domain_ideas')) {
            return false;
        }

        return ! self::visitor() || (config('gaiacp.ai.domain_ideas_visitors') && Captcha::active() !== null);
    }

    /**
     * Names for the business described in the search box, with the first ending sold and .com (when sold too), each
     * checked like a search; only the free ones are listed. Counts in the same AI_PER_HOUR as Suggest other names.
     */
    private function describe(): void
    {
        $this->passCaptcha();

        if (! RateLimiter::attempt('domain-ai:'.DomainSearch::limiterKey(), self::AI_PER_HOUR, fn () => true, 3600)) {
            throw ValidationException::withMessages(['query' => __('You have asked for suggestions often. Try again in an hour.')]);
        }

        if (Assistant::domainsLimitReached()) {
            throw ValidationException::withMessages(['query' => __('No suggestions right now. Try again later.')]);
        }

        try {
            $labels = app(Assistant::class)->domainIdeas($this->query, app()->getLocale());
        } catch (AiException $e) {
            report($e);

            throw ValidationException::withMessages(['query' => __('No suggestions right now. Try again later.')]);
        }

        $endings = collect(DomainSearch::tlds())->map(fn (Tld $tld) => (string) $tld->getAttribute('tld'));
        $endings = collect([$endings->first(), $endings->contains('.com') ? '.com' : null])->filter()->unique();

        $this->suggestions = [];
        $this->ideas = collect($labels)
            ->crossJoin($endings)
            ->map(fn (array $pair) => $pair[0].$pair[1])
            ->reject(fn (string $domain) => DomainName::problem($domain) !== null)
            ->map(fn (string $domain) => $this->row($domain))
            ->filter(fn (array $row) => DomainAvailability::from($row['status'])->orderable())
            ->values()
            ->all();

        if ($this->ideas === []) {
            throw ValidationException::withMessages(['query' => __('The names suggested are taken. Try describing it in other words.')]);
        }
    }

    /** Asks for the captcha when the next search needs it; a solved one lets the client search for VERIFIED_MINUTES. */
    private function passCaptcha(): void
    {
        if ($this->needsCaptcha()) {
            if (! Captcha::verify((string) $this->captcha)) {
                $message = filled($this->captcha) ? __('The check that you are not a robot failed. Please try again.') : __('Please complete the check that you are not a robot.');
                $this->captcha = null;
                $this->dispatch('captcha-reset');

                throw ValidationException::withMessages(['captcha' => $message]);
            }

            session()->put(self::VERIFIED, now()->addMinutes(self::VERIFIED_MINUTES)->timestamp);
            $this->captcha = null;
        }
    }

    /** Whether Suggest other names shows: on under Settings → AI assistant, after a search. */
    public function canSuggest(): bool
    {
        return Assistant::enabled('domains') && $this->results !== [] && ! $this->needsCaptcha() && ! Assistant::domainsLimitReached();
    }

    /**
     * Suggest other names (Settings → AI assistant → Suggest domain names): the AI assistant's names close to the one
     * searched, with the ending searched (else the first one sold), each checked like a search; only the free ones are
     * listed. Only the name goes to the AI service, at most AI_PER_HOUR times an hour per client or address.
     */
    public function suggest(): void
    {
        if (! $this->canSuggest()) {
            return;
        }

        if (! RateLimiter::attempt('domain-ai:'.DomainSearch::limiterKey(), self::AI_PER_HOUR, fn () => true, 3600)) {
            Notification::make()->title(__('You have asked for suggestions often. Try again in an hour.'))->warning()->send();

            return;
        }

        $first = $this->results[0]['domain'];
        $name = explode('.', $first)[0];
        $ending = mb_substr($first, mb_strlen($name));

        try {
            $names = app(Assistant::class)->domainNames($name, app()->getLocale());
        } catch (AiException $e) {
            report($e);
            Notification::make()->title(__('No suggestions right now. Try again later.'))->warning()->send();

            return;
        }

        $this->suggestions = collect($names)
            ->map(fn (string $label) => $label.$ending)
            ->reject(fn (string $domain) => DomainName::problem($domain) !== null || collect($this->results)->contains('domain', $domain))
            ->map(fn (string $domain) => $this->row($domain))
            ->filter(fn (array $row) => DomainAvailability::from($row['status'])->orderable())
            ->values()
            ->all();

        if ($this->suggestions === []) {
            Notification::make()->title(__('The names suggested are taken too. Try another search.'))->warning()->send();
        }
    }

    /** @return array{domain: string, status: string, price: ?string, was: ?string, term: ?string, transfer: bool, with_ssl: ?string} */
    private function row(string $domain): array
    {
        $currency = Currencies::forClient(filament()->auth()->user()?->client);
        $ssl = DomainSsl::firstPayment($currency);
        $tld = Tld::match($domain);
        $years = $tld?->terms('register')[0] ?? null;
        $price = $years !== null ? $tld->orderPriceCents('register', $years, $currency) : null;
        $sale = $years !== null ? $tld->salePriceCents('register', $years, $currency) : null;

        return [
            'domain' => $domain,
            'status' => app(DomainSearch::class)->check($domain)->value,
            'price' => $price !== null ? Money::format($price, $currency) : null,
            // On sale: the normal price, struck through before the sale one
            'was' => $sale !== null ? Money::format((int) $tld->priceCents('register', $years, $currency), $currency) : null,
            'term' => $years !== null ? Tld::termLabel($years) : null,
            'transfer' => ($tld?->terms('transfer') ?? []) !== [],
            // Settings → Domains → SSL with domains: the domain and its certificate, first payment together
            'with_ssl' => $price !== null && $ssl !== null ? Money::format($price + $ssl, $currency) : null,
        ];
    }

    /** Whether the next search asks for the captcha: it is on for the domain search and none was solved lately. */
    public function needsCaptcha(): bool
    {
        // Visitors who may describe their business always solve it first
        return (Captcha::on('domain_search') || (self::visitor() && $this->canDescribe()))
            && (int) session(self::VERIFIED, 0) <= now()->timestamp;
    }

    /** @return array<string, mixed>|null the captcha plugin's widget, while the next search needs it */
    public function captchaWidget(): ?array
    {
        return $this->needsCaptcha() ? Captcha::active()?->captchaWidget() : null;
    }

    /** Puts a free domain from the results in the cart, to register for its shortest term, with the SSL certificate offered with domains when $ssl. */
    public function add(string $domain, bool $ssl = false): void
    {
        $result = collect([...$this->results, ...$this->suggestions, ...$this->ideas])->firstWhere('domain', $domain);

        if ($result === null || ! $this->canOrder() || ! DomainAvailability::from($result['status'])->orderable()) {
            return;
        }

        try {
            app(ClientOrder::class)->addToCart([
                'product' => ClientOrder::DOMAIN_ONLY,
                'domain_option' => ClientOrder::REGISTER,
                'domain' => $domain,
                'years' => Tld::match($domain)?->terms('register')[0] ?? 1,
                ...($ssl && DomainSsl::product() !== null ? ['ssl' => true] : []),
            ], filament()->auth()->user()?->client);
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

            return;
        }

        Notification::make()->title(__(':domain is in your cart', ['domain' => $domain]))->success()->send();
        $this->dispatch('cart-updated');
    }

    /** @return list<string> the domains already in the cart */
    public function inCart(): array
    {
        return collect(ClientOrder::cart())->map(fn (array $line) => DomainName::normalize($line['domain'] ?? null))->filter()->values()->all();
    }

    public function transferUrl(string $domain): string
    {
        $query = ['product' => ClientOrder::DOMAIN_ONLY, 'domain_option' => ClientOrder::TRANSFER, 'domain' => $domain];

        return self::visitor() ? route('filament.client.cart', $query) : Order::getUrl($query);
    }

    public function cartUrl(): string
    {
        return self::visitor() ? route('filament.client.cart') : Cart::getUrl();
    }
}
