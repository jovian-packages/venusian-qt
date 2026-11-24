<?php

namespace Jovian\Toolkits\Qt\UI;

use QRect;
use QSize;
use QBrush;
use QObject;
use QPainter;
use QListView;
use QModelIndex;
use QFontMetricsF;
use WeakReference;
use QFont\Weight;
use Qt\AlignmentFlag;
use QPalette\ColorRole;
use Surface\Toolkits\UI\TKListView;
use Surface\Contracts\Toolkits\UI\ListRow;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\QtItemView;

/**
 * A QListView painted by a delegate: image and badge, title and icons, detail, action.
 */
class QtListView extends TKListView
{
    use QtNative;
    use QtItemView;

    protected function bootstrapNative(): void
    {
        $view = new QListView();
        $this->buildView($view);
        $view->setViewMode(QListView\ViewMode::LIST_MODE);
        $self = WeakReference::create($this);
        QObject::connect($view, 'activated(QModelIndex)', static function (QModelIndex $index) use ($self): void {
            $self->get()?->activated($index->row());
        });
    }

    /** The view asks the delegate again on its next layout. */
    protected function applyRowHeight(int $height): void
    {
        $this->write(function (): void {
            $count = $this->model->rowCount();
            $this->model->setRowCount(0);
            $this->model->setRowCount($count);
        });
    }

    protected function itemSize(): QSize
    {
        return new QSize(200, $this->row_height);
    }

    protected function paintItem(QPainter $painter, QRect $rect, int $state, int $row): void
    {
        $item = $this->items[$row] ?? null;
        if (! $item instanceof ListRow) {
            return;
        }
        $painter->save();
        if (! $item->enabled) {
            $painter->setOpacity(0.5);
        } elseif (($state & (self::HOVERED | self::SELECTED)) !== 0) {
            $painter->setOpacity(($state & self::SELECTED) !== 0 ? 0.3 : 0.12);
            $painter->fillRect($rect, self::color(ColorRole::HIGHLIGHT));
            $painter->setOpacity(1.0);
        }
        [$x, $y, $width, $height] = [$rect->x(), $rect->y(), $rect->width(), $rect->height()];

        $imageHeight = max(1, $height - 16);
        $imageWidth = (int) round($imageHeight * 1.5);
        $box = new QRect($x + 8, $y + 8, $imageWidth, $imageHeight);
        $picture = $this->cover($item->image, $imageWidth, $imageHeight);
        if (! is_null($picture)) {
            $painter->drawPixmap($box, $picture);
        }

        if (! is_null($item->badge)) {
            $badgeFont = self::font(9.0, Weight::BOLD->value);
            $badgeWidth = (int) ceil((new QFontMetricsF($badgeFont))->horizontalAdvance($item->badge)) + 10;
            $badge = new QRect($x + 4, $y + 4, $badgeWidth, 16);
            $painter->setPen(self::color(ColorRole::HIGHLIGHT));
            $painter->setBrush(new QBrush(self::color(ColorRole::HIGHLIGHT)));
            $painter->drawRoundedRect($badge, 4.0, 4.0);
            $painter->setFont($badgeFont);
            $painter->setPen(self::color(ColorRole::HIGHLIGHTED_TEXT));
            $painter->drawText($badge, AlignmentFlag::CENTER->value, $item->badge);
        }

        $left = $x + 8 + $imageWidth + 12;
        $right = $x + $width - 12;
        $iconsWidth = count($item->icons) * 18;
        foreach ($item->icons as $i => $file) {
            $icon = $this->cover($file, 14, 14);
            if (! is_null($icon)) {
                $painter->drawPixmap(new QRect($right - $iconsWidth + $i * 18 + 4, $y + 11, 14, 14), $icon);
            }
        }
        $titleFont = self::font(13.0, Weight::DEMI_BOLD->value);
        $painter->setFont($titleFont);
        $painter->setPen(self::color(ColorRole::TEXT));
        $titleWidth = max(0, $right - $iconsWidth - $left - 6);
        $painter->drawText(new QRect($left, $y + 8, $titleWidth, 20), AlignmentFlag::LEFT->value | AlignmentFlag::V_CENTER->value, self::elide($item->title, $titleFont, $titleWidth));

        $action = $this->actionRect($rect, $item);
        // Beside the action, not squeezed above it.
        $detailBottom = $y + $height - 6;
        $detailRight = is_null($action) ? $right : $action->x() - 8;
        $painter->setFont(self::font(11.0, Weight::NORMAL->value));
        $painter->setOpacity($item->enabled ? 0.65 : 0.33);
        $painter->drawText(new QRect($left, $y + 30, max(0, $detailRight - $left), max(0, $detailBottom - $y - 30)), AlignmentFlag::LEFT->value | AlignmentFlag::TOP->value | self::WORD_WRAP, $item->detail);
        $painter->setOpacity($item->enabled ? 1.0 : 0.5);

        if (! is_null($action)) {
            $painter->setPen(self::color(ColorRole::MID));
            $painter->setBrush(new QBrush(self::color(ColorRole::BUTTON)));
            $painter->drawRoundedRect($action, 6.0, 6.0);
            $painter->setFont(self::font(12.0, Weight::NORMAL->value));
            $painter->setPen(self::color(ColorRole::BUTTON_TEXT));
            $painter->drawText($action, AlignmentFlag::CENTER->value, $item->action);
        }
        $painter->restore();
    }

    protected function actionRect(QRect $rect, object $item): ?QRect
    {
        if (! $item instanceof ListRow || is_null($item->action)) {
            return null;
        }
        $width = (int) ceil((new QFontMetricsF(self::font(12.0, Weight::NORMAL->value)))->horizontalAdvance($item->action)) + 24;

        return new QRect($rect->x() + $rect->width() - 12 - $width, $rect->y() + $rect->height() - 8 - 24, $width, 24);
    }
}
