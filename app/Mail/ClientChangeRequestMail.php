<?php

namespace App\Mail;

use App\Actions\ClientChangeRequests;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\ClientChangeRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Intl\Countries;

/** To the admins (Receive system emails; Settings → Automation → Change request email): a client asked to change their details. */
class ClientChangeRequestMail extends Mailable
{
    public function __construct(public ClientChangeRequest $request) {}

    public function envelope(): Envelope
    {
        $name = config('gaiacp.company.name') ?: config('app.name');

        return new Envelope(subject: "[{$name}] ".__(':client asked to change their details', ['client' => $this->request->client->name]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.client-change-request', with: [
            'request' => $this->request,
            'rows' => self::rows($this->request),
            'url' => ClientResource::getUrl('edit', ['record' => $this->request->client_id], panel: 'admin'),
        ]);
    }

    /**
     * The changes for display: label, value when asked, value asked for.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function rows(ClientChangeRequest $request): array
    {
        $show = fn (string $field, ?string $value) => match (true) {
            $value === null => '–',
            $field === 'country' => Countries::exists($value) ? Countries::getName($value, app()->getLocale()) : $value,
            default => $value,
        };

        return collect($request->fields)
            ->map(fn (array $change, string $field) => [ClientChangeRequests::label($field), $show($field, $change[0]), $show($field, $change[1])])
            ->values()
            ->all();
    }
}
