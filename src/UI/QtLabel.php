<?php

namespace Jovian\Toolkits\Qt\UI;

use QFont;
use QEvent;
use QLabel;
use QObject;
use QEventFilter;
use QFontMetricsF;
use WeakReference;
use Qt\TextFormat;
use Qt\TextElideMode;
use QSizePolicy\Policy;
use QEvent\Type as EventType;
use Surface\Toolkits\UI\TKLabel;
use Surface\Contracts\Toolkits\Styling\TextRun;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\Qt\UI\Concerns\QtText;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\QtSheetMargin;

/**
 * A QLabel showing plain text (Qt's default, AutoText, would render a string that looks like
 * HTML), or Qt's rich text for runs. Font and text colour are style sheet declarations.
 *
 * Truncating: a QLabel never elides, so the label shows its text cut with "…" to its width,
 * Qt's way, through QFontMetricsF::elidedText(), again on each resize and font change (a style
 * sheet's font comes as a font change) and each new text. Runs are cut run by run, each
 * measured in its own weight and slant. Its minimum width is 1 (Qt uses it in place of the
 * whole text's minimum hint) and, while it does not fill, its width policy IGNORED, so the
 * text never widens what holds it. It is one line whatever wrap says.
 */
class QtLabel extends TKLabel implements QtKeepsOwnMargin
{
    use QtNative {
        applyMinSize as private nativeMinSize;
        naturalSizePolicy as private ownSizePolicy;
    }
    use QtSheetMargin;
    use QtText {
        applyFont as private sheetFont;
    }

    /** The font set, for a run's slant: none is the toolkit's upright one. */
    private ?FontSpec $font_spec = null;

    /** @var list<TextRun>|null the runs shown; null while the text is plain */
    private ?array $shown_runs = null;

    /** Watches the label's resizes and font changes, for the cut. */
    private ?QEventFilter $cuts = null;

    protected function bootstrapNative(): void
    {
        $label = new QLabel($this->text());
        $label->setTextFormat(TextFormat::PLAIN_TEXT);
        $this->adoptNative($label);
        $this->applyAlignment($this->alignment);

        $self = WeakReference::create($this);
        $this->cuts = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            if (in_array($event->type(), [EventType::RESIZE, EventType::FONT_CHANGE, EventType::STYLE_CHANGE], true)) {
                $self->get()?->cut();
            }

            return false;
        });
        $label->installEventFilter($this->cuts);
    }

    /**
     * Plain text again: a later '<' shows as itself.
     */
    protected function applyText(string $text): void
    {
        $this->shown_runs = null;
        $this->showText();
    }

    /**
     * @param list<TextRun> $runs
     */
    protected function applyRuns(array $runs): void
    {
        $this->shown_runs = $runs;
        $this->showText();
    }

    /**
     * Qt's rich text: each run escaped, in <b>, <i> and a coloured <span> as it asks; a plain
     * run is the label's own font and colour from its style sheet. White space is kept as
     * plain text keeps it (white-space: pre-wrap), so a space at a run's edge stays.
     *
     * @param list<TextRun> $runs
     */
    private static function richText(array $runs): string
    {
        $html = '';
        foreach ($runs as $run) {
            $piece = htmlspecialchars($run->text, ENT_QUOTES | ENT_HTML401 | ENT_SUBSTITUTE, 'UTF-8');
            if (! is_null($run->color)) {
                $color = $run->color->alpha < 1.0 ? $run->color->toCss() : $run->color->toHex();
                $piece = '<span style="color: '.$color.'">'.$piece.'</span>';
            }
            if ($run->italic) {
                $piece = '<i>'.$piece.'</i>';
            }
            if ($run->bold) {
                $piece = '<b>'.$piece.'</b>';
            }
            $html .= $piece;
        }

        return '<span style="white-space: pre-wrap">'.$html.'</span>';
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->font_spec = $font;
        $this->sheetFont($font);
    }

    protected function applyWrap(bool $wrap): void
    {
        $this->labelNative()->setWordWrap($wrap && ! $this->truncates());
    }

    protected function applyTruncate(bool $truncate): void
    {
        $this->labelNative()->setWordWrap($this->wraps() && ! $truncate);
        $this->applyMinSize(...$this->minimumSize());
        $this->refreshSizePolicy();
        $this->showText();
    }

    /**
     * At least 1 wide while truncating: a minimum of 0 makes Qt use the whole text's.
     */
    protected function applyMinSize(int $width, int $height): void
    {
        $this->nativeMinSize($this->truncates() ? max(1, $width) : $width, $height);
    }

    /**
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        [$horizontal, $vertical] = $this->ownSizePolicy();

        return [$this->truncates() ? Policy::IGNORED : $horizontal, $vertical];
    }

    /**
     * The text, or the runs, whole; or, truncating, cut to the label's width.
     */
    private function showText(): void
    {
        $label = $this->labelNative();
        $runs = $this->shown_runs;
        if ($this->truncates()) {
            [$cut, $runs] = $this->cutTo($this->room());
            if (is_null($runs)) {
                $this->showPlain($cut);

                return;
            }
        }

        if (is_null($runs)) {
            $this->showPlain($this->text());

            return;
        }

        $html = self::richText($runs);
        if ($label->textFormat() !== TextFormat::RICH_TEXT || $label->text() !== $html) {
            $label->setTextFormat(TextFormat::RICH_TEXT);
            $label->setText($html);
        }
    }

    private function showPlain(string $text): void
    {
        $label = $this->labelNative();
        if ($label->textFormat() !== TextFormat::PLAIN_TEXT || $label->text() !== $text) {
            $label->setTextFormat(TextFormat::PLAIN_TEXT);
            $label->setText($text);
        }
    }

    /**
     * The label was resized or its font changed.
     */
    private function cut(): void
    {
        if ($this->truncates() && ! $this->isRemoved()) {
            $this->showText();
        }
    }

    /**
     * The width the text has: the label's, less its own sheet margin. 0 before it is laid out.
     */
    private function room(): float
    {
        $margin = $this->margin();

        return (float) max(0, $this->labelNative()->width() - $margin->left - $margin->right);
    }

    /**
     * The plain text cut to $width, or the runs cut to it run by run: each run measured in its
     * own weight and slant, the run that does not fit cut with "…" and the rest dropped. Before
     * the label has a width (0), whole.
     *
     * @return array{string, ?list<TextRun>}
     */
    private function cutTo(float $width): array
    {
        $base = $this->labelNative()->font();
        $plain = new QFontMetricsF($base);
        if (is_null($this->shown_runs)) {
            return [$width > 0 ? $plain->elidedText($this->text(), TextElideMode::ELIDE_RIGHT, $width) : $this->text(), null];
        }
        if ($width <= 0) {
            return ['', $this->shown_runs];
        }

        $runs = [];
        $left = $width;
        foreach ($this->shown_runs as $run) {
            $metrics = new QFontMetricsF(self::runFont($base, $run, $this->font_spec?->italic ?? false));
            $advance = $metrics->horizontalAdvance($run->text);
            if ($advance <= $left) {
                $runs[] = $run;
                $left -= $advance;
                continue;
            }

            $cut = $metrics->elidedText($run->text, TextElideMode::ELIDE_RIGHT, $left);
            $runs[] = new TextRun($cut === '' ? '…' : $cut, bold: $run->bold, italic: $run->italic, color: $run->color);
            break;
        }

        return ['', $runs];
    }

    /**
     * The label's font in a run's weight and slant: its family and size, bold as Qt's 700,
     * italic when the run or the label's font is.
     */
    private static function runFont(QFont $base, TextRun $run, bool $italic): QFont
    {
        $weight = $base->weight();
        $font = new QFont($base->family(), -1, $run->bold ? 700 : (is_int($weight) ? $weight : $weight->value), $run->italic || $italic);
        $font->setPointSizeF($base->pointSizeF());

        return $font;
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->labelNative()->setAlignment(self::alignmentFlags($alignment));
    }

    private function labelNative(): QLabel
    {
        /** @var QLabel */
        return $this->native();
    }
}
