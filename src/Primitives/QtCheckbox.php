<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QCheckBox;
use QObject;
use QSizePolicy\Policy;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKCheckbox;
use Surface\Windows\Primitives\TKPrimitiveGroup;

class QtCheckbox extends TKCheckbox implements QtNative
{
    use QtPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $label, bool $checked)
    {
        parent::__construct($name, $window, $parent, $placement, $label, $checked);
        $box = $this->adoptNative(new QCheckBox(self::literal($label)));
        $box->setChecked($checked);

        QObject::connect($box, 'toggled(bool)', function (bool $on): void {
            if (! $this->handling()) {
                return;
            }
            $this->nativeToggled($on);
            $this->session()->post(new Toggled($this->window->name(), $this->path(), $this->uuid, $on));
        });
    }

    public function native(): QCheckBox
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

    protected function applyChecked(bool $checked): void
    {
        $this->applying(fn () => $this->native()->setChecked($checked));
    }
}
