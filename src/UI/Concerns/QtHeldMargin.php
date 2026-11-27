<?php

namespace Jovian\Toolkits\Qt\UI\Concerns;

use QWidget;
use QHBoxLayout;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Insets;
use Jovian\Toolkits\Qt\UI\QtKeepsOwnMargin;

/**
 * A child's margin as its parent keeps it. Qt layouts keep no space round a child of their
 * own, so a layout holds a child with a margin in a nested QHBoxLayout whose contents margins
 * are that margin, the child in it with its alignment: the holder's size hint is the child's
 * and the margin's. Qt keeps a holder's contents margins after its child is hidden, so a
 * hidden child's holder has none: its margin takes no room either (holdMargin() again on
 * every change of visibility). The kinds that keep their own (QtKeepsOwnMargin) are held by
 * nothing.
 *
 * A tab view, split view and scroll view take a page, a pane and their content as one widget,
 * with no layout round it: each child there is always in a holder widget of its own
 * (marginWidget()), whose layout's contents margins are its margin, none when it has none. A
 * change of margin is then the holder's alone, with no widget to swap in the container.
 */
trait QtHeldMargin
{
    /**
     * The margin this parent keeps round $child: none for a kind that keeps its own.
     */
    private static function heldMarginOf(TKPrimitive $child): Insets
    {
        return $child instanceof QtKeepsOwnMargin ? Insets::none() : $child->margin();
    }

    /**
     * A holder for $child: its margin as contents margins, the child in it with $alignment.
     */
    private static function marginHolder(TKPrimitive $child, int $alignment): QHBoxLayout
    {
        $holder = new QHBoxLayout();
        $holder->setSpacing(0);
        self::holdMargin($holder, $child);
        $holder->addWidget($child->native(), 0, $alignment);

        return $holder;
    }

    /**
     * A plain widget holding $child alone, filling it, in a QHBoxLayout whose contents
     * margins are the child's margin: what a tab view, split view or scroll view takes as the
     * child's page, pane or content.
     *
     * @return array{QWidget, QHBoxLayout}
     */
    private static function marginWidget(TKPrimitive $child): array
    {
        $widget = new QWidget();
        $holder = new QHBoxLayout($widget);
        $holder->setSpacing(0);
        self::holdMargin($holder, $child);
        $holder->addWidget($child->native());

        return [$widget, $holder];
    }

    /**
     * The holder's contents margins: the child's margin while it is shown, none while hidden.
     */
    private static function holdMargin(QHBoxLayout $holder, TKPrimitive $child): void
    {
        $margin = $child->isVisible() ? self::heldMarginOf($child) : Insets::none();
        $holder->setContentsMargins($margin->left, $margin->top, $margin->right, $margin->bottom);
    }
}
