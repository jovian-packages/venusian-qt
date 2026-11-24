<?php

namespace Jovian\Toolkits\Qt\UI;

use QObject;
use QTabWidget;
use WeakReference;
use Surface\Toolkits\UI\TKTabView;
use Surface\Toolkits\UI\TKPrimitive;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QTabWidget. currentChanged(int) comes for what code does too; that is inside write().
 */
class QtTabView extends TKTabView
{
    use QtNative;

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
        $this->tabsNative()->addTab($child->native(), self::plain($this->title($child->name())));
        $this->tellExpansion();
    }

    protected function detachNative(TKPrimitive $child): void
    {
        $index = $this->tabsNative()->indexOf($child->native());
        if ($index >= 0) {
            $this->tabsNative()->removeTab($index);
        }
        $this->tellExpansion();
    }

    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $tabs = $this->tabsNative();
        $tabs->removeTab($tabs->indexOf($child->native()));
        $tabs->insertTab($index, $child->native(), self::plain($this->title($child->name())));
    }

    protected function applyTitle(TKPrimitive $page, string $title): void
    {
        $this->tabsNative()->setTabText($this->tabsNative()->indexOf($page->native()), self::plain($title));
    }

    protected function applySelected(TKPrimitive $page): void
    {
        $this->tabsNative()->setCurrentIndex($this->tabsNative()->indexOf($page->native()));
    }

    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->tellExpansion();
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
