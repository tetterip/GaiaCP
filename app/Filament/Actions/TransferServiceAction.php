<?php

namespace App\Filament\Actions;

use App\Actions\TransferService;
use App\Enums\ServiceType;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Client;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/** "Move to another client" on a set-up service, whose Client field is locked (App\Actions\TransferService). */
class TransferServiceAction
{
    public static function make(): Action
    {
        return Action::make('transferService')
            ->label('Move to another client')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->visible(fn (Service $record) => TransferService::transferable($record))
            ->modalDescription(fn (Service $record) => __('Its unpaid lines come off :client\'s payment notices, overdue ones too, and the next daily run bills the new client. Payments already made, the order and old notices stay with :client.', ['client' => $record->client->name])
                .' '.($record->type === ServiceType::Domain
                    ? __('The registrant at the registrar does not change: update it there by hand.')
                    : __('Nothing changes on the server: move the account to the new client\'s customer in the panel by hand.')))
            ->schema(fn (Service $record) => array_filter([
                Select::make('client_id')
                    ->label('New client')
                    ->options(fn () => Client::query()->whereKeyNot($record->client_id)->orderBy('last_name')->orderBy('first_name')->get()
                        ->mapWithKeys(fn (Client $client) => [$client->id => $client->displayName()]))
                    ->getSearchResultsUsing(fn (string $search) => Client::query()->whereKeyNot($record->client_id)
                        ->where(fn (Builder $q) => $q->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('company', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->limit(50)->get()->mapWithKeys(fn (Client $client) => [$client->id => $client->displayName()]))
                    ->searchable()
                    ->required(),
                ($domain = TransferService::domainOf($record))
                    ? Toggle::make('with_domain')
                        ->label(__('Also move the domain :domain', ['domain' => $domain->domain]))
                        ->default(true)
                    : null,
            ]))
            ->action(function (Service $record, array $data) {
                $to = Client::findOrFail($data['client_id']);
                $moved = app(TransferService::class)->handle($record, $to, (bool) ($data['with_domain'] ?? false));

                Notification::make()
                    ->title(count($moved) > 1
                        ? __('Moved to :client, with its domain', ['client' => $to->name])
                        : __('Moved to :client', ['client' => $to->name]))
                    ->body($record->type === ServiceType::Domain || count($moved) > 1
                        ? __('Remember to change the account on the server and the registrant at the registrar.')
                        : __('Remember to change the account on the server.'))
                    ->success()
                    ->persistent()
                    ->send();

                return redirect(ServiceResource::getUrl('edit', ['record' => $record]));
            });
    }
}
