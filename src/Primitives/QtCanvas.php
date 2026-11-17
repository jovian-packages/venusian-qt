<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QByteArray;
use QGridLayout;
use QImage;
use QImage\Format;
use QLabel;
use QPixmap;
use QSizePolicy\Policy;
use QWidget;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\ToolkitWindow;
use Surface\Toolkits\Primitives\TKCanvas;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;

/**
 * A widget holding one label, which shows the pixels it was last given as a pixmap stretched over
 * the widget (the alpha byte is not read, so opaque). It has no natural size: the layout sizes it.
 *
 * It takes no keys or pointer events. It lends its widget to an engine that draws there itself (a
 * Vulkan engine puts its own window in it); the label stays under whatever the engine adds.
 */
class QtCanvas extends TKCanvas implements QtNative
{
    use QtPrimitive;

    protected QGridLayout $cell;

    protected QLabel $label;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $host = $this->adoptNative(new QWidget());
        $this->cell = new QGridLayout($host);
        $this->cell->setContentsMargins(0, 0, 0, 0);
        $this->label = new QLabel();
        // ignored: the label takes the size the layout gives it, never the pixmap's
        $this->label->setSizePolicy(Policy::IGNORED, Policy::IGNORED);
        $this->label->setScaledContents(true);
        $this->cell->addWidget($this->label, 0, 0);
    }

    public function native(): QWidget
    {
        return $this->native;
    }

    /** The widget: an engine adds its own child to it and takes it away again. */
    public function nativeSurface(): QWidget
    {
        $this->live();

        return $this->native;
    }

    /** Device pixels per logical pixel on the widget's screen. */
    protected function nativeScale(): float
    {
        return $this->native->devicePixelRatioF();
    }

    /** The image borrows the byte array's copy of the pixels; fromImage() copies them into the pixmap while the array is alive. */
    protected function applyPixels(string $rgba8, int $width, int $height): void
    {
        $bytes = new QByteArray($rgba8, strlen($rgba8));
        $this->label->setPixmap(QPixmap::fromImage(new QImage($bytes->pointer(), $width, $height, $width * 4, Format::RGBX8888)));
    }
}
