<?php

namespace Jovian\Toolkits\Qt\UI\Concerns;

use QFontDatabase;
use Qt\AlignmentFlag;
use QFontDatabase\SystemFont;
use Surface\NutsAndBolts\Color;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Surface\Contracts\Toolkits\Styling\TextAlignment;

/**
 * Fonts, colour and alignment, for every Qt primitive that shows text.
 */
trait QtText
{
    protected static function alignmentFlags(TextAlignment $alignment): int
    {
        $horizontal = match ($alignment) {
            TextAlignment::LEFT => AlignmentFlag::LEFT,
            TextAlignment::CENTER => AlignmentFlag::H_CENTER,
            TextAlignment::RIGHT => AlignmentFlag::RIGHT,
        };

        return $horizontal->value | AlignmentFlag::V_CENTER->value;
    }

    /**
     * Qt's pt is the point FontSpec sizes are in. Monospace with no family: the system's fixed font.
     */
    protected function applyFont(FontSpec $font): void
    {
        $family = $font->family ?? ($font->monospace ? QFontDatabase::systemFont(SystemFont::FIXED_FONT)->family() : null);

        $this->declare('font-size', "{$font->size}pt");
        $this->declare('font-weight', (string) $font->weight->toCssWeight());
        $this->declare('font-style', $font->italic ? 'italic' : null);
        $this->declare('font-family', is_null($family) ? null : '"'.addcslashes($family, '"\\').'"');
    }

    protected function applyTextColor(?Color $color): void
    {
        $this->declare('color', $color?->toCss());
    }
}
