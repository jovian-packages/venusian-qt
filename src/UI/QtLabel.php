<?php

namespace Jovian\Toolkits\Qt\UI;

use QLabel;
use Qt\TextFormat;
use Surface\Toolkits\UI\TKLabel;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\Qt\UI\Concerns\QtText;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QLabel showing plain text (Qt's default, AutoText, would render a string that looks like
 * HTML). Font and text colour are style sheet declarations.
 */
class QtLabel extends TKLabel
{
    use QtNative;
    use QtText;

    protected function bootstrapNative(): void
    {
        $label = new QLabel($this->text());
        $label->setTextFormat(TextFormat::PLAIN_TEXT);
        $this->adoptNative($label);
        $this->applyAlignment($this->alignment);
    }

    protected function applyText(string $text): void
    {
        $this->labelNative()->setText($text);
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
