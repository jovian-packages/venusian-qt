<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QHBoxLayout;
use QObject;
use QSizePolicy\Policy;
use QToolButton;
use QWidget;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKSegmentedControl;

/**
 * Qt has no segmented control: a QWidget whose zero-spacing QHBoxLayout holds one checkable,
 * auto-exclusive QToolButton per segment, so Qt keeps one checked. A button the user checks
 * posts SelectionChanged.
 */
class QtSegmentedControl extends TKSegmentedControl implements QtNative
{
    use QtPrimitive;

    protected QHBoxLayout $layout;

    /**
     * @var list<QToolButton>
     */
    protected array $buttons = [];

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $segments, int $selected)
    {
        parent::__construct($name, $window, $parent, $placement, $segments, $selected);
        $bar = $this->adoptNative(new QWidget());
        $this->layout = new QHBoxLayout($bar);
        $this->layout->setSpacing(0);
        $this->layout->setContentsMargins(0, 0, 0, 0);
        // The stretches either side keep the segments together, centred in a wider bar.
        $this->layout->addStretch();
        $this->layout->addStretch();
        $this->applySegments($this->segments);
        $this->applySelected($this->selected);
    }

    public function native(): QWidget
    {
        return $this->native;
    }

    /**
     * @return list<QToolButton>
     */
    public function buttons(): array
    {
        return $this->buttons;
    }

    protected function naturalPolicy(): array
    {
        return [Policy::PREFERRED, Policy::FIXED];
    }

    protected function applySegments(array $segments): void
    {
        $this->applying(function () use ($segments): void {
            foreach ($this->buttons as $button) {
                $button->setParent(null);
                $button->deleteLater();
            }
            $this->buttons = [];
            foreach ($segments as $index => $label) {
                $button = new QToolButton($this->native);
                $button->setText(self::literal($label));
                $button->setCheckable(true);
                $button->setAutoExclusive(true);
                QObject::connect($button, 'toggled(bool)', function (bool $checked) use ($index): void {
                    $this->toggled($index, $checked);
                });
                $this->layout->insertWidget($index + 1, $button);
                $this->buttons[] = $button;
            }
        });
    }

    protected function applySegment(int $index, string $label): void
    {
        $this->buttons[$index]->setText(self::literal($label));
    }

    protected function applySelected(int $index): void
    {
        if (isset($this->buttons[$index])) {
            $this->applying(fn () => $this->buttons[$index]->setChecked(true));
        }
    }

    protected function toggled(int $index, bool $checked): void
    {
        if (! $this->handling() || ! $checked || $index === $this->selected) {
            return;
        }
        $this->nativeSelected($index);
        $this->session()->post(new SelectionChanged($this->window->name(), $this->path(), $this->uuid, $index, $this->segments[$index]));
    }
}
