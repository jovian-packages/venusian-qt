<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QGridLayout;
use QImage;
use QImage\Format;
use QLabel;
use QPixmap;
use QSizePolicy\Policy;
use QWidget;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKCanvas;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A canvas over a host QWidget holding one QLabel. The label is Ignored×Ignored with scaled
 * contents, so the layout sizes the canvas and the pixmap is stretched over it, whatever the
 * framebuffer's size. Each present() copies the framebuffer's RGBA8 bytes into a QImage in the
 * RGBX8888 format (the fourth byte ignored, so opaque) and shows it as the label's pixmap.
 */
class QtCanvas extends TKCanvas implements QtNative
{
    use QtPrimitive;

    protected QLabel $label;

    protected QGridLayout $cell;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $host = $this->adoptNative(new QWidget());
        $this->cell = new QGridLayout($host);
        $this->cell->setContentsMargins(0, 0, 0, 0);
        $this->label = new QLabel();
        $this->label->setSizePolicy(Policy::IGNORED, Policy::IGNORED);
        $this->label->setScaledContents(true);
        $this->cell->addWidget($this->label, 0, 0);
    }

    /**
     * The host widget: the canvas's slot in its container.
     * @return QWidget
     */
    public function native(): QWidget
    {
        return $this->native;
    }

    /**
     * The label showing the framebuffer.
     * @return QLabel
     */
    public function label(): QLabel
    {
        return $this->label;
    }

    /** Device pixels per logical pixel on the widget's screen. */
    protected function nativeScale(): float
    {
        return $this->native->devicePixelRatioF();
    }

    protected function applyPixels(string $rgba8, int $width, int $height): void
    {
        $this->label->setPixmap(QPixmap::fromImage(new QImage($rgba8, $width, $height, $width * 4, Format::RGBX8888)));
    }
}
