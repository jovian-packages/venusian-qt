<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QProgressBar;
use QSizePolicy\Policy;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKProgressBar;

/**
 * A fraction is 0..1000 on the bar; null is Qt's busy bar (range 0..0).
 */
class QtProgressBar extends TKProgressBar implements QtNative
{
    use QtPrimitive;

    public const int STEPS = 1000;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, ?float $fraction)
    {
        parent::__construct($name, $window, $parent, $placement, $fraction);
        $this->adoptNative(new QProgressBar())->setTextVisible(false);
        $this->applyFraction($fraction);
    }

    public function native(): QProgressBar
    {
        return $this->native;
    }

    protected function naturalPolicy(): array
    {
        return [Policy::EXPANDING, Policy::FIXED];
    }

    protected function applyFraction(?float $fraction): void
    {
        if (is_null($fraction)) {
            $this->native()->setRange(0, 0);

            return;
        }

        $this->native()->setRange(0, self::STEPS);
        $this->native()->setValue((int) round($fraction * self::STEPS));
    }
}
