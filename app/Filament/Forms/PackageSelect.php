<?php

namespace App\Filament\Forms;

use App\Provisioning\PackageOptions;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * The package / service plan picker: a normal searchable dropdown with the server's packages,
 * plus the current value, and "create" for a name the server did not return (unreachable server).
 */
class PackageSelect
{
    /** @param  Closure  $serverId  evaluated like any Filament closure (Get $get, $record, …) */
    public static function make(Closure $serverId, string $name = 'package'): Select
    {
        return Select::make($name)
            ->label('Package')
            ->searchable()
            ->options(function (Select $component, ?string $state) use ($serverId) {
                $packages = PackageOptions::forServer($component->evaluate($serverId));

                if (filled($state) && ! in_array($state, $packages, true)) {
                    $packages[] = $state;
                }

                return array_combine($packages, $packages);
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
