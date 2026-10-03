<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QObject;
use QPushButton;
use QSizePolicy\Policy;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\NutsAndBolts\Color;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKToggleButton;

/**
 * A checkable QPushButton: pressed is checked.
 */
class QtToggleButton extends TKToggleButton implements QtNative
{
    use QtPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $label, bool $pressed)
    {
        parent::__construct($name, $window, $parent, $placement, $label, $pressed);
        $button = $this->adoptNative(new QPushButton(self::literal($label)));
        $button->setCheckable(true);
        $button->setChecked($pressed);

        QObject::connect($button, 'toggled(bool)', function (bool $on): void {
            if (! $this->handling()) {
                return;
            }
            $this->nativeToggled($on);
            $this->session()->post(new Toggled($this->window->name(), $this->path(), $this->uuid, $on));
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

    protected function applyPressed(bool $pressed): void
    {
        $this->applying(fn () => $this->native()->setChecked($pressed));
    }
}
