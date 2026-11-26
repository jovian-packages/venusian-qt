<?php

namespace Jovian\Toolkits\Qt\UI;

use QRect;
use QEvent;
use QColor;
use QPainter;
use QPainterPath;
use QPainter\RenderHint;
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
use Surface\Contracts\Toolkits\UI\Corners;
use Surface\Contracts\Toolkits\UI\ImageScaling;

/**
 * A QLabel over a spacer of the picture's natural size, scaled again as it is resized. A FIT
 * picture's natural height follows its width; ext-qt binds no heightForWidth. With rounded
 * corners the label shows the picture drawn through the rounded outline, transparent outside it.
 */
class QtImage extends TKImage
{
    use QtNative {
        applyCorners as private sheetCorners;
    }

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
        $size = $this->picture->size();
        if (! $this->corners()->isZero() && $this->laid_out && $size->width() > 0 && $size->height() > 0) {
            $this->picture->setScaledContents(false);
            $this->picture->setPixmap($this->rounded($this->shown($size->width(), $size->height())));

            return;
        }
        $this->picture->setScaledContents($scaling === ImageScaling::STRETCH);
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
     * Rounds the background, and the picture itself: the label draws the rounded picture.
     */
    protected function applyCorners(Corners $corners): void
    {
        $this->sheetCorners($corners);
        $this->draw();
    }

    /**
     * The part of the picture the label shows, at its pixels (device pixel ratio 1) for a
     * label of $width × $height points: fitted, covering (cropped to the label, centred),
     * stretched, or at its own size (cropped, centred, when larger).
     */
    private function shown(int $width, int $height): QPixmap
    {
        $ratio = $this->picture->devicePixelRatioF();
        [$w, $h] = [(int) round($width * $ratio), (int) round($height * $ratio)];

        $picture = match ($this->scaling()) {
            ImageScaling::FIT => $this->pixmap->scaled($w, $h, AspectRatioMode::KEEP_ASPECT_RATIO, TransformationMode::SMOOTH_TRANSFORMATION),
            ImageScaling::FILL => $this->pixmap->scaled($w, $h, AspectRatioMode::KEEP_ASPECT_RATIO_BY_EXPANDING, TransformationMode::SMOOTH_TRANSFORMATION),
            ImageScaling::STRETCH => $this->pixmap->scaled($w, $h, AspectRatioMode::IGNORE_ASPECT_RATIO, TransformationMode::SMOOTH_TRANSFORMATION),
            ImageScaling::CENTER => $this->pixmap->scaled((int) round($this->pixmap->width() * $ratio), (int) round($this->pixmap->height() * $ratio), AspectRatioMode::IGNORE_ASPECT_RATIO, TransformationMode::SMOOTH_TRANSFORMATION),
        };
        $picture->setDevicePixelRatio(1.0);

        [$pw, $ph] = [min($picture->width(), $w), min($picture->height(), $h)];
        if ($pw === $picture->width() && $ph === $picture->height()) {
            return $picture;
        }

        return $picture->copy(new QRect(intdiv($picture->width() - $pw, 2), intdiv($picture->height() - $ph, 2), $pw, $ph));
    }

    /**
     * $picture drawn through a path with each corner's arc, antialiased, into a transparent
     * pixmap of its size; set to the label's device pixel ratio.
     */
    private function rounded(QPixmap $picture): QPixmap
    {
        $ratio = $this->picture->devicePixelRatioF();
        [$w, $h] = [(float) $picture->width(), (float) $picture->height()];
        $limit = min($w, $h) / 2;
        $r = static fn (float $radius): float => min($radius * $ratio, $limit);
        [$tl, $tr, $br, $bl] = array_map($r, $this->corners()->toArray());

        $path = new QPainterPath();
        $path->moveTo($tl, 0.0);
        $path->lineTo($w - $tr, 0.0);
        if ($tr > 0.0) {
            $path->arcTo($w - 2 * $tr, 0.0, 2 * $tr, 2 * $tr, 90.0, -90.0);
        }
        $path->lineTo($w, $h - $br);
        if ($br > 0.0) {
            $path->arcTo($w - 2 * $br, $h - 2 * $br, 2 * $br, 2 * $br, 0.0, -90.0);
        }
        $path->lineTo($bl, $h);
        if ($bl > 0.0) {
            $path->arcTo(0.0, $h - 2 * $bl, 2 * $bl, 2 * $bl, 270.0, -90.0);
        }
        $path->lineTo(0.0, $tl);
        if ($tl > 0.0) {
            $path->arcTo(0.0, 0.0, 2 * $tl, 2 * $tl, 180.0, -90.0);
        }
        $path->closeSubpath();

        $canvas = $picture->copy();
        $canvas->fill(new QColor(0, 0, 0, 0));
        $painter = new QPainter($canvas);
        $painter->setRenderHint(RenderHint::ANTIALIASING);
        $painter->setRenderHint(RenderHint::SMOOTH_PIXMAP_TRANSFORM);
        $painter->setClipPath($path);
        $painter->drawPixmap(new QRect(0, 0, $picture->width(), $picture->height()), $picture);
        $painter->end();

        $canvas->setDevicePixelRatio($ratio);

        return $canvas;
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
