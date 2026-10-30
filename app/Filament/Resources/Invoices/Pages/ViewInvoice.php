<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Actions\RecordInvoicePayment;
use App\Ai\AiException;
use App\Ai\Assistant;
use App\Ai\ClientFacts;
use App\Credit\ApplyCredit;
use App\Enums\InvoiceStatus;
use App\Enums\MailTemplate;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Invoices\SendInvoiceEmails;
use App\Invoicing\IssueInvoice;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Payments\PaymentMethods;
use App\Support\MailTemplates;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use RuntimeException;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    public function getTitle(): string
    {
        return __('Payment notice :number', ['number' => $this->getRecord()->number]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->payWithCredit(),
            $this->recordPayment(),
            $this->sendEmail(),
            $this->aiReminder(),
            $this->updateBillTo(),
            $this->issueInvoice(),
            $this->cancel(),
        ];
    }

    /** A paid notice without its invoice (paid before invoices were on, or issuing failed): issue it now. */
    private function issueInvoice(): Action
    {
        return Action::make('issueInvoice')
            ->label('Issue invoice')
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->color('gray')
            ->visible(fn (Invoice $record) => IssueInvoice::enabled() && IssueInvoice::invoiceable($record) && $record->taxInvoice === null)
            ->requiresConfirmation()
            ->modalDescription(__('Issues the invoice of this payment notice with the next number, dated today. An invoice can\'t be changed or deleted afterwards.'))
            ->action(function (Invoice $record) {
                $invoice = app(IssueInvoice::class)->handle($record);

                Notification::make()->title(__('Invoice :number issued', ['number' => $invoice?->number]))->success()->send();
                $this->redirect(TaxInvoiceResource::getUrl('view', ['record' => $invoice]));
            });
    }

    private function recordPayment(): Action
    {
        return Action::make('recordInvoicePayment')
            ->label('Record payment')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Unpaid)
            ->modalDescription(function (Invoice $record) {
                $unpaid = app(RecordInvoicePayment::class)->unpaid($record);

                if ($record->credit) {
                    return __('Adds :total to the client\'s credit.', ['total' => Money::format($unpaid->sum('total_cents'), $record->currency)]);
                }

                return __('Records one payment per unpaid line, :total in total. Each service\'s due date moves forward one cycle; domains are renewed at the registrar.', [
                    'total' => Money::format($unpaid->sum('total_cents'), $record->currency),
                ]);
            })
            ->schema([
                DatePicker::make('paid_on')
                    ->required()
                    ->default(now())
                    ->maxDate(now()),
                Select::make('method')
                    ->options(fn () => PaymentMethods::options())
                    ->default(fn (Invoice $record) => array_key_exists((string) $record->client->default_payment_method, PaymentMethods::options()) ? $record->client->default_payment_method : PaymentMethods::default())
                    ->required(),
                TextInput::make('reference')
                    ->maxLength(255)
                    ->helperText(__('Bank transaction reference, receipt number…')),
            ])
            ->action(function (Invoice $record, array $data) {
                $recorder = app(RecordInvoicePayment::class);

                try {
                    $recorder->handle(
                        $record,
                        CarbonImmutable::parse($data['paid_on']),
                        $data['method'],
                        $data['reference'] ?? null,
                    );
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Not recorded'))->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title(__('Payment recorded'))->success()->send();

                foreach ($recorder->renewalErrors as $line => $error) {
                    Notification::make()
                        ->title(__('The payment is recorded, but :line was not renewed at the registrar', ['line' => $line]))
                        ->body($error.' '.__('Renew it at the registrar by hand.'))
                        ->danger()
                        ->persistent()
                        ->send();
                }

                $this->refreshFormData(['status', 'paid_on']);
            });
    }

    /** Pays what the client's credit covers, whole lines at a time (App\Credit\ApplyCredit). */
    private function payWithCredit(): Action
    {
        return Action::make('payWithCredit')
            ->label('Pay with credit')
            ->icon(Heroicon::OutlinedWallet)
            ->color('success')
            ->visible(fn (Invoice $record) => app(ApplyCredit::class)->coverable($record) > 0)
            ->requiresConfirmation()
            ->modalDescription(fn (Invoice $record) => __('Pays :amount of this notice from the client\'s credit of :balance: the lines the balance covers in full.', [
                'amount' => Money::format(app(ApplyCredit::class)->coverable($record), $record->currency),
                'balance' => Money::format((int) $record->client->credit_cents, $record->currency),
            ]))
            ->action(function (Invoice $record) {
                $apply = app(ApplyCredit::class);

                try {
                    $paid = $apply->toInvoice($record);
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Not recorded'))->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title(__(':amount paid from credit', ['amount' => Money::format($paid, $record->currency)]))->success()->send();

                foreach ($apply->renewalErrors as $line => $error) {
                    Notification::make()
                        ->title(__('The payment is recorded, but :line was not renewed at the registrar', ['line' => $line]))
                        ->body($error.' '.__('Renew it at the registrar by hand.'))
                        ->danger()
                        ->persistent()
                        ->send();
                }

                $this->refreshFormData(['status', 'paid_on']);
            });
    }

    private function sendEmail(): Action
    {
        return Action::make('sendInvoiceEmail')
            ->label('Send email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Unpaid)
            ->schema([
                Select::make('template')
                    ->label('Email')
                    ->options(collect([
                        MailTemplate::InvoiceCreated, MailTemplate::InvoicePaymentReminder, MailTemplate::InvoiceModified,
                        MailTemplate::InvoiceFirstOverdue, MailTemplate::InvoiceSecondOverdue,
                    ])->mapWithKeys(fn (MailTemplate $t) => [$t->value => $t->label()]))
                    ->default(fn (Invoice $record) => ($record->emailed_at ? MailTemplate::InvoiceModified : MailTemplate::InvoiceCreated)->value)
                    ->required(),
            ])
            ->modalDescription(fn (Invoice $record) => new HtmlString(__('Sends it to :email now, even if that email is switched off on the Email templates page.', ['email' => '<strong>'.e($record->client->email).'</strong>'])))
            ->action(function (Invoice $record, array $data) {
                $template = MailTemplate::from($data['template']);

                if (! app(SendInvoiceEmails::class)->send($record->load('items', 'client'), $template, evenIfOff: true)) {
                    Notification::make()->title(__('Not sent'))->body(__('See the activity log for the error.'))->danger()->send();

                    return;
                }

                if ($record->emailed_at === null) {
                    $record->update(['emailed_at' => now()]);
                }

                Notification::make()->title(__('Email sent'))->success()->send();
                $this->refreshFormData(['emailed_at']);
            });
    }

    /**
     * Personal reminder: the reminder (or, once overdue, the first overdue) email rewritten by the AI assistant for how
     * this client pays, in their language, to read, change and send.
     */
    private function aiReminder(): Action
    {
        return Action::make('aiReminder')
            ->label('Personal reminder')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Unpaid && Assistant::enabled('reminders'))
            ->modalHeading(__('Personal reminder'))
            ->modalDescription(fn (Invoice $record) => __('Written by :service for how this client pays, from the email template. Change it if you like; Send emails it to :email now.', ['service' => Assistant::provider()?->aiLabel(), 'email' => $record->client->email]))
            ->modalWidth('3xl')
            ->fillForm(function (Invoice $record, Action $action) {
                $template = self::reminderTemplate($record);
                $locale = $record->client->preferredLocale();
                $facts = (new ClientFacts($record->client))->notice($record);

                try {
                    $text = app(Assistant::class)->reminderText(MailTemplates::get($template, $locale), $facts, $locale);
                } catch (AiException $e) {
                    Notification::make()->danger()->title(__('The AI assistant gave no answer'))->body($e->getMessage())->send();
                    $action->cancel();

                    return [];
                }

                return $text;
            })
            ->schema([
                TextInput::make('subject')
                    ->label('Subject')
                    ->required()
                    ->maxLength(200),
                Textarea::make('body')
                    ->label('Message')
                    ->required()
                    ->rows(14)
                    ->helperText(__('Placeholders such as {$invoice_total} are filled in when it is sent.')),
            ])
            ->modalSubmitActionLabel(__('Send'))
            ->action(function (Invoice $record, array $data) {
                $text = ['subject' => $data['subject'], 'body' => $data['body']];

                if (! app(SendInvoiceEmails::class)->send($record->load('items', 'client'), self::reminderTemplate($record), evenIfOff: true, text: $text)) {
                    Notification::make()->title(__('Not sent'))->body(__('See the activity log for the error.'))->danger()->send();

                    return;
                }

                if ($record->emailed_at === null) {
                    $record->update(['emailed_at' => now()]);
                }

                Notification::make()->title(__('Email sent'))->success()->send();
                $this->refreshFormData(['emailed_at']);
            });
    }

    private static function reminderTemplate(Invoice $invoice): MailTemplate
    {
        return $invoice->due_date->lt(today()) ? MailTemplate::InvoiceFirstOverdue : MailTemplate::InvoicePaymentReminder;
    }

    /** An open notice keeps the client's details from the day it was issued until this takes the current ones. */
    private function updateBillTo(): Action
    {
        return Action::make('updateBillTo')
            ->label('Update client details')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Unpaid && $record->billToChanged())
            ->requiresConfirmation()
            ->modalDescription(__('The notice shows the client\'s name, address and VAT details as they were when it was issued. This replaces them with the client\'s current ones.'))
            ->action(function (Invoice $record) {
                $record->refreshBillTo();

                Notification::make()->title(__('Client details updated'))->success()->send();
                $this->refreshFormData(['bill_to']);
            });
    }

    private function cancel(): Action
    {
        return Action::make('cancelInvoice')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Unpaid)
            ->requiresConfirmation()
            ->modalHeading(__('Cancel payment notice'))
            ->modalDescription(__('No more emails are sent for it. While a service on it stays active and due, the next daily run creates a new notice for it (e.g. after you changed its price); terminate or cancel the service if it will not renew.'))
            ->action(function (Invoice $record) {
                $record->update(['status' => InvoiceStatus::Cancelled]);
                ActivityLog::record('invoice.cancelled', "Payment notice {$record->number} cancelled", client: $record->client, properties: ['invoice_id' => $record->id]);

                Notification::make()->title(__('Payment notice cancelled'))->success()->send();
                $this->refreshFormData(['status']);
            });
    }
}
