<?php

namespace Jovian\Toolkits\Qt\UI;

use QProgressBar;
use QSizePolicy\Policy;
use Surface\Toolkits\UI\TKProgressBar;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QProgressBar without its text. A range of nothing is Qt's "not known".
 */
class QtProgressBar extends TKProgressBar
{
    use QtNative;

    private const int STEPS = 1000;

    protected function bootstrapNative(): void
    {
        $bar = new QProgressBar();
        $bar->setTextVisible(false);
        $this->adoptNative($bar);
        $this->applyFraction($this->fraction());
    }

    protected function applyFraction(?float $fraction): void
    {
        $bar = $this->barNative();
        if (is_null($fraction)) {
            $bar->setRange(0, 0);

            return;
        }

        $bar->setRange(0, self::STEPS);
        $bar->setValue((int) round($fraction * self::STEPS));
    }

    /**
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::PREFERRED, Policy::FIXED];
    }

    private function barNative(): QProgressBar
    {
        /** @var QProgressBar */
        return $this->native();
    }
}
