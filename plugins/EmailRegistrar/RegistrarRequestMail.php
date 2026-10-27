<?php

namespace Plugins\EmailRegistrar;

use App\Filament\Resources\Services\ServiceResource;
use App\Models\Client;
use App\Models\Service;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the admins: a registrar request the Email Notifications registrar can't do itself (EmailRegistrar). */
class RegistrarRequestMail extends Mailable
{
    public const REGISTER = 'register';

    public const TRANSFER = 'transfer';

    public const RENEW = 'renew';

    public const NAMESERVERS = 'nameservers';

    public const EPP = 'epp';

    /** @param  array{years?: int, registrant?: Client, nameservers?: list<string>}  $details */
    public function __construct(public string $request, public string $domain, public ?Service $service, public array $details = []) {}

    public function title(): string
    {
        $years = $this->details['years'] ?? 1;

        return match ($this->request) {
            self::REGISTER => __('Register :domain for :years year(s)', ['domain' => $this->domain, 'years' => $years]),
            self::TRANSFER => __('Transfer :domain in', ['domain' => $this->domain]),
            self::RENEW => __('Renew :domain for :years year(s)', ['domain' => $this->domain, 'years' => $years]),
            self::NAMESERVERS => __('Change the nameservers of :domain', ['domain' => $this->domain]),
            default => __('Send the EPP code of :domain to the client', ['domain' => $this->domain]),
        };
    }

    public function envelope(): Envelope
    {
        $name = config('gaiacp.company.name') ?: config('app.name');

        return new Envelope(subject: "[{$name}] ".$this->title());
    }

    public function content(): Content
    {
        $registrant = $this->details['registrant'] ?? null;

        return new Content(markdown: 'email_registrar::request', with: [
            'title' => $this->title(),
            'client' => $this->service?->client,
            'registrant' => $registrant,
            'address' => $registrant ? collect([$registrant->address1, $registrant->address2, trim("{$registrant->postcode} {$registrant->city}"), $registrant->state, $registrant->country])->filter(fn ($line) => filled($line))->join(', ') : null,
            'nameservers' => $this->details['nameservers'] ?? [],
            'url' => $this->service ? ServiceResource::getUrl('edit', ['record' => $this->service], panel: 'admin') : null,
        ]);
    }
}
