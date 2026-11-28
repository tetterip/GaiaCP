<?php

namespace App\Actions;

use App\Enums\AdminArea;
use App\Enums\DomainRegistrationType;
use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Mail\ClientMailer;
use App\Mail\EppCodeRequestMail;
use App\Models\ActivityLog;
use App\Models\Registrar;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\DomainContact;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\EmailsEppCode;
use App\Provisioning\GivesNoEppCode;
use App\Provisioning\ManagesChildNameservers;
use App\Provisioning\ManagesDomainContacts;
use App\Provisioning\ManagesRegistrarLock;
use App\Provisioning\ProvisioningException;
use App\Provisioning\RegistrarFactory;
use App\Support\DomainName;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

/**
 * Registrar actions on domain services: the only code that calls a registrar.
 * Every outcome, including failures, lands in the activity log; a failed call
 * leaves the service unchanged and throws ProvisioningException.
 */
class DomainLifecycle
{
    public function __construct(private RegistrarFactory $registrars, private ClientMailer $mailer) {}

    /**
     * Make a pending domain active the way its registration type says: register it,
     * request the transfer in, or (registered elsewhere) only mark it active.
     *
     * Safe to re-run: a domain the registrar account already holds is not registered
     * or transferred again, so a retry after a timeout only finishes the bookkeeping.
     */
    public function activate(Service $service): void
    {
        $this->assertDomain($service);

        if ($service->status !== ServiceStatus::Pending) {
            throw new ProvisioningException('Only pending domains can be registered or transferred.');
        }

        $details = $service->registration();
        $type = $details->registration_type;

        if ($type === DomainRegistrationType::External) {
            $service->update(['status' => ServiceStatus::Active]);
            ActivityLog::record('domain.activated', "{$service->domain} marked active (registered elsewhere)", $service);

            return;
        }

        $event = $type === DomainRegistrationType::Register ? 'domain.registered' : 'domain.transfer_requested';
        $registrar = $this->account($service, $event);
        $years = $service->registrationYears();

        // The domain's own nameservers, else the defaults from Settings → Domains.
        $wanted = $type === DomainRegistrationType::Register ? fn () => $this->nameserversFor($details->nameservers) : null;
        $nameserversNotSet = null;

        [$expires, $nameservers] = $this->call($service, $registrar, $event, function (DomainRegistrar $api) use ($service, $details, $type, $years, $wanted, &$nameserversNotSet) {
            // Any reply means the account holds it, even one without a real date yet (knownExpiry()): never register twice.
            if ($this->expiryIn($api, $this->name($service)) === null) {
                if ($type === DomainRegistrationType::Register) {
                    $api->register($this->name($service), $years, $service->client, $wanted());
                } else {
                    $api->transfer($this->name($service), (string) $details->epp_code ?: throw new ProvisioningException("Enter the EPP code of {$service->domain} to transfer it."), $service->client);
                }
            }

            // A transfer takes days, so its expiry and nameservers are read by a later sync. A registration's are read
            // now, so they show at once; a failed read leaves them to the daily sync and never fails the registration.
            if ($type !== DomainRegistrationType::Register || ($expires = $this->expiryIn($api, $this->name($service))) === null) {
                return [null, []];
            }

            $nameservers = rescue(fn () => $api->nameservers($this->name($service)), [], report: false);

            // A registrar can keep its own placeholders instead of the nameservers sent (easy.gr's nodns1.easy.gr on a
            // new .gr), and a retry finds a domain registered without them: set them once more. A refusal never fails
            // the registration; it is logged, so the admin sets them from the Registrar menu.
            $want = rescue($wanted, [], report: false);

            if ($nameservers !== [] && $want !== [] && ! self::sameNameservers($nameservers, $want)) {
                try {
                    $api->setNameservers($this->name($service), $want);
                    $nameservers = $want;
                } catch (ProvisioningException $e) {
                    $nameserversNotSet = $e->getMessage();
                }
            }

            // A registration the registry has not confirmed yet can come back without a date (easy.gr's .gr gives
            // 0000-00-00): the domain is in the account, its expiry is left to the daily sync.
            return [self::knownExpiry($expires), $nameservers];
        });

        if ($nameserversNotSet !== null) {
            ActivityLog::record('domain.nameservers_not_set', "Nameservers of {$service->domain} not set after registering, so it keeps ".implode(', ', $nameservers).": {$nameserversNotSet}", $service, properties: ['registrar_id' => $registrar->id]);
        }

        $details->update([
            'registrar_id' => $registrar->id,
            'registered_at' => $details->registered_at ?? today(),
            'expires_at' => $expires ?? $details->expires_at,
            'nameservers' => $nameservers ?: $details->nameservers,
            'last_synced_at' => $expires ? now() : $details->last_synced_at,
        ]);
        $service->update(['status' => ServiceStatus::Active]);

        ActivityLog::record($event, $type === DomainRegistrationType::Register
            ? "{$service->domain} registered for {$years} ".Str::plural('year', $years)." at {$registrar->name}"
            : "Transfer of {$service->domain} to {$registrar->name} requested", $service, properties: ['registrar_id' => $registrar->id]);

        if ($type === DomainRegistrationType::Register) {
            $this->mailer->domain(MailTemplate::DomainRegistration, $service->load('domainDetails'), $years);
        }
    }

    /**
     * Renew at the domain's registrar, for one billing cycle by default.
     *
     * Not idempotent at the registrar, so it is only called once per recorded payment.
     */
    public function renew(Service $service, ?int $years = null): void
    {
        $this->assertDomain($service);

        $years ??= $service->registrationYears();
        $registrar = $this->account($service, 'domain.renewed');

        $this->call($service, $registrar, 'domain.renewed', fn (DomainRegistrar $api) => $api->renew($this->name($service), $years));

        $details = $service->registration();

        if ($details->expires_at) {
            $details->update(['expires_at' => $details->expires_at->addYears($years)]);
        }

        ActivityLog::record('domain.renewed', "{$service->domain} renewed for {$years} ".Str::plural('year', $years)." at {$registrar->name}", $service, properties: ['registrar_id' => $registrar->id, 'years' => $years]);
        $this->mailer->domain(MailTemplate::DomainRenewal, $service->load('domainDetails'), $years);
    }

    /** Whether the domain's registrar emails the EPP code to the registrant instead of handing it back (EmailsEppCode). */
    public function emailsEppCode(Service $service): bool
    {
        $account = $service->registration()->registrarAccount();

        return $account !== null && ! $account->isManual()
            && rescue(fn () => $this->registrars->forRegistrar($account) instanceof EmailsEppCode, false, report: false);
    }

    /** Whether the domain's registrar can give its EPP code at all (not one that is GivesNoEppCode, such as Namecheap). */
    public function givesEppCode(Service $service): bool
    {
        $account = $service->registration()->registrarAccount();

        return $account === null || $account->isManual()
            || ! rescue(fn () => $this->registrars->forRegistrar($account) instanceof GivesNoEppCode, false, report: false);
    }

    /**
     * Whether the domain's registrar can be read (sync, EPP code): not for a domain registered elsewhere, nor at a
     * registrar that books nothing (Email Notifications).
     */
    public function readsRegistrar(Service $service): bool
    {
        return $service->type === ServiceType::Domain
            && $service->registration()->registration_type !== DomainRegistrationType::External
            && ! $service->registration()->registrarAccount()?->isManual();
    }

    /** Read the expiry date and nameservers from the registrar. Changes nothing there, and never the due date. */
    public function sync(Service $service): void
    {
        $this->assertDomain($service);

        $registrar = $this->account($service, 'domain.synced');

        if ($registrar->isManual()) {
            throw new ProvisioningException("{$registrar->name} keeps no record of domains, so there is nothing to sync. Enter the expiry date on the domain.");
        }

        [$expires, $nameservers] = $this->call($service, $registrar, 'domain.synced', fn (DomainRegistrar $api) => [
            self::knownExpiry($api->expiryDate($this->name($service))) ?? throw new ProvisioningException(self::noExpiryYet($service, $registrar)),
            $api->nameservers($this->name($service)),
        ]);

        $details = $service->registration();
        // A transfer in has no expiry until the registrar account holds the domain: the first sync that reads one sees it completed.
        $transferCompleted = $details->registration_type === DomainRegistrationType::Transfer && $details->expires_at === null;

        // What the sync changed, for the admins' daily summary: [before, after] per field.
        $changes = array_filter([
            'expires_at' => $details->expires_at?->toDateString() !== $expires->toDateString()
                ? [$details->expires_at?->toDateString(), $expires->toDateString()] : null,
            'nameservers' => array_map('strtolower', array_values($details->nameservers ?? [])) !== array_map('strtolower', array_values($nameservers))
                ? [array_values($details->nameservers ?? []), array_values($nameservers)] : null,
        ]);

        $details->update(['expires_at' => $expires, 'nameservers' => $nameservers, 'last_synced_at' => now()]);

        ActivityLog::record('domain.synced', "{$service->domain} expires on {$expires->toDateString()}", $service, properties: array_filter([
            'expires_at' => $expires->toDateString(),
            'changes' => $changes,
        ]));

        if ($transferCompleted) {
            ActivityLog::record('domain.transfer_completed', "Transfer of {$service->domain} completed", $service);
            $this->mailer->domain(MailTemplate::DomainTransferCompleted, $service->load('domainDetails'));
        }
    }

    /** @param  list<string>  $nameservers */
    public function setNameservers(Service $service, array $nameservers): void
    {
        $this->assertDomain($service);

        $nameservers = $this->nameserversFor($nameservers);
        $registrar = $this->account($service, 'domain.nameservers_changed');

        $this->call($service, $registrar, 'domain.nameservers_changed', fn (DomainRegistrar $api) => $api->setNameservers($this->name($service), $nameservers));

        $service->registration()->update(['nameservers' => $nameservers]);
        ActivityLog::record('domain.nameservers_changed', "Nameservers of {$service->domain}: ".implode(', ', $nameservers), $service);
    }

    /**
     * A client asks for the EPP code of a domain at a registrar whose API gives none (GivesNoEppCode): the admins who
     * may open Services get the bell and, with Receive system emails on, an email; one of them then reads the code at
     * the registrar and sends it with sendEppCode(). Once an hour per domain, so pressing again can't flood the admins.
     */
    public function requestEppCode(Service $service): void
    {
        $this->assertDomain($service);

        if (! Cache::add("domain-epp-request:{$service->id}", true, now()->addHour())) {
            return;
        }

        $registrar = $service->registration()->registrarAccount()->name ?? '';

        ActivityLog::record('domain.epp_code_requested', "EPP code of {$service->domain} requested by the client; {$registrar} gives none through its API", $service);

        $admins = User::query()->admins()->where('active', true)->where('system_emails', true)->get()
            ->filter(fn (User $admin) => $admin->canUseArea(AdminArea::Services));

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->locale(Locales::normalize($admin->locale))->send(new EppCodeRequestMail($service));
            } catch (Throwable $e) {
                Log::error('EPP code request email failed', ['user_id' => $admin->id, 'error' => $e->getMessage()]);
                ActivityLog::record('email.failed', "EPP code request email to {$admin->email} failed: {$e->getMessage()}", $service);
            }
        }
    }

    /**
     * Emails the client the EPP code an admin read at the registrar (Domain EPP Code template, sent even while that
     * template is off, since the admin asked for it). Logged without the code. False when the email could not go.
     */
    public function sendEppCode(Service $service, string $code): bool
    {
        $this->assertDomain($service);
        // Each character as an entity, so Markdown in the template never reads a * or _ of the code.
        $value = new HtmlString(implode('', array_map(fn (string $c) => '&#'.mb_ord($c).';', mb_str_split(trim($code)))));

        $sent = $this->mailer->send(MailTemplate::DomainEppCode, $service->client, fn () => ['domain' => (string) $service->domain, 'epp_code' => $value], $service, evenIfOff: true);

        if ($sent) {
            ActivityLog::record('domain.epp_code_sent', "EPP code of {$service->domain} emailed to the client", $service);
        }

        return $sent;
    }

    /**
     * The transfer-out code. Logged without the code itself. Null at a registrar that books nothing (Email
     * Notifications): the admins were asked for it and send it by hand; and at one that emails it to the registrant
     * (EmailsEppCode, e.g. Enom).
     */
    public function eppCode(Service $service): ?string
    {
        $this->assertDomain($service);

        $registrar = $this->account($service, 'domain.epp_requested');
        $emailed = false;
        $code = $this->call($service, $registrar, 'domain.epp_requested', function (DomainRegistrar $api) use ($service, &$emailed) {
            $emailed = $api instanceof EmailsEppCode;

            return $api->eppCode($this->name($service));
        });

        if ($registrar->isManual()) {
            ActivityLog::record('domain.epp_requested', "EPP code of {$service->domain} requested from the admins by email ({$registrar->name})", $service);

            return null;
        }

        if ($emailed) {
            ActivityLog::record('domain.epp_requested', "EPP code of {$service->domain} emailed to the registrant by {$registrar->name}", $service);

            return null;
        }

        ActivityLog::record('domain.epp_requested', "EPP code of {$service->domain} requested", $service);

        return $code;
    }

    /**
     * Whether the domain's registrar can do this (ManagesDomainContacts, ManagesChildNameservers, ManagesRegistrarLock):
     * the domain is at a registrar here, not registered elsewhere, and its module implements it.
     *
     * @param  class-string  $capability
     */
    public function supports(Service $service, string $capability): bool
    {
        $account = $service->type === ServiceType::Domain ? $service->registration()->registrarAccount() : null;

        return $account !== null && ! $account->isManual()
            && $service->registration()->registration_type !== DomainRegistrationType::External
            && rescue(fn () => $this->registrars->forRegistrar($account) instanceof $capability, false, report: false);
    }

    /** @return list<string> the contact roles the domain's registrar keeps, without calling it; [] when it can't change them */
    public function contactRoles(Service $service): array
    {
        if (! $this->supports($service, ManagesDomainContacts::class)) {
            return [];
        }

        $api = $this->registrars->forRegistrar($service->registration()->registrarAccount());

        return $api instanceof ManagesDomainContacts ? $api->contactRoles() : [];
    }

    /** @return array<string, DomainContact> the domain's contacts at the registrar, by role (DomainContact::ROLES order) */
    public function contacts(Service $service): array
    {
        $this->assertDomain($service);
        $registrar = $this->account($service, 'domain.contacts_read');

        $contacts = $this->call($service, $registrar, 'domain.contacts_read', fn (DomainRegistrar $api) => $this->capable($api, ManagesDomainContacts::class, $registrar)->contacts($this->name($service)));

        return array_intersect_key(array_merge(DomainContact::ROLES, $contacts), $contacts);
    }

    /** @param  array<string, DomainContact>  $contacts  by role */
    public function saveContacts(Service $service, array $contacts): void
    {
        $this->assertDomain($service);
        $registrar = $this->account($service, 'domain.contacts_changed');

        $this->call($service, $registrar, 'domain.contacts_changed', fn (DomainRegistrar $api) => $this->capable($api, ManagesDomainContacts::class, $registrar)->saveContacts($this->name($service), $contacts));

        ActivityLog::record('domain.contacts_changed', "Contact details of {$service->domain} changed at {$registrar->name}", $service, properties: [
            'roles' => array_keys($contacts),
            'registrant' => isset($contacts['registrant']) ? $contacts['registrant']->name() : null,
        ]);
    }

    /** Register a private nameserver under the domain (e.g. ns1.example.com), or change its IP. */
    public function saveChildNameserver(Service $service, string $host, string $ip): void
    {
        $this->assertDomain($service);
        $host = $this->childHost($service, $host);
        $ip = trim($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new ProvisioningException("{$ip} is not an IP address.");
        }

        $details = $service->registration();
        $existing = collect($details->child_nameservers ?? [])->firstWhere('host', $host);
        $registrar = $this->account($service, 'domain.child_nameserver_saved');

        $this->call($service, $registrar, 'domain.child_nameserver_saved', fn (DomainRegistrar $api) => $this->capable($api, ManagesChildNameservers::class, $registrar)
            ->saveChildNameserver($this->name($service), DomainName::toAscii($host), $ip, $existing['ip'] ?? null));

        $details->update(['child_nameservers' => collect($details->child_nameservers ?? [])
            ->reject(fn (array $ns) => $ns['host'] === $host)
            ->push(['host' => $host, 'ip' => $ip])
            ->sortBy('host', SORT_NATURAL)->values()->all()]);
        ActivityLog::record('domain.child_nameserver_saved', "Private nameserver {$host} → {$ip} saved at {$registrar->name}", $service);
    }

    public function deleteChildNameserver(Service $service, string $host): void
    {
        $this->assertDomain($service);
        $host = $this->childHost($service, $host);
        $registrar = $this->account($service, 'domain.child_nameserver_deleted');

        $this->call($service, $registrar, 'domain.child_nameserver_deleted', fn (DomainRegistrar $api) => $this->capable($api, ManagesChildNameservers::class, $registrar)
            ->deleteChildNameserver($this->name($service), DomainName::toAscii($host)));

        $details = $service->registration();
        $details->update(['child_nameservers' => collect($details->child_nameservers ?? [])->reject(fn (array $ns) => $ns['host'] === $host)->values()->all() ?: null]);
        ActivityLog::record('domain.child_nameserver_deleted', "Private nameserver {$host} deleted at {$registrar->name}", $service);
    }

    public function locked(Service $service): bool
    {
        $this->assertDomain($service);
        $registrar = $this->account($service, 'domain.lock_read');

        return $this->call($service, $registrar, 'domain.lock_read', fn (DomainRegistrar $api) => $this->capable($api, ManagesRegistrarLock::class, $registrar)->locked($this->name($service)));
    }

    public function setLocked(Service $service, bool $locked): void
    {
        $this->assertDomain($service);
        $registrar = $this->account($service, 'domain.lock_changed');

        $this->call($service, $registrar, 'domain.lock_changed', fn (DomainRegistrar $api) => $this->capable($api, ManagesRegistrarLock::class, $registrar)->setLocked($this->name($service), $locked));

        ActivityLog::record('domain.lock_changed', ($locked ? 'Registrar lock on for ' : 'Registrar lock off for ').$service->domain, $service);
    }

    /** "ns1" or "ns1.example.com" → "ns1.example.com", which must be under the domain. */
    private function childHost(Service $service, string $host): string
    {
        $domain = strtolower((string) $service->domain);
        $host = strtolower(rtrim(trim($host), '.'));
        $host = str_ends_with($host, '.'.$domain) ? $host : "{$host}.{$domain}";
        $label = substr($host, 0, -strlen($domain) - 1);

        if ($label === '' || ! DomainName::isNameserver($host)) {
            throw new ProvisioningException("{$host} is not a nameserver name under {$service->domain}.");
        }

        return $host;
    }

    /**
     * @template T
     *
     * @param  class-string<T>  $capability
     * @return T
     */
    private function capable(DomainRegistrar $api, string $capability, Registrar $registrar): object
    {
        if (! $api instanceof $capability) {
            throw new ProvisioningException("{$registrar->name} can't do this from GaiaCP; do it in the registrar's own panel.");
        }

        return $api;
    }

    /**
     * A payment on an active or suspended domain is its renewal, unless the registrar
     * expiry already covers the period paid for (a domain registered or transferred
     * just before its first payment). A pending domain's first payment is not either:
     * registering it covers the first term. Nor is a domain registered elsewhere
     * (tracked only). Only yearly (or 2/3-yearly) cycles, since
     * registrars renew in whole years, and only while Settings → Domains renews on payment.
     */
    public function shouldRenewOnPayment(Service $service): bool
    {
        if (! config('gaiacp.domains.renew_on_payment', true)
            || $service->type !== ServiceType::Domain
            || blank($service->domain)
            || ! in_array($service->status, ServiceStatus::billable(), true)
            || $service->billing_cycle->months() % 12 !== 0
            || $service->domainDetails?->registration_type === DomainRegistrationType::External) {
            return false;
        }

        return $this->endsBefore($service, $service->billing_cycle->advance($service->next_due_date, $service->anchorDay()));
    }

    /** Days a registrar expiry may fall short of the paid-through date and still count as covering it (registrars and billing disagree by a day or so). */
    public const EXPIRY_TOLERANCE_DAYS = 7;

    /**
     * Whether the domain's stored expiry ends before $paidUntil, by more than EXPIRY_TOLERANCE_DAYS. True when no expiry
     * is known, so callers that can read the registrar should sync() first (renewIfNeeded()).
     */
    public function endsBefore(Service $service, CarbonInterface $paidUntil): bool
    {
        $expires = $service->domainDetails?->expires_at;

        return $expires === null || $expires->lessThan(CarbonImmutable::parse($paidUntil->toDateString())->subDays(self::EXPIRY_TOLERANCE_DAYS));
    }

    /**
     * Renews a domain whose payment just moved its due date, reading the registrar first: a renewal is not idempotent,
     * and the stored expiry can be stale (imported from WHMCS, renewed at the registrar by hand, or not synced yet).
     * It renews only if the domain still ends before the paid-through date (its next due date now). When the registrar
     * can't be read, nothing is renewed: the failure is logged as a failed renewal, so it waits in the Module queue.
     * Returns whether it renewed.
     */
    public function renewIfNeeded(Service $service): bool
    {
        $this->assertDomain($service);

        if ($this->readsRegistrar($service)) {
            $registrar = $this->account($service, 'domain.renewed');

            try {
                $expires = self::knownExpiry($this->registrars->forRegistrar($registrar)->expiryDate($this->name($service)))
                    ?? throw new ProvisioningException(self::noExpiryYet($service, $registrar));
                $service->registration()->update(['expires_at' => $expires, 'last_synced_at' => now()]);
            } catch (ProvisioningException $e) {
                $message = "Could not read the expiry date at the registrar, so it was not renewed: {$e->getMessage()}";
                ActivityLog::record('provisioning.failed', "domain.renewed failed: {$message}", $service, properties: ['event' => 'domain.renewed', 'registrar_id' => $registrar->id]);

                throw new ProvisioningException($message, previous: $e);
            }

            $service->unsetRelation('domainDetails');
        }

        if (! $this->endsBefore($service, $service->next_due_date)) {
            $expires = $service->domainDetails?->expires_at?->toDateString();
            ActivityLog::record('domain.renew_skipped', "{$service->domain} not renewed: it already expires on {$expires}, which covers the paid period to {$service->next_due_date->toDateString()}", $service, properties: ['expires_at' => $expires]);

            return false;
        }

        $this->renew($service);

        return true;
    }

    /** The name the registrar gets: punycode for an IDN. */
    private function name(Service $service): string
    {
        return DomainName::toAscii((string) $service->domain);
    }

    private function assertDomain(Service $service): void
    {
        if ($service->type !== ServiceType::Domain || blank($service->domain)) {
            throw new ProvisioningException('This is not a domain service.');
        }
    }

    /** The chosen registrar account, else the TLD's preferred one; failures are logged. */
    private function account(Service $service, string $event): Registrar
    {
        $registrar = $service->registration()->registrarAccount();

        if ($registrar === null) {
            $message = "No active registrar handles the TLD of {$service->domain}. Pick its registrar on the domain or under Settings → Domain pricing.";
            ActivityLog::record('provisioning.failed', "{$event} failed: {$message}", $service, properties: ['event' => $event]);

            throw new ProvisioningException($message);
        }

        return $registrar;
    }

    /**
     * @template T
     *
     * @param  Closure(DomainRegistrar): T  $call
     * @return T
     */
    private function call(Service $service, Registrar $registrar, string $event, Closure $call): mixed
    {
        try {
            return $call($this->registrars->forRegistrar($registrar));
        } catch (ProvisioningException $e) {
            ActivityLog::record('provisioning.failed', "{$event} failed: {$e->getMessage()}", $service, properties: ['event' => $event, 'registrar_id' => $registrar->id]);

            throw $e;
        }
    }

    /**
     * The expiry a registrar gave, or null when it is not a real date: a registration the registry has not confirmed
     * yet can come back as 0000-00-00 (Carbon reads it as -0001-11-30, which MySQL refuses) or as the Unix epoch.
     */
    public static function knownExpiry(?CarbonInterface $expires): ?CarbonImmutable
    {
        return $expires !== null && $expires->year >= 2000 ? CarbonImmutable::instance($expires) : null;
    }

    private static function noExpiryYet(Service $service, Registrar $registrar): string
    {
        return "{$registrar->name} has no expiry date for {$service->domain} yet (the registry may not have confirmed the registration). Try again later.";
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function sameNameservers(array $a, array $b): bool
    {
        $normal = fn (array $list) => collect($list)->map(fn (string $ns) => rtrim(mb_strtolower(trim($ns)), '.'))->sort()->values()->all();

        return $normal($a) === $normal($b);
    }

    /** The domain's expiry when the registrar account holds it, else null. */
    private function expiryIn(DomainRegistrar $api, string $domain): ?CarbonImmutable
    {
        try {
            return $api->expiryDate($domain);
        } catch (ProvisioningException) {
            return null;
        }
    }

    /**
     * @param  list<string>|null  $nameservers
     * @return list<string> the given ones, else the default nameservers from Settings
     */
    private function nameserversFor(?array $nameservers): array
    {
        $result = DomainName::nameservers($nameservers) ?: DomainName::nameservers((array) config('gaiacp.domains.nameservers'));

        if (($bad = array_filter($result, fn (string $ns) => ! DomainName::isNameserver($ns))) !== []) {
            throw new ProvisioningException('Not a nameserver host name: '.implode(', ', $bad).'. Enter names like ns1.example.com.');
        }

        if (count($result) > 4) {
            throw new ProvisioningException('A domain takes at most 4 nameservers.');
        }

        if (count($result) < 2) {
            throw new ProvisioningException('A domain needs at least two nameservers. Enter them on the domain or set the defaults under Settings → Domains.');
        }

        return $result;
    }
}
