<?php

namespace Jovian\Toolkits\Qt\UI\Concerns;

use QLabel;
use Surface\Contracts\Toolkits\UI\Insets;

/**
 * Margin as a style sheet `margin`, for the widgets whose sheet box Qt keeps natively: the
 * style sheet style paints the background inside the margin and adds the margin to the size
 * hint, so the layout leaves the space round the widget. Used only where that holds and the
 * widget keeps its own look under the sheet (label, link, check box, text input, progress bar;
 * see ui-primitives.md). Qt layouts keep no space round a child of their own, so the others
 * leave margin unshown.
 */
trait QtSheetMargin
{
    /**
     * A label under a sheet box has a frame, and Qt then indents its text by half an 'x' and
     * widens its size hint by a whole one: indent 0 while a margin is set, Qt's -1 after.
     */
    protected function applyMargin(Insets $margin): void
    {
        $this->declare('margin', $margin->isZero() ? null : "{$margin->top}px {$margin->right}px {$margin->bottom}px {$margin->left}px");

        if ($this->native instanceof QLabel) {
            $this->native->setIndent($margin->isZero() ? -1 : 0);
        }
    }
}
