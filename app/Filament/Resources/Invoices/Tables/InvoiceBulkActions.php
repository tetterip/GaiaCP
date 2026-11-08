<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Actions\RecordInvoicePayment;
use App\Actions\ReversePayment;
use App\Enums\InvoiceStatus;
use App\Enums\MailTemplate;
use App\Filament\Actions\BulkOutcome;
use App\Invoices\SendInvoiceEmails;
use App\Models\Invoice;
use App\Models\Payment;
use App\Payments\PaymentMethods;
use Carbon\CarbonImmutable;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection as Records;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mass actions on the payment notices picked in a list. Each does what the notice page's own action does, notice by
 * notice (same checks, emails and activity log); the ones it can't apply to are left out and named in the summary.
 */
class InvoiceBulkActions
{
    /** Most notices one action takes: each payment may call a registrar or a server panel. */
    public const MAX = 100;

    private const BY_DUE_DATE = 'by_due_date';

    public static function group(): BulkActionGroup
    {
        return BulkActionGroup::make([
            self::markPaid(),
            self::sendEmail(),
            self::markUnpaid(),
            self::cancel(),
        ]);
    }

    /** As Record payment on each: one payment per unpaid line, due dates move forward, suspended services come back. */
    public static function markPaid(): BulkAction
    {
        $skip = fn (Invoice $invoice) => $invoice->status === InvoiceStatus::Unpaid ? null : __('not unpaid');

        return BulkAction::make('markPaid')
            ->label('Mark as paid')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->modalHeading(__('Mark as paid'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '
                .__('Each one is recorded as its Record payment would: one payment per unpaid line, each service\'s due date moves forward one cycle and domains are renewed at the registrar.'))
            ->schema([
                DatePicker::make('paid_on')
                    ->required()
                    ->default(now())
                    ->maxDate(now()),
                Select::make('method')
                    ->options(fn () => PaymentMethods::options())
                    ->default(fn () => PaymentMethods::default())
                    ->required(),
                TextInput::make('reference')
                    ->maxLength(255)
                    ->helperText(__('The same reference on every payment; leave it empty to add each one later.')),
            ])
            ->modalSubmitActionLabel(__('Mark as paid'))
            ->deselectRecordsAfterCompletion()
            ->action(function (Records $records, array $data) use ($skip) {
                $renewals = [];

                BulkOutcome::run(
                    $records->load('client', 'items'),
                    $skip,
                    function (Invoice $invoice) use ($data, &$renewals) {
                        $recorder = app(RecordInvoicePayment::class);
                        $recorder->handle($invoice, CarbonImmutable::parse($data['paid_on']), $data['method'], $data['reference'] ?? null);

                        foreach ($recorder->renewalErrors as $line => $error) {
                            $renewals[$line] = $error;
                        }
                    },
                    fn (Invoice $invoice) => $invoice->number,
                )->notify(':count payment notice marked paid|:count payment notices marked paid');

                self::renewalErrors($renewals);
            });
    }

    /** As Send email on each unpaid one, with the email picked here. */
    public static function sendEmail(): BulkAction
    {
        $skip = fn (Invoice $invoice) => $invoice->status === InvoiceStatus::Unpaid ? null : __('not unpaid');

        return BulkAction::make('sendEmail')
            ->label('Send reminder')
            ->icon(Heroicon::OutlinedEnvelope)
            ->modalHeading(__('Send reminder'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '
                .__('Each client gets the email now, even if it is switched off on the Email templates page.'))
            ->schema([
                Select::make('template')
                    ->label('Email')
                    ->options([self::BY_DUE_DATE => __('Payment reminder, or 1st overdue once past due')] + collect([
                        MailTemplate::InvoiceCreated, MailTemplate::InvoicePaymentReminder, MailTemplate::InvoiceModified,
                        MailTemplate::InvoiceFirstOverdue, MailTemplate::InvoiceSecondOverdue,
                    ])->mapWithKeys(fn (MailTemplate $t) => [$t->value => $t->label()])->all())
                    ->default(self::BY_DUE_DATE)
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('Send'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Records $records, array $data) => BulkOutcome::run(
                $records->load('client', 'items'),
                $skip,
                function (Invoice $invoice) use ($data) {
                    $template = $data['template'] === self::BY_DUE_DATE
                        ? ($invoice->due_date->lt(today()) ? MailTemplate::InvoiceFirstOverdue : MailTemplate::InvoicePaymentReminder)
                        : MailTemplate::from($data['template']);

                    if (! app(SendInvoiceEmails::class)->send($invoice, $template, evenIfOff: true)) {
                        throw new RuntimeException(__('Not sent. See the activity log for the error.'));
                    }

                    if ($invoice->emailed_at === null) {
                        $invoice->update(['emailed_at' => now()]);
                    }
                },
                fn (Invoice $invoice) => $invoice->number,
            )->notify(':count email sent|:count emails sent'));
    }

    /**
     * Undoes the payments of paid notices, each as Reverse on the service's payment: only where every one of them is
     * still its service's latest and the due date is the one it set. Due dates go back; an invoice gets a credit note.
     */
    public static function markUnpaid(): BulkAction
    {
        $skip = fn (Invoice $invoice) => self::whyNotReversible($invoice);

        return BulkAction::make('markUnpaid')
            ->label('Mark as unpaid')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Mark as unpaid'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '
                .__('Their payments are reversed: each service\'s due date goes back to where it was, a payment from credit goes back to the balance and an issued invoice gets a credit note. Nothing is refunded or changed on a server or registrar.'))
            ->modalSubmitActionLabel(__('Mark as unpaid'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Records $records) => BulkOutcome::run(
                $records->load('items'),
                $skip,
                fn (Invoice $invoice) => DB::transaction(function () use ($invoice) {
                    foreach (self::payments($invoice)->sortByDesc('id') as $payment) {
                        app(ReversePayment::class)->handle($payment);
                    }
                }),
                fn (Invoice $invoice) => $invoice->number,
            )->notify(':count payment notice marked unpaid|:count payment notices marked unpaid'));
    }

    /** As Cancel on each unpaid one: a partly paid notice keeps its paid lines and is closed as paid. */
    public static function cancel(): BulkAction
    {
        $skip = fn (Invoice $invoice) => $invoice->status === InvoiceStatus::Unpaid ? null : __('not unpaid');

        return BulkAction::make('cancel')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Cancel payment notices'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '
                .__('No more emails are sent for them. A partly paid one loses only its unpaid lines and is closed as paid. While a service on them stays active and due, the next daily run creates a new notice for it.'))
            ->modalSubmitActionLabel(__('Cancel them'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Records $records) => BulkOutcome::run(
                $records->load('client'),
                $skip,
                fn (Invoice $invoice) => $invoice->cancel('mass action'),
                fn (Invoice $invoice) => $invoice->number,
            )->notify(':count payment notice cancelled|:count payment notices cancelled'));
    }

    /** Null when every payment of a paid notice can be reversed; else why not (a translated text). */
    public static function whyNotReversible(Invoice $invoice): ?string
    {
        if ($invoice->status !== InvoiceStatus::Paid) {
            return __('not paid');
        }

        if ($invoice->credit || $invoice->items->contains(fn ($item) => $item->payment_id === null)) {
            return __('an Add funds notice, or a line without a payment');
        }

        $payments = self::payments($invoice);

        if ($payments->isEmpty() || $payments->contains(fn (Payment $payment) => ! ReversePayment::canReverse($payment))) {
            return __('a later payment or due date change came after it');
        }

        return null;
    }

    /** @return Collection<int, Payment> */
    private static function payments(Invoice $invoice): Collection
    {
        return Payment::query()->whereIn('id', $invoice->items->pluck('payment_id')->filter()->unique())->with('service')->get();
    }

    /** @param  array<string, string>  $errors */
    private static function renewalErrors(array $errors): void
    {
        foreach ($errors as $line => $error) {
            Notification::make()
                ->title(__('The payment is recorded, but :line was not renewed at the registrar', ['line' => $line]))
                ->body($error.' '.__('Renew it at the registrar by hand.'))
                ->danger()
                ->persistent()
                ->send();
        }
    }
}
