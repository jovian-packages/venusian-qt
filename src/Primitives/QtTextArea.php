<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QBrush;
use QColor;
use QCoreApplication;
use QEvent;
use QEvent\Type as EventType;
use QEventFilter;
use QObject;
use QPlainTextEdit;
use QPlainTextEdit\LineWrapMode;
use QSizePolicy\Policy;
use Qt\TextInteractionFlag;
use QTextCharFormat;
use QTextCursor;
use QTextCursor\MoveMode;
use QTextCursor\MoveOperation;
use QTextEdit\ExtraSelection;
use QTextFormat;
use QTimer;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\ViewScrolled;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\ScrollPosition;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\NutsAndBolts\Color;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTextArea;
use WeakReference;

/**
 * A QPlainTextEdit. Line backgrounds and range colours are extra selections, painted over the
 * document without changing it or its undo stack: lines first, ranges on top. A line background
 * is a FullWidthSelection through the line's newline, which covers every row it wraps to. The
 * text's last line has no newline: it is a span to the start of its last row plus a collapsed
 * FullWidthSelection at the text's end, rebuilt whenever its rows change (the user's edits, the
 * wrap, the font, the viewport's width). Positions are UTF-16, through the abstract's TextLines.
 * The vertical scroll bar counts lines, the horizontal one pixels; ScrollPosition hides both.
 * Every code-driven write that can move a scroll bar (value, wrap, font, selection, scrolls)
 * runs marked as applying, so only the user's scrolls post ViewScrolled.
 */
class QtTextArea extends TKTextArea implements QtNative
{
    use QtPrimitive {
        applyFont as private styleFont;
    }

    /**
     * How many of the edit's extra selections, from the front, are line backgrounds; the rest are
     * range styles. A hook rebuilds only its own kind from the text as it is now and keeps the
     * other kind's selections as Qt holds them: Qt moves their cursors through the user's edits,
     * which the other kind's line and column numbers no longer describe.
     */
    private int $line_selections = 0;

    /** Where the text's last line starts, when it has a background: a cursor Qt moves through the user's edits. */
    private ?QTextCursor $tail_start = null;

    private ?QTextCharFormat $tail_format = null;

    /** How many of the line selections, at their end, are the last line's: none, one or two. */
    private int $tail_selections = 0;

    /** Whether a rebuild of the last line's selections waits for the viewport's resize to finish. */
    private bool $tail_pending = false;

    /** Sees the viewport resize: its width is the one the text wraps to. */
    private readonly QEventFilter $viewport_filter;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $value)
    {
        parent::__construct($name, $window, $parent, $placement, $value);
        $edit = $this->adoptNative(new QPlainTextEdit());
        $edit->setPlainText($this->value);

        QObject::connect($edit, 'textChanged()', function (): void {
            if (! $this->handling()) {
                return;
            }
            $text = $this->native()->toPlainText();
            $this->nativeValueChanged($text);
            $this->rebuildTail();
            $this->session()->post(new TextChanged($this->window->name(), $this->path(), $this->uuid, $text));
        });
        QObject::connect($edit->verticalScrollBar(), 'valueChanged(int)', fn () => $this->scrolled());
        QObject::connect($edit->horizontalScrollBar(), 'valueChanged(int)', fn () => $this->scrolled());

        // The filter sees the resize before the edit relays its text out to the new width: the
        // rebuild waits a turn of the event loop.
        $self = WeakReference::create($this);
        $this->viewport_filter = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            if ($event->type() === EventType::RESIZE) {
                $self->get()?->viewportResized();
            }

            return false;
        });
        $edit->viewport()->installEventFilter($this->viewport_filter);
    }

    public function native(): QPlainTextEdit
    {
        return $this->native;
    }

    protected function naturalPolicy(): array
    {
        return [Policy::EXPANDING, Policy::EXPANDING];
    }

    /**
     * A new document: the styles painted over the old one go with it. The edit lays the new text
     * out in the window's next paint and clamps the scroll bars there: that update request is
     * sent inside the write, as applyFont does.
     */
    protected function applyValue(string $value): void
    {
        $this->applying(function () use ($value): void {
            $this->native()->setPlainText($value);
            QCoreApplication::sendPostedEvents($this->qtWindow()->native(), EventType::UPDATE_REQUEST->value);
        });
        $this->native()->setExtraSelections([]);
        $this->line_selections = 0;
        $this->tail_selections = 0;
        $this->tail_start = null;
        $this->tail_format = null;
    }

    /**
     * The style-sheet font, applied as a code-driven write. The edit lays its text out again in
     * the window's next paint, and that relayout clamps the scroll bars: the window's pending
     * update request is sent inside the write, so the clamp is not taken for the user's scroll.
     */
    protected function applyFont(FontSpec $font): void
    {
        $this->applying(function () use ($font): void {
            $this->styleFont($font);
            QCoreApplication::sendPostedEvents($this->qtWindow()->native(), EventType::UPDATE_REQUEST->value);
            $this->rebuildTail();
        });
    }

    protected function applyReadOnly(bool $read_only): void
    {
        $this->native()->setReadOnly($read_only);
        $this->native()->setTextInteractionFlags($read_only
            ? TextInteractionFlag::TEXT_SELECTABLE_BY_MOUSE->value | TextInteractionFlag::TEXT_SELECTABLE_BY_KEYBOARD->value
            : TextInteractionFlag::TEXT_EDITOR_INTERACTION->value);
    }

    /**
     * Relaid out in the window's next paint, like a new value: the update request goes inside the write.
     */
    protected function applyWrap(bool $wrap): void
    {
        $this->applying(function () use ($wrap): void {
            $this->native()->setLineWrapMode($wrap ? LineWrapMode::WIDGET_WIDTH : LineWrapMode::NO_WRAP);
            QCoreApplication::sendPostedEvents($this->qtWindow()->native(), EventType::UPDATE_REQUEST->value);
            $this->rebuildTail();
        });
    }

    protected function applyLineBackgrounds(array $backgrounds): void
    {
        $document = $this->native()->document();
        $lines = $this->lines();
        $last = $lines->count() - 1;
        $selections = [];
        $this->tail_start = null;
        $this->tail_format = null;
        foreach ($backgrounds as $line => $color) {
            $format = new QTextCharFormat();
            $format->setBackground(new QBrush(self::qColor($color)));
            $format->setProperty(QTextFormat::FULL_WIDTH_SELECTION, true);
            $cursor = new QTextCursor($document);
            $cursor->setPosition($lines->utf16Start($line));
            if ($line === $last) {
                $this->tail_start = $cursor;
                $this->tail_format = $format;
                continue;
            }
            $cursor->setPosition($lines->utf16Start($line + 1), MoveMode::KEEP_ANCHOR);
            $selections[] = new ExtraSelection($cursor, $format);
        }
        $tail = $this->tailSelections();
        $ranges = array_slice($this->native()->extraSelections(), $this->line_selections);
        $this->native()->setExtraSelections([...$selections, ...$tail, ...$ranges]);
        $this->line_selections = count($selections) + count($tail);
        $this->tail_selections = count($tail);
    }

    protected function applyRangeStyles(array $styles): void
    {
        $document = $this->native()->document();
        $lines = $this->lines();
        $selections = [];
        foreach ($styles as $style) {
            $start = $lines->utf16Start($style->line);
            $cursor = new QTextCursor($document);
            $cursor->setPosition($start + $lines->utf16Column($style->line, $style->start));
            $cursor->setPosition($start + $lines->utf16Column($style->line, $style->start + $style->length), MoveMode::KEEP_ANCHOR);
            $format = new QTextCharFormat();
            if (! is_null($style->foreground)) {
                $format->setForeground(new QBrush(self::qColor($style->foreground)));
            }
            if (! is_null($style->background)) {
                $format->setBackground(new QBrush(self::qColor($style->background)));
            }
            $selections[] = new ExtraSelection($cursor, $format);
        }
        $backgrounds = array_slice($this->native()->extraSelections(), 0, $this->line_selections);
        $this->native()->setExtraSelections([...$backgrounds, ...$selections]);
    }

    protected function applySelectLines(int $first, int $last): void
    {
        $lines = $this->lines();
        $cursor = new QTextCursor($this->native()->document());
        $cursor->setPosition($lines->utf16Start($first));
        $cursor->setPosition($lines->utf16Start($last) + $lines->utf16Length($last), MoveMode::KEEP_ANCHOR);
        // setTextCursor makes the caret visible, scrolling the pane; selecting leaves it where it is.
        $this->applying(function () use ($cursor): void {
            $edit = $this->native();
            [$left, $top] = [$edit->horizontalScrollBar()->value(), $edit->verticalScrollBar()->value()];
            $edit->setTextCursor($cursor);
            $edit->horizontalScrollBar()->setValue($left);
            $edit->verticalScrollBar()->setValue($top);
        });
    }

    protected function nativeSelectedLines(): array
    {
        $cursor = $this->native()->textCursor();
        $lines = $this->lines();
        $first = $lines->lineAtUtf16($cursor->selectionStart());

        return [$first, $cursor->hasSelection() ? $lines->lineAtUtf16($cursor->selectionEnd() - 1) : $first];
    }

    protected function nativeScrollPosition(): ScrollPosition
    {
        $horizontal = $this->native()->horizontalScrollBar();
        $vertical = $this->native()->verticalScrollBar();

        return ScrollPosition::of(
            (float) $horizontal->value(), (float) $horizontal->pageStep(), (float) ($horizontal->maximum() + $horizontal->pageStep()),
            (float) $vertical->value(), (float) $vertical->pageStep(), (float) ($vertical->maximum() + $vertical->pageStep()),
        );
    }

    protected function applyScrollTo(float $x, float $y): void
    {
        $horizontal = $this->native()->horizontalScrollBar();
        $vertical = $this->native()->verticalScrollBar();
        $left = ScrollPosition::offset($x, $horizontal->pageStep(), $horizontal->maximum() + $horizontal->pageStep());
        $top = ScrollPosition::offset($y, $vertical->pageStep(), $vertical->maximum() + $vertical->pageStep());
        // Told the position it already has: the bars stay put.
        if (abs($left - $horizontal->value()) < 0.5 && abs($top - $vertical->value()) < 0.5) {
            return;
        }
        $this->applying(function () use ($left, $top, $horizontal, $vertical): void {
            $horizontal->setValue((int) round($left));
            $vertical->setValue((int) round($top));
        });
    }

    protected function applyScrollToLine(int $line): void
    {
        $block = $this->native()->document()->findBlockByNumber($line);
        if (! $block->isValid()) {
            return;
        }
        $this->applying(fn () => $this->native()->verticalScrollBar()->setValue($block->firstLineNumber()));
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

    /**
     * The viewport changed size: once the edit has relaid the text out to it, the last line's
     * rows are rebuilt.
     */
    private function viewportResized(): void
    {
        if (is_null($this->tail_start) || $this->tail_pending) {
            return;
        }
        $this->tail_pending = true;
        $self = WeakReference::create($this);
        QTimer::singleShot(0, static function () use ($self): void {
            $area = $self->get();
            if (is_null($area)) {
                return;
            }
            $area->tail_pending = false;
            if (! $area->removed) {
                $area->rebuildTail();
            }
        });
    }

    /**
     * Replace the last line's selections, between the other line backgrounds and the ranges,
     * with ones built from its rows as they are now.
     */
    private function rebuildTail(): void
    {
        if (is_null($this->tail_start)) {
            return;
        }
        $all = $this->native()->extraSelections();
        $kept = $this->line_selections - $this->tail_selections;
        $tail = $this->tailSelections();
        $this->native()->setExtraSelections([...array_slice($all, 0, $kept), ...$tail, ...array_slice($all, $this->line_selections)]);
        $this->line_selections = $kept + count($tail);
        $this->tail_selections = count($tail);
    }

    /**
     * The last line's background: a span from where it starts to the start of the text's last
     * row (none when that row is its first), and a collapsed FullWidthSelection at the text's
     * end, which paints that last row. The two never overlap, so a translucent colour paints
     * once. If the user's edits have put lines after it, it is one span through its newline,
     * as every other line is.
     *
     * @return list<ExtraSelection>
     */
    private function tailSelections(): array
    {
        if (is_null($this->tail_start) || is_null($this->tail_format)) {
            return [];
        }
        $document = $this->native()->document();
        $lines = $this->lines();
        $from = $this->tail_start->position();
        $line = $lines->lineAtUtf16($from);
        $span = new QTextCursor($document);
        $span->setPosition($from);
        if ($line < $lines->count() - 1) {
            $span->setPosition($lines->utf16Start($line + 1), MoveMode::KEEP_ANCHOR);

            return [new ExtraSelection($span, $this->tail_format)];
        }
        $end = new QTextCursor($document);
        $end->movePosition(MoveOperation::END);
        $row = new QTextCursor($document);
        $row->setPosition($end->position());
        $row->movePosition(MoveOperation::START_OF_LINE);
        $selections = [];
        if ($row->position() > $from) {
            $span->setPosition($row->position(), MoveMode::KEEP_ANCHOR);
            $selections[] = new ExtraSelection($span, $this->tail_format);
        }
        $selections[] = new ExtraSelection($end, $this->tail_format);

        return $selections;
    }

    private static function qColor(Color $color): QColor
    {
        return new QColor(
            (int) round($color->red * 255),
            (int) round($color->green * 255),
            (int) round($color->blue * 255),
            (int) round($color->alpha * 255),
        );
    }
}
