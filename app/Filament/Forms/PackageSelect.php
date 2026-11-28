<?php

namespace App\Filament\Forms;

use App\Provisioning\PackageOptions;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * The package / service plan picker: a normal searchable dropdown with the server's packages (with their disk space
 * and bandwidth where the panel gives them),
 * plus the current value, and "create" for a name the server did not return (unreachable server).
 */
class PackageSelect
{
    /** @param  Closure  $serverId  evaluated like any Filament closure (Get $get, $record, …): a server, or a product's servers */
    public static function make(Closure $serverId, string $name = 'package'): Select
    {
        return Select::make($name)
            ->label('Package')
            ->searchable()
            ->options(function (Select $component, ?string $state) use ($serverId) {
                // "basic · 5 GB disk · 50 GB bandwidth" where the panel says how big each one is
                $servers = $component->evaluate($serverId);
                $packages = is_array($servers) ? PackageOptions::commonLabels($servers) : PackageOptions::labels($servers);

                if (filled($state) && ! isset($packages[$state])) {
                    $packages[$state] = $state;
                }

                return $packages;
            })
            ->createOptionForm([
                TextInput::make('name')
                    ->label('Package name')
                    ->required()
                    ->maxLength(255),
            ])
            ->createOptionUsing(fn (array $data): string => trim($data['name']))
            ->createOptionModalHeading(__('Package not listed by the server'));
    }
}
