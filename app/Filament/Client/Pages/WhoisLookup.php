<?php

namespace App\Filament\Client\Pages;

use App\Captcha\Captcha;
use App\Filament\Client\Concerns\OpenToVisitors;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Support\DomainSearch;
use App\Support\PublicArea;
use App\Support\Whois;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * WHOIS lookup (Settings → Public area → WHOIS lookup): visitors and clients type a domain name and see whether it is
 * registered, its registrar, dates, statuses and nameservers (App\Support\Whois). The captcha is the domain search's:
 * one solved for either lets the other through for SearchDomains::VERIFIED_MINUTES. RateLimiter caps lookups.
 */
class WhoisLookup extends Page
{
    use OpenToVisitors;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $title = 'WHOIS lookup';

    protected static ?string $navigationLabel = 'WHOIS';

    protected static ?string $slug = 'whois';

    protected static ?int $navigationSort = 3; // after Domain search

    protected string $view = 'filament.client.whois';

    /** The session key the domain search keeps a solved captcha under, shared with this page. */
    private const VERIFIED = 'domain_search.verified_until';

    public ?string $captcha = null;

    #[Url(as: 'domain', except: '')]
    public string $query = '';

    /** @var array<string, mixed>|null what App\Support\Whois found */
    public ?array $result = null;

    /** Whether the last lookup could not be answered by the registry. */
    public bool $failed = false;

    public static function canAccess(): bool
    {
        return PublicArea::whois();
    }

    public function mount(): void
    {
        if (filled($this->query) && ! $this->needsCaptcha()) {
            $this->lookup();
        }
    }

    public function lookup(): void
    {
        $this->resetErrorBag();
        $this->result = null;
        $this->failed = false;

        if (blank(trim($this->query))) {
            return;
        }

        $domain = Whois::domain($this->query);

        if ($domain === null) {
            throw ValidationException::withMessages(['query' => __('Enter a domain name like example.com.')]);
        }

        if ($this->needsCaptcha()) {
            if (! Captcha::verify((string) $this->captcha)) {
                $message = filled($this->captcha) ? __('The check that you are not a robot failed. Please try again.') : __('Please complete the check that you are not a robot.');
                $this->captcha = null;
                $this->dispatch('captcha-reset');

                throw ValidationException::withMessages(['captcha' => $message]);
            }

            session()->put(self::VERIFIED, now()->addMinutes(SearchDomains::VERIFIED_MINUTES)->timestamp);
            $this->captcha = null;
        }

        if (! RateLimiter::attempt('whois:'.DomainSearch::limiterKey(), Whois::PER_MINUTE, fn () => true)) {
            throw ValidationException::withMessages(['query' => __('Too many lookups. Try again in a minute.')]);
        }

        $this->query = $domain;
        $this->result = app(Whois::class)->lookup($domain);
        $this->failed = $this->result === null;
    }

    /** Whether the next lookup asks for the captcha: it is on for the domain search and none was solved lately. */
    public function needsCaptcha(): bool
    {
        return Captcha::on('domain_search') && (int) session(self::VERIFIED, 0) <= now()->timestamp;
    }

    /** @return array<string, mixed>|null the captcha plugin's widget, while the next lookup needs it */
    public function captchaWidget(): ?array
    {
        return $this->needsCaptcha() ? Captcha::active()?->captchaWidget() : null;
    }

    /** The domain search for a name that is free, while there is one for this visitor or client. */
    public function searchUrl(string $domain): ?string
    {
        return SearchDomains::canAccess() ? SearchDomains::getUrl(['q' => $domain]) : null;
    }
}
