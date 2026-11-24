<?php

namespace Jovian\Toolkits\Qt\UI;

use QRect;
use QSize;
use QBrush;
use QColor;
use QObject;
use QPainter;
use QListView;
use QModelIndex;
use WeakReference;
use QFont\Weight;
use Qt\AlignmentFlag;
use QPalette\ColorRole;
use Surface\Toolkits\UI\TKCollectionView;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\QtItemView;
use Surface\Contracts\Toolkits\UI\CollectionItem;

/**
 * A QListView painted by a delegate: picture, caption, status discs, action, in as many columns as fit.
 */
class QtCollectionView extends TKCollectionView
{
    use QtNative;
    use QtItemView;

    protected const int MARGIN = 6;

    protected const int CAPTION = 22;

    protected const int ACTION = 26;

    /** A status icon's disc, in pixels. */
    protected const int DISC = 26;

    protected function bootstrapNative(): void
    {
        $view = new QListView();
        $this->buildView($view);
        $view->setViewMode(QListView\ViewMode::ICON_MODE);
        $view->setFlow(QListView\Flow::LEFT_TO_RIGHT);
        $view->setWrapping(true);
        $view->setResizeMode(QListView\ResizeMode::ADJUST);
        $view->setGridSize($this->itemSize());
        $self = WeakReference::create($this);
        QObject::connect($view, 'clicked(QModelIndex)', static function (QModelIndex $index) use ($self): void {
            $self->get()?->activated($index->row());
        });
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
