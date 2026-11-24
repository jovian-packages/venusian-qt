<?php

namespace Jovian\Toolkits\Qt\UI;

use QFont;
use QBrush;
use QColor;
use QEvent;
use QTimer;
use QObject;
use QTextCursor;
use QTextFormat;
use QEventFilter;
use QFontDatabase;
use QFontMetricsF;
use WeakReference;
use QPlainTextEdit;
use QTextCharFormat;
use QCoreApplication;
use QTextCursor\MoveMode;
use Qt\TextInteractionFlag;
use QTextLayout\FormatRange;
use QEvent\Type as EventType;
use QFontDatabase\SystemFont;
use QTextEdit\ExtraSelection;
use QTextCursor\MoveOperation;
use Surface\NutsAndBolts\Color;
use QPlainTextEdit\LineWrapMode;
use Surface\Toolkits\UI\TKTextArea;
use Jovian\Toolkits\Qt\UI\Concerns\QtText;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Surface\Contracts\Toolkits\Styling\ScrollPosition;

/**
 * A QPlainTextEdit. Styles are extra selections, which Qt moves through the user's edits;
 * what code changes or scrolls is inside write(), the relayout it causes included.
 */
class QtTextArea extends TKTextArea
{
    use QtNative;
    use QtText {
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
    private ?QEventFilter $viewport_filter = null;

    /** Lines whose block layout carries bold or italic runs, cleared by the next range styles. */
    private array $layout_lines = [];

    /**
     * A font change lays the text out again at the viewport's next paint: inside the write on
     * macOS, after the X server's configure reply on X11. Until that paint has run, the scroll
     * clamp it makes is the write's, not the user's.
     */
    private bool $relayout_pending = false;

    private bool $relayout_closing = false;

    protected function bootstrapNative(): void
    {
        $edit = new QPlainTextEdit();
        $edit->setPlainText($this->value);
        $this->adoptNative($edit);

        $self = WeakReference::create($this);
        QObject::connect($edit, 'textChanged()', static function () use ($self): void {
            $self->get()?->edited();
        });
        $scrolled = static function () use ($self): void {
            $self->get()?->barMoved();
        };
        QObject::connect($edit->verticalScrollBar(), 'valueChanged(int)', $scrolled);
        QObject::connect($edit->horizontalScrollBar(), 'valueChanged(int)', $scrolled);

        // The filter sees the resize before the edit relays its text out to the new width: the
        // rebuild waits a turn of the event loop.
        $this->viewport_filter = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            if ($event->type() === EventType::RESIZE) {
                $self->get()?->viewportResized();
            } elseif ($event->type() === EventType::PAINT) {
                $self->get()?->viewportPainting();
            }

            return false;
        });
        $edit->viewport()->installEventFilter($this->viewport_filter);
    }

    private function edited(): void
    {
        if ($this->isRemoved() || $this->isWriting()) {
            return;
        }
        $this->typed($this->editNative()->toPlainText());
        $this->rebuildTail();
    }

    /**
     * A new document: the styles painted over the old one go with it. The edit lays the new text
     * out in the window's next paint and clamps the scroll bars there: that update request is
     * sent inside the write, as applyFont does.
     */
    protected function applyValue(string $value): void
    {
        $this->write(function () use ($value): void {
            $this->editNative()->setPlainText($value);
            QCoreApplication::sendPostedEvents($this->window()->native(), EventType::UPDATE_REQUEST->value);
        });
        $this->editNative()->setExtraSelections([]);
        $this->line_selections = 0;
        $this->tail_selections = 0;
        $this->tail_start = null;
        $this->tail_format = null;
    }

    /**
     * The style-sheet font, applied as a code-driven write. The edit lays its text out again in
     * the window's next paint, and that relayout clamps the scroll bars: the window's pending
     * update request is sent inside the write (on macOS that paint runs then). On X11 the paint
     * waits for the X server, so the clamp is held as code-driven until the viewport has painted.
     */
    protected function applyFont(FontSpec $font): void
    {
        $this->relayout_pending = true;
        $this->write(function () use ($font): void {
            $this->styleFont($font);
            QCoreApplication::sendPostedEvents($this->window()->native(), EventType::UPDATE_REQUEST->value);
            $this->rebuildTail();
        });
        $this->applyTabWidth($this->tab_width);
    }

    /** Tab stops every columns × the font's space advance: read from the FontSpec, since the style sheet applies at the next polish. */
    protected function applyTabWidth(int $columns): void
    {
        $font = is_null($this->font) ? $this->editNative()->font() : self::qFont($this->font);
        $this->editNative()->setTabStopDistance($columns * (new QFontMetricsF($font))->horizontalAdvance(' '));
    }

    /** The document's default option, copied and given the alignment: QPlainTextEdit keeps its wrap mode there. */
    protected function applyAlignment(TextAlignment $alignment): void
    {
        $document = $this->editNative()->document();
        $option = $document->defaultTextOption();
        $option->setAlignment(self::alignmentFlags($alignment));
        $document->setDefaultTextOption($option);
    }

    protected function applyReadOnly(bool $read_only): void
    {
        $this->editNative()->setReadOnly($read_only);
        $this->editNative()->setTextInteractionFlags($read_only
            ? TextInteractionFlag::TEXT_SELECTABLE_BY_MOUSE->value | TextInteractionFlag::TEXT_SELECTABLE_BY_KEYBOARD->value
            : TextInteractionFlag::TEXT_EDITOR_INTERACTION->value);
    }

    /**
     * Relaid out in the window's next paint, like a new value: the update request goes inside the write.
     */
    protected function applyWrap(bool $wrap): void
    {
        $this->write(function () use ($wrap): void {
            $this->editNative()->setLineWrapMode($wrap ? LineWrapMode::WIDGET_WIDTH : LineWrapMode::NO_WRAP);
            QCoreApplication::sendPostedEvents($this->window()->native(), EventType::UPDATE_REQUEST->value);
            $this->rebuildTail();
        });
    }

    protected function applyLineBackgrounds(array $backgrounds): void
    {
        $document = $this->editNative()->document();
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
        $ranges = array_slice($this->editNative()->extraSelections(), $this->line_selections);
        $this->editNative()->setExtraSelections([...$selections, ...$tail, ...$ranges]);
        $this->line_selections = count($selections) + count($tail);
        $this->tail_selections = count($tail);
    }

    protected function applyRangeStyles(array $styles): void
    {
        $document = $this->editNative()->document();
        $lines = $this->lines();
        $selections = [];
        $ranges = [];
        foreach ($styles as $style) {
            $start = $lines->utf16Start($style->line);
            $from = $lines->utf16Column($style->line, $style->start);
            $to = $lines->utf16Column($style->line, $style->start + $style->length);
            $cursor = new QTextCursor($document);
            $cursor->setPosition($start + $from);
            $cursor->setPosition($start + $to, MoveMode::KEEP_ANCHOR);
            $format = new QTextCharFormat();
            if (! is_null($style->foreground)) {
                $format->setForeground(new QBrush(self::qColor($style->foreground)));
            }
            if (! is_null($style->background)) {
                $format->setBackground(new QBrush(self::qColor($style->background)));
            }
            // An extra selection is drawn over text already laid out, so weight and italic go to the block's layout.
            if ($style->bold || $style->italic) {
                $face = new QTextCharFormat();
                if ($style->bold) {
                    $face->setProperty(QTextFormat::FONT_WEIGHT, 700);
                }
                if ($style->italic) {
                    $face->setProperty(QTextFormat::FONT_ITALIC, true);
                }
                $ranges[$style->line][] = new FormatRange($from, $to - $from, $face);
            }
            if ($style->underline) {
                $format->setProperty(QTextFormat::TEXT_UNDERLINE_STYLE, 1);   // QTextCharFormat::SingleUnderline
            }
            if ($style->strikeout) {
                $format->setProperty(QTextFormat::FONT_STRIKE_OUT, true);
            }
            $selections[] = new ExtraSelection($cursor, $format);
        }
        $backgrounds = array_slice($this->editNative()->extraSelections(), 0, $this->line_selections);
        $this->editNative()->setExtraSelections([...$backgrounds, ...$selections]);

        // shortcut: layout formats stay at their columns through the user's edits, unlike extra selections; restyle after edits.
        $relaid = $this->layout_lines + $ranges;
        $this->layout_lines = $ranges;
        if ($relaid === []) {
            return;
        }
        // A wider face can rewrap its line: relaid out like a font change.
        $this->write(function () use ($document, $relaid, $ranges): void {
            foreach ($relaid as $line => $_) {
                $block = $document->findBlockByNumber($line);
                $block->layout()?->setFormats($ranges[$line] ?? []);
                $document->markContentsDirty($block->position(), $block->length());
            }
            QCoreApplication::sendPostedEvents($this->window()->native(), EventType::UPDATE_REQUEST->value);
            $this->rebuildTail();
        });
    }

    protected function applySelectLines(int $first, int $last): void
    {
        $lines = $this->lines();
        $cursor = new QTextCursor($this->editNative()->document());
        $cursor->setPosition($lines->utf16Start($first));
        $cursor->setPosition($lines->utf16Start($last) + $lines->utf16Length($last), MoveMode::KEEP_ANCHOR);
        // setTextCursor makes the caret visible, scrolling the pane; selecting leaves it where it is.
        $this->write(function () use ($cursor): void {
            $edit = $this->editNative();
            [$left, $top] = [$edit->horizontalScrollBar()->value(), $edit->verticalScrollBar()->value()];
            $edit->setTextCursor($cursor);
            $edit->horizontalScrollBar()->setValue($left);
            $edit->verticalScrollBar()->setValue($top);
        });
    }

    protected function nativeSelectedLines(): array
    {
        $cursor = $this->editNative()->textCursor();
        $lines = $this->lines();
        $first = $lines->lineAtUtf16($cursor->selectionStart());

        return [$first, $cursor->hasSelection() ? $lines->lineAtUtf16($cursor->selectionEnd() - 1) : $first];
    }

    protected function nativeScrollPosition(): ScrollPosition
    {
        $horizontal = $this->editNative()->horizontalScrollBar();
        $vertical = $this->editNative()->verticalScrollBar();

        return ScrollPosition::of(
            (float) $horizontal->value(), (float) $horizontal->pageStep(), (float) ($horizontal->maximum() + $horizontal->pageStep()),
            (float) $vertical->value(), (float) $vertical->pageStep(), (float) ($vertical->maximum() + $vertical->pageStep()),
        );
    }

    protected function applyScrollTo(float $x, float $y): void
    {
        $horizontal = $this->editNative()->horizontalScrollBar();
        $vertical = $this->editNative()->verticalScrollBar();
        $left = ScrollPosition::offset($x, $horizontal->pageStep(), $horizontal->maximum() + $horizontal->pageStep());
        $top = ScrollPosition::offset($y, $vertical->pageStep(), $vertical->maximum() + $vertical->pageStep());
        // Told the position it already has: the bars stay put.
        if (abs($left - $horizontal->value()) < 0.5 && abs($top - $vertical->value()) < 0.5) {
            return;
        }
        $this->write(function () use ($left, $top, $horizontal, $vertical): void {
            $horizontal->setValue((int) round($left));
            $vertical->setValue((int) round($top));
        });
    }

    protected function applyScrollToLine(int $line): void
    {
        $block = $this->editNative()->document()->findBlockByNumber($line);
        if (! $block->isValid()) {
            return;
        }
        $this->write(fn () => $this->editNative()->verticalScrollBar()->setValue($block->firstLineNumber()));
    }

    private function barMoved(): void
    {
        if (! $this->relayout_pending) {
            $this->scrolled();
        }
    }

    /** The filter sees the paint before the edit's relayout in it: the pending relayout ends a turn of the event loop later. */
    private function viewportPainting(): void
    {
        if (! $this->relayout_pending || $this->relayout_closing) {
            return;
        }
        $this->relayout_closing = true;
        $self = WeakReference::create($this);
        QTimer::singleShot(0, static function () use ($self): void {
            $area = $self->get();
            if (is_null($area)) {
                return;
            }
            $area->relayout_pending = false;
            $area->relayout_closing = false;
            if (! $area->isRemoved()) {
                $area->rebuildTail();
            }
        });
    }

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
            if (! $area->isRemoved()) {
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
        $all = $this->editNative()->extraSelections();
        $kept = $this->line_selections - $this->tail_selections;
        $tail = $this->tailSelections();
        $this->editNative()->setExtraSelections([...array_slice($all, 0, $kept), ...$tail, ...array_slice($all, $this->line_selections)]);
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
        $document = $this->editNative()->document();
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

    private static function qFont(FontSpec $font): QFont
    {
        $family = $font->family ?? QFontDatabase::systemFont($font->monospace ? SystemFont::FIXED_FONT : SystemFont::GENERAL_FONT)->family();
        $made = new QFont($family, -1, $font->weight->toCssWeight(), $font->italic);
        $made->setPointSizeF($font->size);

        return $made;
    }

    private function editNative(): QPlainTextEdit
    {
        /** @var QPlainTextEdit */
        return $this->native();
    }
}
