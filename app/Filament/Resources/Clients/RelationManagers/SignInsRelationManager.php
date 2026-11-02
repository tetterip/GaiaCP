<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\ClientSessions\ClientSessions;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\Users\UserActions;
use App\Models\Client;
use App\Models\ClientLogin;
use App\Models\ClientSession;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The client's client area sign-ins and failed tries, newest first, and "Log out everywhere" while a browser is
 * signed in (App\ClientSessions\ClientSessions). Shown once the client has signed in or been tried.
 */
class SignInsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'clientLogins';

    protected static ?string $title = 'Sign-ins';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedComputerDesktop;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        /** @var Client $ownerRecord */
        return $ownerRecord->clientLogins()->exists();
    }

    /** Browsers signed in now. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Client $ownerRecord */
        $count = self::sessions($ownerRecord)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        return __('Signed in now');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /** @return Builder<ClientSession> */
    private static function sessions(Client $client)
    {
        return ClientSession::query()->where('user_id', $client->user?->id ?? 0);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime(),
                IconColumn::make('succeeded')
                    ->label('Signed in')
                    ->boolean(),
                TextColumn::make('user_agent')
                    ->label('Device')
                    ->formatStateUsing(fn (ClientLogin $record) => $record->device())
                    ->tooltip(fn (ClientLogin $record) => $record->user_agent),
                TextColumn::make('ip_address')
                    ->label('IP address')
                    ->placeholder('—')
                    ->searchable(),
            ])
            ->filters([
                TernaryFilter::make('succeeded')
                    ->label('Signed in'),
            ])
            ->headerActions([
                Action::make('removePasskeys')
                    ->label(fn () => __('Remove passkeys (:count)', ['count' => $this->getOwnerRecord()->user?->passkeys()->count() ?? 0]))
                    ->icon(Heroicon::OutlinedFingerPrint)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn () => UserActions::passkeysDescription($this->getOwnerRecord()->user))
                    ->visible(fn () => (bool) $this->getOwnerRecord()->user?->passkeys()->exists())
                    ->action(fn () => UserActions::deletePasskeys($this->getOwnerRecord()->user, 'an admin')),
                Action::make('logOutEverywhere')
                    ->label(fn () => __('Log out everywhere (:count)', ['count' => self::sessions($this->getOwnerRecord())->count()]))
                    ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('Every browser signed in to this client\'s client area has to sign in again. Their password stays the same.'))
                    ->visible(fn () => self::sessions($this->getOwnerRecord())->exists())
                    ->action(function () {
                        $count = app(ClientSessions::class)->logOutEverywhere($this->getOwnerRecord()->user);
                        Notification::make()->success()->title(trans_choice('{1} Logged out of :count device|[2,*] Logged out of :count devices', $count, ['count' => $count]))->send();
                    }),
            ]);
    }
}
