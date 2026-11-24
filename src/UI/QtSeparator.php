<?php

namespace Jovian\Toolkits\Qt\UI;

use QFrame;
use QFrame\Shape;
use QFrame\Shadow;
use QSizePolicy\Policy;
use Surface\Toolkits\UI\TKSeparator;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QFrame line.
 */
class QtSeparator extends TKSeparator
{
    use QtNative;

    protected function bootstrapNative(): void
    {
        $frame = new QFrame();
        $frame->setFrameShape($this->isHorizontal() ? Shape::H_LINE : Shape::V_LINE);
        $frame->setFrameShadow(Shadow::SUNKEN);
        $this->adoptNative($frame);
    }

    /**
     * What QFrame sets for a line.
     *
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return $this->isHorizontal() ? [Policy::MINIMUM, Policy::FIXED] : [Policy::FIXED, Policy::MINIMUM];
    }
}
