<?php

namespace App\Filament\Client\Pages;

use App\ClientSessions\ClientSessions;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\ClientLogin;
use App\Models\ClientSession;
use App\Models\User;
use App\Providers\AppServiceProvider;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * The client's signed-in devices, with "Log out" for each and "Log out other devices", and their recent sign-ins,
 * failed tries included (App\ClientSessions\ClientSessions). In the client's menu, not in the sidebar.
 */
class SignIns extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static ?string $title = 'Devices and sign-ins';

    protected static ?string $slug = 'sign-ins';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.client.sign-ins';

    public static function canAccess(): bool
    {
        return filament()->auth()->user()?->client !== null;
    }

    private function user(): User
    {
        /** @var User */
        return filament()->auth()->user();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('logOutOthers')
                ->label('Log out other devices')
                ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Every other browser signed in to your account has to sign in again.'))
                ->visible(fn () => ClientSession::query()->where('user_id', $this->user()->id)->where('token_hash', '!=', (string) ClientSessions::currentHash())->exists())
                ->action(function () {
                    $count = app(ClientSessions::class)->logOutOthers($this->user());
                    Notification::make()->success()->title(trans_choice('{1} Logged out of :count device|[2,*] Logged out of :count devices', $count, ['count' => $count]))->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => ClientSession::query()->where('user_id', $this->user()->id))
            ->defaultSort('last_active_at', 'desc')
            ->heading(__('Signed in now'))
            ->paginated(false)
            // Side by side from sm; on a phone the device, its address and when it was last active stack, so the
            // device keeps the full width and Log out stays in view
            ->columns([
                Split::make([
                    TextColumn::make('user_agent')
                        ->label('Device')
                        ->weight('medium')
                        ->formatStateUsing(fn (ClientSession $record) => $record->device())
                        ->description(fn (ClientSession $record) => collect([$record->isCurrent() ? __('This device') : null, $record->ip_address])->filter()->implode(' · ') ?: null)
                        ->wrap()
                        ->extraAttributes(['style' => 'overflow-wrap: anywhere;']),
                    TextColumn::make('last_active_at')
                        ->label('Last active')
                        ->formatStateUsing(fn (ClientSession $record) => __('Last active :time', ['time' => $record->last_active_at->diffForHumans()]))
                        ->tooltip(fn (ClientSession $record) => __('Signed in :date', ['date' => $record->created_at->format(AppServiceProvider::dateFormat().' H:i')]))
                        ->color('gray')
                        ->size('sm')
                        ->grow(false),
                ])->from('sm'),
            ])
            ->recordActions([
                Action::make('logOut')
                    ->label('Log out')
                    ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->hidden(fn (ClientSession $record) => $record->isCurrent())
                    ->action(function (ClientSession $record) {
                        $record->delete();
                        Notification::make()->success()->title(__('Logged out of :device', ['device' => $record->device()]))->send();
                    }),
            ]);
    }

    /** @return Collection<int, ClientLogin> the last 20 sign-ins and failed tries */
    public function recent(): Collection
    {
        return ClientLogin::query()->where('user_id', $this->user()->id)->latest('id')->limit(20)->get();
    }
}
