<?php

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;

/**
 * Picks which tiles of a wide × deep block a furniture piece covers, drawn
 * as the floor looks in the game at facing 0: click a tile to add or remove
 * it. Its state is the ticked tiles as "x,y" keys.
 */
class ShapePicker extends Field
{
    protected string $view = 'filament.forms.components.shape-picker';

    protected int|Closure $wide = 1;

    protected int|Closure $deep = 1;

    public function size(int|Closure $wide, int|Closure $deep): static
    {
        $this->wide = $wide;
        $this->deep = $deep;

        return $this;
    }

    public function getWide(): int
    {
        return (int) $this->evaluate($this->wide);
    }

    public function getDeep(): int
    {
        return (int) $this->evaluate($this->deep);
    }
}
