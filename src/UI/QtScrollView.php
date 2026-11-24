<?php

namespace Jovian\Toolkits\Qt\UI;

use QScrollArea;
use QSizePolicy\Policy;
use Qt\ScrollBarPolicy;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Toolkits\UI\TKScrollView;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QScrollArea whose widget follows the viewport.
 */
class QtScrollView extends TKScrollView
{
    use QtNative;

    protected function bootstrapNative(): void
    {
        $area = new QScrollArea();
        $area->setWidgetResizable(true);
        $this->adoptNative($area);
        // Its frame is a panel's; the others draw none.
        $this->declare('border', 'none');
        $this->applyScrollbars($this->scrollsAcross(), $this->scrollsDown());
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $this->areaNative()->setWidget($child->native());
        if ($child->isVisible()) {
            $child->native()->show();
        }
        $this->tellExpansion();
    }

    /** The child's own release takes it off the area. */
    protected function detachNative(TKPrimitive $child): void
    {
        $this->tellExpansion();
    }

    protected function applyScrollbars(bool $horizontal, bool $vertical): void
    {
        $area = $this->areaNative();
        $area->setHorizontalScrollBarPolicy($horizontal ? ScrollBarPolicy::AS_NEEDED : ScrollBarPolicy::ALWAYS_OFF);
        $area->setVerticalScrollBarPolicy($vertical ? ScrollBarPolicy::AS_NEEDED : ScrollBarPolicy::ALWAYS_OFF);
    }

    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->tellExpansion();
    }

    protected function childVisibilityChanged(TKPrimitive $child): void
    {
        $this->tellExpansion();
    }

    /**
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::PREFERRED, Policy::PREFERRED];
    }

    private function areaNative(): QScrollArea
    {
        /** @var QScrollArea */
        return $this->native();
    }
}
