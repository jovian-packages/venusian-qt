<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QLabel;
use Qt\AlignmentFlag;
use Qt\TextFormat;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Styling\TextAlignment;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKLabel;
use Surface\Windows\Primitives\TKPrimitiveGroup;

class QtLabel extends TKLabel implements QtNative
{
    use QtPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $text)
    {
        parent::__construct($name, $window, $parent, $placement, $text);
        // Shown literally: QLabel's AutoText would render text that looks like HTML as rich text.
        $this->adoptNative(new QLabel($text))->setTextFormat(TextFormat::PLAIN_TEXT);
    }

    public function native(): QLabel
    {
        return $this->native;
    }

    protected function applyText(string $text): void
    {
        $this->native()->setText($text);
    }

    protected function applyWrap(bool $wrap): void
    {
        $this->native()->setWordWrap($wrap);
    }

    /**
     * Horizontal only: QLabel's vertical centring (its default) stays.
     * @param TextAlignment $alignment
     * @return void
     */
    protected function applyAlignment(TextAlignment $alignment): void
    {
        $horizontal = match ($alignment) {
            TextAlignment::LEFT => AlignmentFlag::LEFT,
            TextAlignment::CENTER => AlignmentFlag::H_CENTER,
            TextAlignment::RIGHT => AlignmentFlag::RIGHT,
        };
        $this->native()->setAlignment($horizontal->value | AlignmentFlag::V_CENTER->value);
    }
}
