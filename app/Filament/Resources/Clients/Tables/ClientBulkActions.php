<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Actions\ClientAreaAccess;
use App\Enums\UserRole;
use App\Filament\Actions\BulkOutcome;
use App\Filament\Actions\MessageClientsBulkAction;
use App\Models\ActivityLog;
use App\Models\Client;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mass actions on the clients picked in a list: Send message emails them, Send password reset link does what the
 * client page's own action does, Delete removes only clients with no history (as the client page's Delete).
 */
class ClientBulkActions
{
    /** Most clients one action takes. */
    public const MAX = 100;

    public static function group(): BulkActionGroup
    {
        return BulkActionGroup::make([
            MessageClientsBulkAction::make(fn (Collection $records) => $records->modelKeys()),
            self::sendPasswordResetLink(),
            self::delete(),
        ]);
    }

    /** As Send password reset link on each client whose own client area login is turned on. */
    public static function sendPasswordResetLink(): BulkAction
    {
        $skip = fn (Client $client) => $client->user !== null && $client->user->role === UserRole::Client && $client->user->active
            ? null : __('no client area login that is turned on');

        return BulkAction::make('sendPasswordResetLink')
            ->label('Send password reset link')
            ->icon(Heroicon::OutlinedKey)
            ->requiresConfirmation()
            ->modalHeading(__('Send password reset link'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records->load('user'), $skip).' '
                .__('Each client gets the same link as "Forgot password?" on the login page. Their password stays as it is until they use it.'))
            ->modalSubmitActionLabel(__('Send'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records->load('user'),
                $skip,
                fn (Client $client) => app(ClientAreaAccess::class)->sendPasswordResetLink($client),
                fn (Client $client) => $client->name,
            )->notify(':count link sent|:count links sent'));
    }

    /** Clients with no services, orders, notices, payments or invoices; never one an admin logs in as. */
    public static function delete(): BulkAction
    {
        $skip = fn (Client $client) => self::whyNotDeletable($client);

        return BulkAction::make('delete')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->modalHeading(__('Delete clients'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records->load('user'), $skip).' '
                .__('Only clients with no services, orders, payment notices, payments or invoices are deleted, with their client area login. This cannot be undone.'))
            ->schema([
                TextInput::make('confirm')
                    ->label('Type DELETE to confirm')
                    ->required()
                    ->in(['DELETE'])
                    ->validationMessages(['in' => __('Type DELETE to confirm.')]),
            ])
            ->modalSubmitActionLabel(__('Delete them'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records->load('user'),
                $skip,
                fn (Client $client) => self::destroy($client),
                fn (Client $client) => $client->name,
            )->notify(':count client deleted|:count clients deleted'));
    }

    /** Null when the client can be deleted; else why not (a translated text). */
    public static function whyNotDeletable(Client $client): ?string
    {
        if ($client->keepsHistory()) {
            return __('has services, orders, payment notices, payments or invoices');
        }

        // Deleting the client deletes its login too, so never one that is an admin's.
        if (ClientAreaAccess::isLinkedAdmin($client)) {
            return __('linked to an admin login');
        }

        return null;
    }

    private static function destroy(Client $client): void
    {
        DB::transaction(function () use ($client) {
            $client = Client::query()->with('user')->lockForUpdate()->findOrFail($client->id);

            if (($why = self::whyNotDeletable($client)) !== null) {
                throw new RuntimeException($why);
            }

            $client->delete();

            ActivityLog::record('client.deleted', "Client {$client->name} ({$client->email}) deleted by a mass action");
        });
    }
}
