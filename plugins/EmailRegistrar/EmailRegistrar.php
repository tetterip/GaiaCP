<?php

namespace Plugins\EmailRegistrar;

use App\Enums\AdminArea;
use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\ProvisioningException;
use App\Support\DomainName;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email Notifications: each request is an email (RegistrarRequestMail) to the active admins with Receive system
 * emails on who open services. Nothing is booked anywhere and nothing can be read back, so expiryDate() and
 * nameservers() throw (DomainLifecycle never syncs these domains). A request no admin was emailed fails, so the
 * domain is not marked done.
 */
class EmailRegistrar implements DomainRegistrar
{
    public function name(): string
    {
        return 'Email Notifications';
    }

    public function configured(): bool
    {
        return true;
    }

    public function expiryDate(string $domain): CarbonImmutable
    {
        throw new ProvisioningException('Email Notifications keeps no record of domains: enter the expiry date on the domain.');
    }

    public function register(string $domain, int $years, Client $registrant, array $nameservers): void
    {
        $this->send(RegistrarRequestMail::REGISTER, $domain, ['years' => $years, 'registrant' => $registrant, 'nameservers' => $nameservers]);
    }

    public function transfer(string $domain, string $eppCode, Client $registrant): void
    {
        // The code itself stays on the domain's page, not in an email.
        $this->send(RegistrarRequestMail::TRANSFER, $domain, ['registrant' => $registrant]);
    }

    public function renew(string $domain, int $years): void
    {
        $this->send(RegistrarRequestMail::RENEW, $domain, ['years' => $years]);
    }

    public function nameservers(string $domain): array
    {
        throw new ProvisioningException('Email Notifications keeps no record of domains: the nameservers are the ones entered on the domain.');
    }

    public function setNameservers(string $domain, array $nameservers): void
    {
        $this->send(RegistrarRequestMail::NAMESERVERS, $domain, ['nameservers' => $nameservers]);
    }

    /** The admins are asked for the code; it reaches the client by hand, so there is none to return. */
    public function eppCode(string $domain): string
    {
        $this->send(RegistrarRequestMail::EPP, $domain);

        return '';
    }

    /** @param  array{years?: int, registrant?: Client, nameservers?: list<string>}  $details */
    private function send(string $request, string $domain, array $details = []): void
    {
        $name = DomainName::normalize($domain);
        $service = Service::query()->where('type', ServiceType::Domain)->where('domain', $name)->with('client')->latest('id')->first();
        $sent = 0;

        $admins = User::query()->admins()->where('active', true)->where('system_emails', true)->get()
            ->filter(fn (User $admin) => $admin->canUseArea(AdminArea::Services));

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->locale(Locales::normalize($admin->locale))->send(new RegistrarRequestMail($request, $name, $service, $details));
                $sent++;
            } catch (Throwable $e) {
                Log::error('Email Notifications registrar email failed', ['user_id' => $admin->id, 'error' => $e->getMessage()]);
                ActivityLog::record('email.failed', "Registrar request email ({$request} {$name}) to {$admin->email} failed: {$e->getMessage()}", $service);
            }
        }

        if ($sent === 0) {
            throw new ProvisioningException('Email Notifications emailed no admin: turn on "Receive system emails" for an admin who opens services (Settings → Admin users), and check Settings → Mail.');
        }
    }
}
