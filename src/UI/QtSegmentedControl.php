<?php

namespace Jovian\Toolkits\Qt\UI;

use QObject;
use QWidget;
use QHBoxLayout;
use QToolButton;
use WeakReference;
use QSizePolicy\Policy;
use Surface\Toolkits\UI\TKSegmentedControl;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * Exclusive QToolButtons side by side: Qt has no segmented control. Stretches either side
 * keep them together in a wider slot.
 */
class QtSegmentedControl extends TKSegmentedControl
{
    use QtNative;

    private ?QHBoxLayout $segments_layout = null;

    /** @var list<QToolButton> */
    private array $buttons = [];

    protected function bootstrapNative(): void
    {
        $bar = new QWidget();
        $this->segments_layout = new QHBoxLayout($bar);
        $this->segments_layout->setSpacing(0);
        $this->segments_layout->setContentsMargins(0, 0, 0, 0);
        $this->segments_layout->addStretch(1);
        $this->segments_layout->addStretch(1);
        $this->adoptNative($bar);
        $this->write(fn () => $this->applySegments($this->segments(), $this->selected()));
    }

    protected function applySegments(array $segments, int $selected): void
    {
        foreach ($this->buttons as $button) {
            $this->segments_layout->removeWidget($button);
            $button->setParent(null);
            $button->deleteLater();
        }
        $this->buttons = [];

        $self = WeakReference::create($this);
        foreach ($segments as $index => $label) {
            $button = new QToolButton($this->native());
            $button->setText(self::plain($label));
            $button->setCheckable(true);
            $button->setAutoExclusive(true);
            QObject::connect($button, 'toggled(bool)', static function (bool $on) use ($self, $index): void {
                if ($on) {
                    $self->get()?->chosen($index);
                }
            });
            $this->segments_layout->insertWidget($index + 1, $button);
            // A child made after its parent was shown stays hidden until told.
            $button->setVisible(true);
            $this->buttons[] = $button;
        }

        if ($selected >= 0) {
            $this->applySelected($selected);
        }
    }

    protected function applySegment(int $index, string $label): void
    {
        $this->buttons[$index]->setText(self::plain($label));
    }

    protected function applySelected(int $index): void
    {
        $this->buttons[$index]->setChecked(true);
    }

    /**
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::PREFERRED, Policy::FIXED];
    }

    /**
     * QAbstractButton reads & as a mnemonic marker; doubled, it shows as itself.
     */
    private static function plain(string $label): string
    {
        return str_replace('&', '&&', $label);
    }
}
