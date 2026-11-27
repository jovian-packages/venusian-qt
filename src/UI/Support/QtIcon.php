<?php

namespace Jovian\Toolkits\Qt\UI\Support;

use QIcon;
use QPixmap;

/**
 * An icon as topside gives it, a file and a theme name: the file when there is one, else the
 * theme's icon (on macOS Qt also takes SF Symbol names), else Qt's null icon.
 *
 * @internal
 */
final class QtIcon
{
    public static function of(?string $file, ?string $symbol): QIcon
    {
        if (! is_null($file)) {
            $pixmap = new QPixmap();
            $pixmap->load($file);

            return new QIcon($pixmap);
        }

        return is_null($symbol) ? new QIcon(new QPixmap()) : QIcon::fromTheme($symbol);
    }
}
