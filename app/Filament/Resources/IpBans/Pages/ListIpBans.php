<?php

namespace App\Filament\Resources\IpBans\Pages;

use App\Filament\Clusters\Settings\Pages\LoginSecuritySettings;
use App\Filament\Resources\IpBans\IpBanResource;
use App\Models\ActivityLog;
use App\Support\LoginSecurity;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListIpBans extends ListRecords
{
    protected static string $resource = IpBanResource::class;

    public function getSubheading(): ?string
    {
        return LoginSecurity::enabled()
            ? __('An address is banned for :minutes minutes after :attempts failed logins.', ['minutes' => LoginSecurity::banMinutes(), 'attempts' => LoginSecurity::maxAttempts()])
            : __('Banning is off, so nobody is kept out. Switch it on under Settings → Login security.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')
                ->label('Login security settings')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color('gray')
                ->url(LoginSecuritySettings::getUrl()),
            Action::make('ban')
                ->label('Ban an IP address')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->schema([
                    TextInput::make('ip')
                        ->label('IP address')
                        ->required()
                        ->ip()
                        ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                            if (LoginSecurity::isAllowListed((string) $value)) {
                                $fail(__('This address is on the allow-list under Settings → Login security.'));
                            } elseif (LoginSecurity::normalize((string) $value) === LoginSecurity::normalize((string) request()->ip())) {
                                $fail(__('This is your own address.'));
                            }
                        }),
                    TextInput::make('reason')->maxLength(255),
                    TextInput::make('minutes')
                        ->label('Ban length')
                        ->suffix(__('minutes'))
                        ->helperText(__('Leave blank to keep it until you lift it.'))
                        ->integer()
                        ->minValue(1),
                ])
                ->action(function (array $data) {
                    $ban = LoginSecurity::ban($data['ip'], filled($data['reason'] ?? null) ? $data['reason'] : null,
                        filled($data['minutes'] ?? null) ? now()->addMinutes((int) $data['minutes']) : null);

                    ActivityLog::record('ip.banned', "Banned {$ban->ip} by hand", properties: ['ip' => $ban->ip, 'expires_at' => $ban->expires_at?->toIso8601String()]);
                    Notification::make()->success()->title(__(':ip banned', ['ip' => $ban->ip]))->send();
                }),
        ];
    }
}
