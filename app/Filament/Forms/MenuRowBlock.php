<?php

namespace App\Filament\Forms;

use Closure;
use Filament\Forms\Components\Builder\Block;
use Illuminate\Contracts\View\View;

/**
 * A line of Settings → Client area → Menus: shown as a short preview (its icon and what it opens), its fields only in
 * the window that opens on a click, so a long menu in every language stays light.
 */
class MenuRowBlock extends Block
{
    /** @var Closure(array): array{icon: ?string, text: string} */
    protected ?Closure $describe = null;

    public function describe(Closure $describe): static
    {
        $this->describe = $describe;
        $this->preview('filament.forms.menu-row');

        return $this;
    }

    public function renderPreview(array $data): View
    {
        return view('filament.forms.menu-row', ($this->describe)($data));
    }
}
