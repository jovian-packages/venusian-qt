<?php

namespace Jovian\Toolkits\Qt\UI;

use QLabel;
use Qt\TextFormat;
use Surface\Toolkits\UI\TKLabel;
use Surface\Contracts\Toolkits\Styling\TextRun;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\Qt\UI\Concerns\QtText;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\QtSheetMargin;

/**
 * A QLabel showing plain text (Qt's default, AutoText, would render a string that looks like
 * HTML), or Qt's rich text for runs. Font and text colour are style sheet declarations.
 */
class QtLabel extends TKLabel
{
    use QtNative;
    use QtSheetMargin;
    use QtText;

    protected function bootstrapNative(): void
    {
        $label = new QLabel($this->text());
        $label->setTextFormat(TextFormat::PLAIN_TEXT);
        $this->adoptNative($label);
        $this->applyAlignment($this->alignment);
    }

    /**
     * Plain text again: a later '<' shows as itself.
     */
    protected function applyText(string $text): void
    {
        $this->labelNative()->setTextFormat(TextFormat::PLAIN_TEXT);
        $this->labelNative()->setText($text);
    }

    /**
     * Qt's rich text: each run escaped, in <b>, <i> and a coloured <span> as it asks; a plain
     * run is the label's own font and colour from its style sheet. White space is kept as
     * plain text keeps it (white-space: pre-wrap), so a space at a run's edge stays.
     *
     * @param list<TextRun> $runs
     */
    protected function applyRuns(array $runs): void
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

        $this->labelNative()->setTextFormat(TextFormat::RICH_TEXT);
        $this->labelNative()->setText('<span style="white-space: pre-wrap">'.$html.'</span>');
    }

    protected function applyWrap(bool $wrap): void
    {
        $this->labelNative()->setWordWrap($wrap);
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
