<?php

namespace Jovian\Toolkits\Qt\Primitives\Concerns;

use QFont;
use QFontDatabase;
use QFontDatabase\SystemFont;
use QFontInfo;
use QFontMetricsF;
use Surface\Contracts\Windows\Styling\FontMetrics;
use Surface\Contracts\Windows\Styling\FontSpec;

/**
 * FontSpec as the QFont the style sheet asks for (family, or the system's general or fixed
 * family; weight; italic; point size), and what Qt measures for it. Needs the QApplication.
 */
final class QtFonts
{
    public static function qFont(FontSpec $font): QFont
    {
        $family = $font->family ?? QFontDatabase::systemFont($font->monospace ? SystemFont::FIXED_FONT : SystemFont::GENERAL_FONT)->family();
        $made = new QFont($family, -1, $font->weight->toCssWeight(), $font->italic);
        $made->setPointSizeF($font->size);

        return $made;
    }

    public static function metrics(FontSpec $font): FontMetrics
    {
        $made = self::qFont($font);
        $metrics = new QFontMetricsF($made);

        return new FontMetrics(
            $metrics->ascent(),
            $metrics->descent(),
            $metrics->lineSpacing(),
            $metrics->averageCharWidth(),
            (new QFontInfo($made))->fixedPitch(),
        );
    }

    /** One space's advance: the unit tab stops count in. */
    public static function spaceAdvance(QFont $font): float
    {
        return (new QFontMetricsF($font))->horizontalAdvance(' ');
    }
}
