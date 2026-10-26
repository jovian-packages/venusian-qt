<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QObject;
use QScrollBar;
use QSizePolicy\Policy;
use Qt\Orientation;
use Surface\Contracts\Windows\Mail\View\ScrollChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKScrollBar;

/**
 * A standalone QScrollBar: range 0..max(0, maximum − page), page step the page, single step the
 * step (Qt's wheel moves single step × the platform's wheel lines). valueChanged(int) outside the
 * applying guard is the user's (a drag, a track click, a wheel, a key): it posts ScrollChanged
 * latest-wins.
 */
class QtScrollBar extends TKScrollBar implements QtNative
{
    use QtPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, bool $vertical)
    {
        parent::__construct($name, $window, $parent, $placement, $vertical);
        $bar = $this->adoptNative(new QScrollBar($vertical ? Orientation::VERTICAL : Orientation::HORIZONTAL));
        $this->applying(function () use ($bar): void {
            $bar->setRange(0, 0);
            $bar->setSingleStep($this->step);
        });

        QObject::connect($bar, 'valueChanged(int)', function (int $value): void {
            if (! $this->handling()) {
                return;
            }
            $this->nativeMoved($value);
            $this->session()->postLatest(
                "view.scroll-changed.{$this->window->name()}.{$this->path()}",
                new ScrollChanged($this->window->name(), $this->path(), $this->uuid, $this->value),
            );
        });
    }

    public function native(): QScrollBar
    {
        return $this->native;
    }

    protected function naturalPolicy(): array
    {
        return $this->vertical ? [Policy::FIXED, Policy::EXPANDING] : [Policy::EXPANDING, Policy::FIXED];
    }

    protected function applyRange(int $maximum, int $page, int $value): void
    {
        $this->applying(function () use ($maximum, $page, $value): void {
            $this->native()->setRange(0, max(0, $maximum - $page));
            $this->native()->setPageStep($page);
            $this->native()->setValue($value);
        });
    }

    protected function applyStep(int $line): void
    {
        $this->applying(fn () => $this->native()->setSingleStep($line));
    }

    protected function applyValue(int $value): void
    {
        $this->applying(fn () => $this->native()->setValue($value));
    }
}
