<?php

namespace Jovian\Toolkits\Qt\Primitives\Concerns;

use QByteArray;
use QImage;
use QPixmap;
use Surface\Contracts\Framebuffers\Framebuffer;

/**
 * A Framebuffer as a QPixmap: its RGBA8 pixels, the device pixel ratio the scale, so Qt shows it
 * at width / scale × height / scale points.
 */
trait QtPixmap
{
    protected static function pixmap(Framebuffer $image, float $scale): QPixmap
    {
        $width = $image->viewportWidth();
        $rgba8 = $image->toRgba8();
        // QImage borrows the bytes; fromImage copies them before $bytes goes.
        $bytes = new QByteArray($rgba8, strlen($rgba8));
        $pixmap = QPixmap::fromImage(new QImage($bytes->pointer(), $width, $image->viewportHeight(), $width * 4, QImage\Format::RGBA8888));
        $pixmap->setDevicePixelRatio($scale);

        return $pixmap;
    }
}
