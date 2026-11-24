<?php

namespace Jovian\Toolkits\Qt\UI;

use QEvent;
use QLabel;
use QObject;
use QPixmap;
use QWidget;
use QGridLayout;
use QSpacerItem;
use QEventFilter;
use WeakReference;
use Qt\AlignmentFlag;
use QSizePolicy\Policy;
use Qt\AspectRatioMode;
use Qt\TransformationMode;
use QEvent\Type as EventType;
use Surface\Toolkits\UI\TKImage;
use Surface\Toolkits\ToolkitException;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Surface\Contracts\Toolkits\UI\ImageScaling;

/**
 * A QLabel over a spacer of the picture's natural size, scaled again as it is resized. A FIT
 * picture's natural height follows its width; ext-qt binds no heightForWidth.
 */
class QtImage extends TKImage
{
    use QtNative;

    private ?QLabel $picture = null;

    private ?QGridLayout $cell = null;

    private ?QSpacerItem $natural = null;

    private ?QPixmap $pixmap = null;

    private ?QEventFilter $resizes = null;

    /** Until its first resize the label has no size to scale to. */
    private bool $laid_out = false;

    protected function bootstrapNative(): void
    {
        $file = $this->file();
        $this->pixmap = is_null($file) ? null : self::read($file);

        $host = new QWidget();
        $this->cell = new QGridLayout($host);
        $this->cell->setContentsMargins(0, 0, 0, 0);
        $this->natural = new QSpacerItem(0, 0, Policy::PREFERRED, Policy::PREFERRED);
        $this->cell->addItem($this->natural, 0, 0);

        $this->picture = new QLabel();
        $this->picture->setSizePolicy(Policy::IGNORED, Policy::IGNORED);
        $this->picture->setAlignment(AlignmentFlag::CENTER->value);
        $this->cell->addWidget($this->picture, 0, 0);

        $self = WeakReference::create($this);
        $this->resizes = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            if ($event->type() === EventType::RESIZE) {
                $self->get()?->resized();
            }

            return false;
        });
        $this->picture->installEventFilter($this->resizes);

        $this->adoptNative($host);
        $this->settle();
    }

    protected function applyFile(?string $file): void
    {
        $this->pixmap = is_null($file) ? null : self::read($file);
        $this->settle();
    }

    protected function applyScaling(ImageScaling $scaling): void
    {
        $this->settle();
    }

    private function settle(): void
    {
        $this->natural->changeSize($this->pixmap?->width() ?? 0, $this->pixmap?->height() ?? 0, Policy::PREFERRED, Policy::PREFERRED);
        $this->cell->invalidate();
        if ($this->laid_out) {
            $this->fitHeight();
        }
        $this->draw();
    }

    private function resized(): void
    {
        if ($this->isRemoved()) {
            return;
        }
        $this->laid_out = true;
        $this->fitHeight();
        $this->draw();
    }

    private function fitHeight(): void
    {
        $pixmap = $this->pixmap;
        if (is_null($pixmap) || $pixmap->width() === 0 || $this->scaling() !== ImageScaling::FIT) {
            return;
        }
        $width = $this->picture->size()->width();
        $height = (int) round($width * $pixmap->height() / $pixmap->width());
        if ($width > 0 && $height !== $this->natural->sizeHint()->height()) {
            $this->natural->changeSize($pixmap->width(), $height, Policy::PREFERRED, Policy::PREFERRED);
            $this->cell->invalidate();
        }
    }

    private function draw(): void
    {
        if (is_null($this->pixmap)) {
            $this->picture->setPixmap(null);

            return;
        }

        $scaling = $this->scaling();
        $this->picture->setScaledContents($scaling === ImageScaling::STRETCH);
        $size = $this->picture->size();
        if (! $this->laid_out || $size->width() < 1 || $size->height() < 1 || in_array($scaling, [ImageScaling::CENTER, ImageScaling::STRETCH], true)) {
            $this->picture->setPixmap($this->pixmap);

            return;
        }

        $ratio = $this->picture->devicePixelRatioF();
        $scaled = $this->pixmap->scaled(
            (int) round($size->width() * $ratio),
            (int) round($size->height() * $ratio),
            $scaling === ImageScaling::FIT ? AspectRatioMode::KEEP_ASPECT_RATIO : AspectRatioMode::KEEP_ASPECT_RATIO_BY_EXPANDING,
            TransformationMode::SMOOTH_TRANSFORMATION,
        );
        $scaled->setDevicePixelRatio($ratio);
        $this->picture->setPixmap($scaled);
    }

    /**
     * @throws ToolkitException When Qt reads no picture in it.
     */
    private static function read(string $file): QPixmap
    {
        $pixmap = new QPixmap();

        return $pixmap->load($file) ? $pixmap : throw new ToolkitException("'{$file}' is not an image Qt reads.");
    }
}
