<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtItemView;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QBrush;
use QColor;
use QFont\Weight;
use QListView;
use QModelIndex;
use QObject;
use QPainter;
use QPalette\ColorRole;
use QRect;
use QSize;
use QSizePolicy\Policy;
use Qt\AlignmentFlag;
use Surface\Contracts\Windows\Primitives\CollectionItem;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKCollectionView;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A QListView in icon mode, wrapping left to right in a grid the cell size plus a margin. The
 * delegate paints each cell: the picture covering the cell above a centred, elided caption, a
 * highlight under a hovered or selected cell, the action picture in the top-right corner, and the
 * status icons on dark discs left of it.
 * clicked(QModelIndex) posts ItemActivated; a release on the action posts ItemActionClicked.
 */
class QtCollectionView extends TKCollectionView implements QtNative
{
    use QtPrimitive;
    use QtItemView;

    protected const int MARGIN = 6;

    protected const int CAPTION = 22;

    protected const int ACTION = 26;

    /** A status icon's disc, in pixels. */
    protected const int DISC = 26;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $items)
    {
        parent::__construct($name, $window, $parent, $placement, $items);
        $view = $this->buildView(new QListView());
        $view->setViewMode(QListView\ViewMode::ICON_MODE);
        $view->setFlow(QListView\Flow::LEFT_TO_RIGHT);
        $view->setWrapping(true);
        $view->setResizeMode(QListView\ResizeMode::ADJUST);
        $view->setGridSize($this->itemSize());
        QObject::connect($view, 'clicked(QModelIndex)', fn (QModelIndex $index) => $this->activated($index->row()));
    }

    public function native(): QListView
    {
        return $this->native;
    }

    protected function naturalPolicy(): array
    {
        return [Policy::EXPANDING, Policy::EXPANDING];
    }

    protected function applyCellSize(int $width, int $height): void
    {
        $this->native()->setGridSize($this->itemSize());
    }

    protected function itemSize(): QSize
    {
        return new QSize($this->cell_width + 2 * self::MARGIN, $this->cell_height + 2 * self::MARGIN);
    }

    protected function paintItem(QPainter $painter, QRect $rect, int $state, int $row): void
    {
        $item = $this->items[$row] ?? null;
        if (! $item instanceof CollectionItem) {
            return;
        }
        $painter->save();
        [$x, $y] = [$rect->x() + self::MARGIN, $rect->y() + self::MARGIN];
        if (($state & (self::HOVERED | self::SELECTED)) !== 0) {
            $painter->setOpacity(($state & self::SELECTED) !== 0 ? 0.35 : 0.18);
            $painter->fillRect(new QRect($rect->x() + 2, $rect->y() + 2, $rect->width() - 4, $rect->height() - 4), self::color(ColorRole::HIGHLIGHT));
            $painter->setOpacity(1.0);
        }
        $imageHeight = max(1, $this->cell_height - self::CAPTION);
        $picture = $this->cover($item->image, $this->cell_width, $imageHeight);
        $box = new QRect($x, $y, $this->cell_width, $imageHeight);
        if (! is_null($picture)) {
            $painter->drawPixmap($box, $picture);
        }

        $font = self::font(12.0, Weight::NORMAL->value);
        $painter->setFont($font);
        $painter->setPen(self::color(ColorRole::TEXT));
        $painter->drawText(new QRect($x, $y + $imageHeight, $this->cell_width, self::CAPTION), AlignmentFlag::CENTER->value, self::elide($item->caption, $font, $this->cell_width - 4));

        // Status icons: each on a dark disc with a bright rim, so it reads over any picture.
        $painter->setRenderHint(QPainter\RenderHint::ANTIALIASING);
        foreach ($this->statusRects($rect, $item) as $i => $disc) {
            $painter->setPen(new QColor(255, 255, 255, 178));
            $painter->setBrush(new QBrush(new QColor(0, 0, 0, 140)));
            $painter->drawRoundedRect($disc, self::DISC / 2, self::DISC / 2);
            $glyph = $this->cover($item->icons[$i], 16, 16);
            is_null($glyph) || $painter->drawPixmap(new QRect($disc->x() + (self::DISC - 16) / 2, $disc->y() + (self::DISC - 16) / 2, 16, 16), $glyph);
        }

        $action = $this->actionRect($rect, $item);
        $icon = is_null($action) ? null : $this->cover($item->action, self::ACTION, self::ACTION);
        if (! is_null($icon)) {
            $painter->drawPixmap($action, $icon);
        }
        $painter->restore();
    }

    /**
     * The status icons' discs, right to left from the action button (or the corner without one).
     * @return list<QRect> in the icons' order
     */
    protected function statusRects(QRect $rect, CollectionItem $item): array
    {
        $right = $rect->x() + self::MARGIN + $this->cell_width - 6 - (is_null($item->action) ? 0 : self::ACTION + 4);
        $left = $right - count($item->icons) * (self::DISC + 4) + 4;

        return array_map(fn (int $i): QRect => new QRect($left + $i * (self::DISC + 4), $rect->y() + self::MARGIN + 6, self::DISC, self::DISC), array_keys($item->icons));
    }

    protected function actionRect(QRect $rect, object $item): ?QRect
    {
        return $item instanceof CollectionItem && ! is_null($item->action)
            ? new QRect($rect->x() + self::MARGIN + $this->cell_width - self::ACTION - 6, $rect->y() + self::MARGIN + 6, self::ACTION, self::ACTION)
            : null;
    }
}
