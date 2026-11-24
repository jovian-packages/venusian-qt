<?php

namespace Jovian\Toolkits\Qt\UI;

use QWidget;
use QGridLayout;
use QSpacerItem;
use QProgressBar;
use QSizePolicy\Policy;
use Surface\Toolkits\UI\TKSpinner;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * Qt has no spinner: a short indeterminate QProgressBar. A spacer under it keeps its place
 * while it is stopped, when it draws nothing as the other toolkits' do.
 */
class QtSpinner extends TKSpinner
{
    use QtNative;

    private const int WIDTH = 48;

    private const int HEIGHT = 16;

    private ?QProgressBar $bar = null;

    protected function bootstrapNative(): void
    {
        $host = new QWidget();
        $cell = new QGridLayout($host);
        $cell->setContentsMargins(0, 0, 0, 0);
        $cell->addItem(new QSpacerItem(self::WIDTH, self::HEIGHT, Policy::PREFERRED, Policy::FIXED), 0, 0);

        $this->bar = new QProgressBar();
        $this->bar->setTextVisible(false);
        $this->bar->setRange(0, 0);
        $this->bar->setSizePolicy(Policy::IGNORED, Policy::FIXED);
        $cell->addWidget($this->bar, 0, 0);

        $this->adoptNative($host);
        $this->applySpinning($this->isSpinning());
    }

    protected function applySpinning(bool $spinning): void
    {
        $this->bar->setVisible($spinning);
    }

    /**
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::PREFERRED, Policy::FIXED];
    }
}
