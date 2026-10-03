<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QObject;
use QPushButton;
use QSizePolicy\Policy;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\NutsAndBolts\Color;
use Surface\Windows\Primitives\TKButton;
use Surface\Windows\Primitives\TKPrimitiveGroup;

class QtButton extends TKButton implements QtNative
{
    use QtPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $label)
    {
        parent::__construct($name, $window, $parent, $placement, $label);
        $this->adoptNative(new QPushButton(self::literal($label)));
        // A disabled QPushButton swallows clicks: no signal, no mail.
        QObject::connect($this->native, 'clicked(bool)', function (): void {
            if ($this->handling()) {
                $this->session()->post(new ButtonClicked($this->window->name(), $this->path(), $this->uuid));
            }
        });
    }

    public function native(): QPushButton
    {
        return $this->native;
    }

    protected function naturalPolicy(): array
    {
        return [Policy::MINIMUM, Policy::FIXED];
    }

    protected function applyLabel(string $label): void
    {
        $this->native()->setText(self::literal($label));
    }

    protected function applyBackground(?Color $color): void
    {
        $this->applyButtonBackground($color);
    }
}
