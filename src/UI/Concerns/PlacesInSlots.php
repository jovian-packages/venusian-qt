<?php

namespace Jovian\Toolkits\Qt\UI\Concerns;

use Qt\AlignmentFlag;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Align;

/**
 * A child's align() as a Qt layout takes it: for the box layouts and the grid.
 */
trait PlacesInSlots
{
    /**
     * FILL on an axis is no flag on it: the layout gives the child the slot's full width or height.
     */
    private static function alignmentOf(TKPrimitive $child): int
    {
        [$horizontal, $vertical] = $child->aligned();

        $h = match ($horizontal) {
            Align::START => AlignmentFlag::LEFT->value,
            Align::CENTER => AlignmentFlag::H_CENTER->value,
            Align::END => AlignmentFlag::RIGHT->value,
            Align::FILL => 0,
        };
        $v = match ($vertical) {
            Align::START => AlignmentFlag::TOP->value,
            Align::CENTER => AlignmentFlag::V_CENTER->value,
            Align::END => AlignmentFlag::BOTTOM->value,
            Align::FILL => 0,
        };

        return $h | $v;
    }
}
