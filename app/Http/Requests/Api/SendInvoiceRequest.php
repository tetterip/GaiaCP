<?php

namespace App\Http\Requests\Api;

use App\Enums\MailTemplate;
use Illuminate\Validation\Rule;

/** POST /invoices/{id}/send: the email to send, as on the notice's Send email. */
class SendInvoiceRequest extends ApiRequest
{
    /** The templates a payment notice can be sent with. */
    public const TEMPLATES = [
        MailTemplate::InvoiceCreated, MailTemplate::InvoicePaymentReminder, MailTemplate::InvoiceModified,
        MailTemplate::InvoiceFirstOverdue, MailTemplate::InvoiceSecondOverdue,
    ];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'template' => ['sometimes', 'nullable', Rule::in(array_map(fn (MailTemplate $template) => $template->value, self::TEMPLATES))],
        ];
    }
}
