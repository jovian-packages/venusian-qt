<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtButtonImage;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QObject;
use QPushButton;
use QSizePolicy\Policy;
use Surface\Contracts\Toolkits\Mail\Views\ButtonClicked;
use Surface\Contracts\Toolkits\Primitives\ButtonRole;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Surface\Contracts\Toolkits\ToolkitWindow;
use Surface\NutsAndBolts\Color;
use Surface\Toolkits\Primitives\TKButton;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;

class QtButton extends TKButton implements QtNative
{
    use QtButtonImage;
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

    protected function applyBackground(?Color $color): void
    {
        $this->applyButtonBackground($color);
    }

    /** A default QPushButton paints as the default button in any window. QtButtonKeys clicks it. */
    protected function applyRole(?ButtonRole $role): void
    {
        $this->native()->setDefault($role === ButtonRole::DEFAULT);
    }

    /** QPushButton has no alignment property: the style sheet's text-align places its label. */
    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->setStyle('text-align', $alignment->value);
    }
}
