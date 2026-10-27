<?php

namespace App\Console\Commands;

use App\Models\Registrar;
use App\Provisioning\ProvisioningException;
use App\Provisioning\RegistrarFactory;
use App\Support\DomainName;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:registrar-check {domain : A domain in the registrar account} {--registrar= : Registrar id (default: the preferred one of the TLD)}')]
#[Description('Read-only check of a registrar login: shows a domain\'s expiry date and nameservers')]
class RegistrarCheck extends Command
{
    public function handle(RegistrarFactory $registrars): int
    {
        $domain = DomainName::normalize($this->argument('domain'));
        $account = $this->option('registrar') ? Registrar::find($this->option('registrar')) : Registrar::forDomain($domain);

        if (! $account) {
            $this->error('No such registrar, or the TLD has no active preferred registrar (Settings → Domain pricing).');

            return self::FAILURE;
        }

        try {
            $registrar = $registrars->forRegistrar($account);
            $expiry = $registrar->expiryDate(DomainName::toAscii($domain));
            $nameservers = $registrar->nameservers(DomainName::toAscii($domain));
        } catch (ProvisioningException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$domain} at {$account->name}: expires {$expiry->toDateString()}");
        $this->line('Nameservers: '.($nameservers ? implode(', ', $nameservers) : '(none returned)'));

        return self::SUCCESS;
    }
}
