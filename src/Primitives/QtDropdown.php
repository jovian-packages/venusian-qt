<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QComboBox;
use QObject;
use QSizePolicy\Policy;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKDropdown;
use Surface\Windows\Primitives\TKPrimitiveGroup;

class QtDropdown extends TKDropdown implements QtNative
{
    use QtPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $options, int $selected)
    {
        parent::__construct($name, $window, $parent, $placement, $options, $selected);
        $combo = $this->adoptNative(new QComboBox());
        $combo->addItems($this->options);
        $combo->setCurrentIndex($this->selected);

        // clear() and the first addItems() also emit this; they run under the applying flag.
        QObject::connect($combo, 'currentIndexChanged(int)', function (int $index): void {
            if (! $this->handling() || $index < 0) {
                return;
            }
            $this->nativeSelected($index);
            $this->session()->post(new SelectionChanged($this->window->name(), $this->path(), $this->uuid, $index, $this->options[$index] ?? null));
        });
    }

    public function native(): QComboBox
    {
        return $this->native;
    }

    protected function naturalPolicy(): array
    {
        return [Policy::PREFERRED, Policy::FIXED];
    }

    protected function applyOptions(array $options): void
    {
        $this->applying(function () use ($options): void {
            $this->native()->clear();
            $this->native()->addItems($options);
        });
    }

    protected function applySelected(int $index): void
    {
        $this->applying(fn () => $this->native()->setCurrentIndex($index));
    }
}
