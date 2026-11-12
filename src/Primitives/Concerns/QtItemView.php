<?php

namespace Jovian\Toolkits\Qt\Primitives\Concerns;

use QApplication;
use QColor;
use QEvent;
use QFont;
use QFontDatabase;
use QFontDatabase\SystemFont;
use QFontMetricsF;
use QListView;
use QModelIndex;
use QMouseEvent;
use QObject;
use QPaintDelegate;
use QPainter;
use QPalette\ColorRole;
use QPixmap;
use QRect;
use QStandardItemModel;
use QStyleOptionViewItem;
use Qt\AspectRatioMode;
use Qt\TransformationMode;
use Surface\Contracts\Windows\Mail\View\ItemActionClicked;
use Surface\Contracts\Windows\Mail\View\ItemActivated;
use Surface\Contracts\Windows\Mail\View\ViewScrolled;
use Surface\Contracts\Windows\Styling\ScrollPosition;

/**
 * An item view's model, painting and scrolling: a QListView over a QStandardItemModel of one
 * row per item, whose QPaintDelegate paints each row in view from the items (Qt asks only for
 * rows it shows) and hit-tests the action on a mouse release. Pictures are cropped to cover
 * their box once and kept in a small cache. The vertical scroll bar posts ViewScrolled
 * (latest-only) while watched.
 */
trait QtItemView
{
    protected QStandardItemModel $model;

    protected QPaintDelegate $delegate;

    /** QStyle::State_MouseOver and State_Selected, Qt's fixed flag values. */
    protected const int HOVERED = 0x2000;

    protected const int SELECTED = 0x8000;

    /** Qt::TextWordWrap, a Qt::TextFlag. */
    protected const int WORD_WRAP = 0x1000;

    protected const int PIXMAPS = 256;

    /**
     * Cropped pictures by "file|width|height", oldest first.
     * @var array<string, QPixmap|null>
     */
    protected array $pixmaps = [];

    protected function buildView(QListView $view): QListView
    {
        $this->model = new QStandardItemModel(count($this->items), 1);
        $this->delegate = new QPaintDelegate(
            fn (QPainter $painter, QStyleOptionViewItem $option, QModelIndex $index) => $this->paintItem($painter, $option->rect(), $option->state(), $index->row()),
            fn (QStyleOptionViewItem $option, QModelIndex $index) => $this->itemSize(),
            fn (QEvent $event, object $model, QStyleOptionViewItem $option, QModelIndex $index): bool => $this->itemEvent($event, $option->rect(), $index->row()),
        );
        $view->setModel($this->model);
        $view->setItemDelegate($this->delegate);
        $view->setUniformItemSizes(true);
        $view->setMovement(QListView\Movement::STATIC);
        $view->setEditTriggers(\QAbstractItemView::NO_EDIT_TRIGGERS);
        $view->setAttribute(\Qt\WidgetAttribute::HOVER);
        QObject::connect($view->verticalScrollBar(), 'valueChanged(int)', fn () => $this->scrolled());

        return $this->adoptNative($view);
    }

    public function model(): QStandardItemModel
    {
        return $this->model;
    }

    protected function applyItems(array $items): void
    {
        $this->applying(function () use ($items): void {
            $this->model->setRowCount(0);
            $this->model->setRowCount(count($items));
        });
    }

    protected function applyInserted(int $at, array $items): void
    {
        $this->model->insertRows($at, count($items));
    }

    protected function applyRemoved(int $at, int $count): void
    {
        $this->model->removeRows($at, $count);
    }

    /** The row's data changes, so the view repaints it. */
    protected function applyUpdated(int $index, object $item): void
    {
        $this->model->setData($this->model->index($index, 0), spl_object_id($item));
    }

    protected function nativeScrollPosition(): ScrollPosition
    {
        $horizontal = $this->native()->horizontalScrollBar();
        $vertical = $this->native()->verticalScrollBar();

        return ScrollPosition::of(
            $horizontal->value(), $horizontal->pageStep(), $horizontal->maximum() + $horizontal->pageStep(),
            $vertical->value(), $vertical->pageStep(), $vertical->maximum() + $vertical->pageStep(),
        );
    }

    protected function applyScrollTo(float $x, float $y): void
    {
        $this->applying(function () use ($x, $y): void {
            foreach ([[$this->native()->horizontalScrollBar(), $x], [$this->native()->verticalScrollBar(), $y]] as [$bar, $fraction]) {
                $page = $bar->pageStep();
                $bar->setValue((int) round(ScrollPosition::offset($fraction, $page, $bar->maximum() + $page)));
            }
        });
    }

    protected function scrolled(): void
    {
        if (! $this->handling() || ! $this->watching_scroll) {
            return;
        }
        $this->session()->postLatest(
            "view.scrolled.{$this->window->name()}.{$this->path()}",
            new ViewScrolled($this->window->name(), $this->path(), $this->uuid, $this->nativeScrollPosition()),
        );
    }

    protected function activated(int $row): void
    {
        if ($this->handling() && $this->enabled($row)) {
            $this->session()->post(new ItemActivated($this->window->name(), $this->path(), $this->uuid, $row));
        }
    }

    /** A left-button release on the row's action posts ItemActionClicked and is taken. */
    protected function itemEvent(QEvent $event, QRect $rect, int $row): bool
    {
        if (! $event instanceof QMouseEvent || $event->type() !== QEvent\Type::MOUSE_BUTTON_RELEASE || ! $this->enabled($row)) {
            return false;
        }
        $action = $this->actionRect($rect, $this->items[$row]);
        $at = $event->position();
        if (is_null($action) || $at->x() < $action->x() || $at->x() > $action->x() + $action->width() || $at->y() < $action->y() || $at->y() > $action->y() + $action->height()) {
            return false;
        }
        $this->session()->post(new ItemActionClicked($this->window->name(), $this->path(), $this->uuid, $row));

        return true;
    }

    /**
     * $file cropped to cover $width × $height, or null when it cannot be read.
     */
    protected function cover(?string $file, int $width, int $height): ?QPixmap
    {
        if (is_null($file) || $width < 1 || $height < 1) {
            return null;
        }
        $key = "{$file}|{$width}|{$height}";
        if (array_key_exists($key, $this->pixmaps)) {
            $pixmap = $this->pixmaps[$key];
            unset($this->pixmaps[$key]);

            return $this->pixmaps[$key] = $pixmap;
        }
        $source = new QPixmap();
        $pixmap = null;
        if ($source->load($file)) {
            $scaled = $source->scaled($width, $height, AspectRatioMode::KEEP_ASPECT_RATIO_BY_EXPANDING, TransformationMode::SMOOTH_TRANSFORMATION);
            $pixmap = $scaled->copy(new QRect(intdiv($scaled->width() - $width, 2), intdiv($scaled->height() - $height, 2), $width, $height));
        }
        if (count($this->pixmaps) >= self::PIXMAPS) {
            unset($this->pixmaps[array_key_first($this->pixmaps)]);
        }

        return $this->pixmaps[$key] = $pixmap;
    }

    protected static function color(ColorRole $role): QColor
    {
        return QApplication::palette()->color($role);
    }

    protected static function font(float $size, int $weight): QFont
    {
        return new QFont(QFontDatabase::systemFont(SystemFont::GENERAL_FONT)->family(), (int) round($size), $weight);
    }

    /** $text cut to fit $width with a trailing ellipsis, measured in $font. */
    protected static function elide(string $text, QFont $font, float $width): string
    {
        $metrics = new QFontMetricsF($font);
        if ($metrics->horizontalAdvance($text) <= $width) {
            return $text;
        }
        $chars = mb_str_split($text);
        [$low, $high] = [0, count($chars)];
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            $metrics->horizontalAdvance(implode('', array_slice($chars, 0, $mid)).'…') <= $width ? $low = $mid : $high = $mid - 1;
        }

        return rtrim(implode('', array_slice($chars, 0, $low))).'…';
    }
}
