<?php

namespace App\Filament\Resources\TaxInvoices\Pages;

use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Invoicing\InvoicePdf;
use App\Invoicing\IssueCreditNote;
use App\Invoicing\IssueInvoice;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceItem;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use RuntimeException;

/** One invoice or credit note, read only: it is never changed after it was issued. A credit note is what undoes one. */
class ViewTaxInvoice extends ViewRecord
{
    protected static string $resource = TaxInvoiceResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->isCreditNote()
            ? __('Credit note :number', ['number' => $this->getRecord()->number])
            : __('Invoice :number', ['number' => $this->getRecord()->number]);
    }

    protected function getHeaderActions(): array
    {
        return [
            self::downloadAction(),
            $this->issueCreditNote(),
            Action::make('sendTaxInvoice')
                ->label('Send email')
                ->visible(fn (TaxInvoice $record) => ! $record->isCreditNote())
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn (TaxInvoice $record) => new HtmlString(__('Sends the Invoice Issued email with the PDF to :email now, even if that email is switched off on the Email templates page.', ['email' => '<strong>'.e($record->client->email).'</strong>'])))
                ->action(function (TaxInvoice $record) {
                    if (! app(IssueInvoice::class)->email($record, evenIfOff: true)) {
                        Notification::make()->title(__('Not sent'))->body(__('See the activity log for the error.'))->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('Email sent'))->success()->send();
                }),
        ];
    }

    /** The lines not credited yet, all ticked: a credit note for them, with the next credit note number. */
    private function issueCreditNote(): Action
    {
        return Action::make('issueCreditNote')
            ->label('Issue credit note')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('gray')
            ->visible(fn (TaxInvoice $record) => $record->creditableItems()->isNotEmpty())
            ->modalDescription(__('A credit note undoes the chosen lines of this invoice, dated today with the next credit note number. It can\'t be changed or deleted afterwards. It records no refund: record one as you would any money paid back.'))
            ->schema(fn (TaxInvoice $record) => [
                CheckboxList::make('items')
                    ->label('Lines')
                    ->options($record->creditableItems()->mapWithKeys(fn (TaxInvoiceItem $item) => [
                        $item->id => $item->description.' ('.Money::format($item->total_cents, $record->currency).')',
                    ]))
                    ->default($record->creditableItems()->pluck('id')->all())
                    ->required(),
                TextInput::make('reason')
                    ->label('Reason')
                    ->maxLength(255),
            ])
            ->action(function (TaxInvoice $record, array $data) {
                try {
                    $note = app(IssueCreditNote::class)->handle($record, array_map('intval', $data['items']), $data['reason'] ?? null);
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('No credit note issued'))->body(__('Every chosen line of this invoice has been credited already.'))->danger()->send();

                    return;
                }

                Notification::make()->title(__('Credit note :number issued', ['number' => $note->number]))->success()->send();
                $this->redirect(TaxInvoiceResource::getUrl('view', ['record' => $note]));
            });
    }

    /** Download PDF, also in the client area. */
    public static function downloadAction(string $name = 'downloadPdf'): Action
    {
        return Action::make($name)
            ->label('Download PDF')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(fn (TaxInvoice $record) => response()->streamDownload(
                fn () => print (InvoicePdf::content($record)),
                InvoicePdf::filename($record),
                ['Content-Type' => 'application/pdf'],
            ));
    }
}
