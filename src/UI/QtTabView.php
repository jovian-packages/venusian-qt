<?php

namespace Jovian\Toolkits\Qt\UI;

use QObject;
use QWidget;
use QTabWidget;
use QHBoxLayout;
use WeakReference;
use Surface\Toolkits\UI\TKTabView;
use Surface\Toolkits\UI\TKPrimitive;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\QtHeldMargin;

/**
 * A QTabWidget. currentChanged(int) comes for what code does too; that is inside write(). Each
 * page is a holder widget with the child in it, in from the child's margin (QtHeldMargin).
 */
class QtTabView extends TKTabView
{
    use QtNative;
    use QtHeldMargin;

    /** @var array<string, array{QWidget, QHBoxLayout}> each child's page and its layout, by uuid */
    private array $pages = [];

    protected function bootstrapNative(): void
    {
        $tabs = new QTabWidget();
        $this->adoptNative($tabs);

        $self = WeakReference::create($this);
        QObject::connect($tabs, 'currentChanged(int)', static function (int $index) use ($self): void {
            $self->get()?->chosen($index);
        });
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $this->pages[$child->uuid()] = self::marginWidget($child);
        $this->tabsNative()->addTab($this->pageOf($child), self::plain($this->title($child->name())));
        $this->tellExpansion();
    }

    /**
     * The tab and its page go; the child's own release takes it out of the page.
     */
    protected function detachNative(TKPrimitive $child): void
    {
        $page = $this->pageOf($child);
        $index = $this->tabsNative()->indexOf($page);
        if ($index >= 0) {
            $this->tabsNative()->removeTab($index);
        }
        unset($this->pages[$child->uuid()]);
        $page->deleteLater();
        $this->tellExpansion();
    }

    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $tabs = $this->tabsNative();
        $tabs->removeTab($tabs->indexOf($this->pageOf($child)));
        $tabs->insertTab($index, $this->pageOf($child), self::plain($this->title($child->name())));
    }

    protected function applyTitle(TKPrimitive $page, string $title): void
    {
        $this->tabsNative()->setTabText($this->tabsNative()->indexOf($this->pageOf($page)), self::plain($title));
    }

    protected function applySelected(TKPrimitive $page): void
    {
        $this->tabsNative()->setCurrentIndex($this->tabsNative()->indexOf($this->pageOf($page)));
    }

    /**
     * The page keeps the child's margin as it is now.
     */
    protected function childLayoutChanged(TKPrimitive $child): void
    {
        self::holdMargin($this->pages[$child->uuid()][1], $child);
        $this->tellExpansion();
    }

    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        self::holdMargin($this->pages[$child->uuid()][1], $child);
    }

    private function pageOf(TKPrimitive $child): QWidget
    {
        return $this->pages[$child->uuid()][0];
    }

    /**
     * A tab bar reads & as a mnemonic marker; doubled, it shows as itself.
     */
    private static function plain(string $title): string
    {
        return str_replace('&', '&&', $title);
    }

    private function tabsNative(): QTabWidget
    {
        /** @var QTabWidget */
        return $this->native();
    }
}
