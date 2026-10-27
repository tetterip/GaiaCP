<?php

namespace App\Plugins;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PluginState: string implements HasColor, HasLabel
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';

    /** Needs a newer GaiaCP than this one (Plugin::requires()): off until GaiaCP is updated. */
    case Incompatible = 'incompatible';

    /** One the public demo keeps off (App\Support\Demo::PLUGINS_OFF). */
    case OffInDemo = 'off_in_demo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Enabled => __('Enabled'),
            self::Disabled => __('Disabled'),
            self::Incompatible => __('Needs a newer GaiaCP'),
            self::OffInDemo => __('Off in the demo'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Enabled => 'success',
            self::Disabled => 'warning',
            self::Incompatible => 'danger',
            self::OffInDemo => 'gray',
        };
    }
}
