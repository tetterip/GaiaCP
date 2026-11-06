<?php

namespace App\Filament\Resources\Clients\Actions;

use App\Models\Client;
use App\Privacy\AnonymizeClient;
use App\Privacy\ClientDataExport;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The "Personal data" menu on a client, for data requests (GDPR): download everything kept about the client, or
 * anonymize them once their services have ended. Super admins only, since both reach all of the client's records.
 */
class ClientPrivacyActions
{
    public static function make(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('exportClientData')
                ->label('Download their data')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(function (Client $record): BinaryFileResponse {
                    $export = new ClientDataExport($record);

                    return response()->download($export->zip(), $export->fileName())->deleteFileAfterSend();
                }),
            Action::make('anonymizeClient')
                ->label('Anonymize')
                ->icon(Heroicon::OutlinedEyeSlash)
                ->color('danger')
                ->modalHeading(__('Anonymize this client'))
                ->modalIcon(Heroicon::OutlinedEyeSlash)
                ->schema(fn (Client $record) => ($blockers = AnonymizeClient::blockers($record))
                    ? [Text::make(new HtmlString('<ul class="list-disc ps-5">'.collect($blockers)->map(fn (string $line) => '<li>'.e($line).'</li>')->implode('').'</ul>'))]
                    : [
                        Text::make(__('Their name, email, phone, address, VAT number, notes and custom fields are replaced by "Anonymized #:id". Their client area login, sign-ins, tickets with their files, the emails sent to them, leads and requests are deleted, and their name is taken out of the activity log. Payment notices, invoices, payments and credit stay as they are, with the details they were issued to, because the law keeps them. This cannot be undone: download their data first if they asked for it.', ['id' => $record->id])),
                        TextInput::make('confirm')
                            ->label(__('Type :email to confirm', ['email' => $record->email]))
                            ->required()
                            ->in([$record->email])
                            ->validationMessages(['in' => __('Type the client\'s email exactly.')]),
                    ])
                ->modalSubmitAction(fn (Action $action, Client $record) => AnonymizeClient::blockers($record) ? false : $action->getModalSubmitAction()->label(__('Anonymize'))->color('danger'))
                ->modalCancelActionLabel(fn (Client $record) => AnonymizeClient::blockers($record) ? __('Close') : __('Cancel'))
                ->hidden(fn (Client $record) => AnonymizeClient::done($record))
                ->action(function (Client $record) {
                    app(AnonymizeClient::class)($record);

                    Notification::make()->success()->title(__('Client anonymized'))->send();
                }),
        ])
            ->label('Personal data')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->button()
            ->color('gray')
            ->visible(fn () => (bool) auth()->user()?->super_admin);
    }
}
