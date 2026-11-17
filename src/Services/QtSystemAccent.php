<?php

namespace Jovian\Toolkits\Qt\Services;

use QGuiApplication;
use QPalette;
use Surface\Contracts\Toolkits\Services\SystemAccent;
use Surface\NutsAndBolts\Color;

/** The accent role of Qt's application palette (QPalette::Accent, Qt 6.6 and later); null on an older Qt. */
final class QtSystemAccent implements SystemAccent
{
    public function color(): ?Color
    {
        // ext-qt's enum names ACCENT on every Qt, but Qt before 6.6 has no such role: reading it goes past the palette.
        if (version_compare(qVersion(), '6.6.0', '<')) {
            return null;
        }
        $accent = QGuiApplication::palette()->color(QPalette\ColorRole::ACCENT);

        return $accent->isValid() ? Color::rgb($accent->red(), $accent->green(), $accent->blue()) : null;
    }
}
