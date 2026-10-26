<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtContainer;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QObject;
use QSizePolicy\Policy;
use QTabWidget;
use QWidget;
use Surface\Contracts\Windows\Mail\View\TabSelected;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TKColumn;
use Surface\Contracts\Windows\Primitives\TKPrimitive as Child;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTabView;

/**
 * A QTabWidget with one tab per page. currentChanged fires for every change of page: Qt's own
 * when the first page is added, and when a removed page's widget leaves (its destroyNative()
 * reparents it, and the tab goes with it, before this view forgets the page), as well as the
 * user's. Only the user's post TabSelected.
 */
class QtTabView extends TKTabView implements QtContainer
{
    use QtPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $tabs = $this->adoptNative(new QTabWidget());

        QObject::connect($tabs, 'currentChanged(int)', function (int $index): void {
            if (! $this->handling() || $this->removingPage()) {
                return;
            }
            $page = $index < 0 ? null : $this->native()->widget($index);
            $name = is_null($page) ? null : $this->pageNamed($page);
            if (! is_null($name)) {
                $this->session()->post(new TabSelected($this->window->name(), $this->path(), $this->uuid, $name));
            }
        });
    }

    public function native(): QTabWidget
    {
        return $this->native;
    }

    /**
     * A page fills its tab; alignment has no slot to act in.
     * @param Child&QtNative $child
     * @return void
     */
    public function placeChild(Child&QtNative $child): void {}

    protected function naturalPolicy(): array
    {
        return [Policy::EXPANDING, Policy::EXPANDING];
    }

    protected function insertPage(TKColumn $page, string $title, ?int $index): void
    {
        $this->applying(fn () => is_null($index)
            ? $this->native()->addTab(self::nativeOf($page), self::literal($title))
            : $this->native()->insertTab($index, self::nativeOf($page), self::literal($title)));
    }

    protected function applyTitle(TKColumn $page, string $title): void
    {
        $this->native()->setTabText($this->native()->indexOf(self::nativeOf($page)), self::literal($title));
    }

    protected function applySelect(TKColumn $page): void
    {
        $index = $this->native()->indexOf(self::nativeOf($page));
        $this->applying(fn () => $this->native()->setCurrentIndex($index));
    }

    protected function nativeSelected(): ?string
    {
        $page = $this->native()->currentWidget();

        return is_null($page) ? null : $this->pageNamed($page);
    }

    /**
     * A page is between its remove() and this view forgetting it: its tab is leaving.
     * @return bool
     */
    protected function removingPage(): bool
    {
        foreach ($this->children as $page) {
            if ($page->isRemoved()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name of the page whose column is $widget.
     * @param QWidget $widget
     * @return string|null
     */
    protected function pageNamed(QWidget $widget): ?string
    {
        foreach ($this->children as $name => $page) {
            if (self::nativeOf($page) === $widget) {
                return $name;
            }
        }

        return null;
    }
}
