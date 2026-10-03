<?php

namespace Jovian\Toolkits\Qt\Contracts\Primitives;

use Surface\Contracts\Windows\Primitives\TKPrimitive;

/**
 * A Qt container: re-places a child after its align() changed.
 */
interface QtContainer extends QtNative
{
    /**
     * Apply $child's alignment inside this container's layout.
     * @param TKPrimitive&QtNative $child
     * @return void
     */
    public function placeChild(TKPrimitive&QtNative $child): void;
}
