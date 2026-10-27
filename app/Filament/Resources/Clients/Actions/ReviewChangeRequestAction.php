<?php

namespace App\Filament\Resources\Clients\Actions;

use App\Actions\ClientChangeRequests;
use App\Mail\ClientChangeRequestMail;
use App\Models\Client;
use App\Models\ClientChangeRequest;
use App\Providers\AppServiceProvider;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/** On a client with a pending change request from the client area: shows old → new, Approve saves it, Decline drops it. */
class ReviewChangeRequestAction
{
    public static function make(): Action
    {
        return Action::make('reviewChangeRequest')
            ->label('Review requested changes')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->visible(fn (Client $record) => self::pending($record) !== null)
            ->modalHeading(__('Requested changes'))
            ->modalDescription(fn (Client $record) => __('Asked from the client area on :date. Approve saves them on the client.', [
                'date' => self::pending($record)?->updated_at->format(AppServiceProvider::dateFormat().' H:i'),
            ]))
            ->modalContent(fn (Client $record) => self::table(self::pending($record)))
            ->modalSubmitAction(fn (Action $action) => $action->label(__('Approve'))->color('success'))
            ->extraModalFooterActions([
                Action::make('declineChangeRequest')
                    ->label('Decline')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('Nothing changes on the client. They see that the request was declined on their profile.'))
                    ->action(function (Client $record, EditRecord $livewire) {
                        if ($request = self::pending($record)) {
                            app(ClientChangeRequests::class)->reject($request);
                        }

                        Notification::make()->title(__('Request declined'))->success()->send();
                        $livewire->redirect($livewire->getUrl(['record' => $record]));
                    })
                    ->cancelParentActions(),
            ])
            ->action(function (Client $record, EditRecord $livewire) {
                if ($request = self::pending($record)) {
                    app(ClientChangeRequests::class)->approve($request);
                }

                Notification::make()->title(__('Changes saved on the client'))->success()->send();
                // Reload the form with the new details
                $livewire->redirect($livewire->getUrl(['record' => $record]));
            });
    }

    private static function pending(Client $client): ?ClientChangeRequest
    {
        return $client->changeRequests()->pending()->latest('id')->first();
    }

    private static function table(?ClientChangeRequest $request): HtmlString
    {
        $rows = $request ? ClientChangeRequestMail::rows($request) : [];

        $html = '<table style="width:100%;font-size:.875rem;border-collapse:collapse">'
            .'<thead><tr>'.collect([__('Field'), __('Now'), __('Asked for')])->map(fn ($h) => '<th style="text-align:start;padding:.4rem;border-bottom:1px solid var(--gray-200)">'.e($h).'</th>')->implode('').'</tr></thead><tbody>';

        foreach ($rows as [$label, $old, $new]) {
            $html .= '<tr><td style="padding:.4rem">'.e($label).'</td><td style="padding:.4rem;color:var(--gray-500)">'.e($old).'</td><td style="padding:.4rem;font-weight:600">'.e($new).'</td></tr>';
        }

        return new HtmlString($html.'</tbody></table>');
    }
}
