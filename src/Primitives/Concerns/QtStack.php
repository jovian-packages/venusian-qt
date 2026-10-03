<?php

namespace Jovian\Toolkits\Qt\Primitives\Concerns;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use QBoxLayout;
use QVBoxLayout;
use Surface\Contracts\Windows\Primitives\TKPrimitive as Child;

/**
 * Column and row: a QWidget carrying a box layout, children in display order, then a
 * stretch spacer. Spare space along the stack goes to the children that fill that axis
 * (stretch 1), or to the spacer when none does: an unfilled child keeps its size hint,
 * whatever its size policy would otherwise let Qt hand it.
 */
trait QtStack
{
    protected QBoxLayout $layout;

    /**
     * @param QBoxLayout $layout already installed on the native
     * @return void
     */
    protected function buildStack(QBoxLayout $layout): void
    {
        $this->layout = $layout;
        $layout->setSpacing($this->spacing);
        $layout->setContentsMargins($this->padding, $this->padding, $this->padding, $this->padding);
        $layout->addStretch(1);
    }

    public function placeChild(Child&QtNative $child): void
    {
        $this->layout->setAlignment($child->native(), $child->qtAlignment());
        $this->restretch();
    }

    public function forgetChild(Child $child): void
    {
        parent::forgetChild($child);
        $this->whileNativeAlive(fn () => $this->restretch());
    }

    protected function insertNative(Child $child): void
    {
        // Before the spacer, which stays last.
        $this->layout->insertWidget($this->layout->count() - 1, self::nativeOf($child), 0, $child instanceof QtNative ? $child->qtAlignment() : 0);
        $this->restretch();
    }

    protected function applySpacing(int $spacing): void
    {
        $this->layout->setSpacing($spacing);
    }

    protected function applyOrder(Child $child, int $index): void
    {
        $native = self::nativeOf($child);
        $this->layout->removeWidget($native);
        $this->layout->insertWidget($index, $native, 0, $child instanceof QtNative ? $child->qtAlignment() : 0);
        $this->restretch();
    }

    /**
     * Stretch 1 for each child that fills the stack's axis, 0 for the rest; the spacer takes
     * the spare space only when no child fills.
     * @return void
     */
    protected function restretch(): void
    {
        $axis = $this->layout instanceof QVBoxLayout ? 1 : 0;
        $filling = false;

        foreach ($this->children as $child) {
            $fills = $child instanceof QtNative && $child->fills()[$axis];
            $filling = $filling || $fills;
            $index = $this->layout->indexOf(self::nativeOf($child));
            if ($index >= 0) {
                $this->layout->setStretch($index, $fills ? 1 : 0);
            }
        }

        $this->layout->setStretch($this->layout->count() - 1, $filling ? 0 : 1);
    }
}
