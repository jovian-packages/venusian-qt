<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QEvent\Type as EventType;
use QEventFilter;
use QGridLayout;
use QLabel;
use QObject;
use QPixmap;
use QSizePolicy\Policy;
use QSpacerItem;
use Qt\AlignmentFlag;
use Qt\AspectRatioMode;
use Qt\TransformationMode;
use QWidget;
use Surface\Contracts\Windows\Primitives\ImageScaling;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKImage;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use WeakReference;

/**
 * A host widget whose one grid cell holds a spacer at the file's size and a QLabel that
 * ignores its own size hint. The host's natural size is the image's, never the last scaled
 * pixmap's, so the slot can shrink and grow back; the label gets the whole cell. FIT and FILL
 * re-scale the loaded pixmap (smoothly, at the screen's pixel ratio) to the label on every
 * resize; CENTER shows it unscaled; STRETCH lets QLabel stretch it.
 */
class QtImage extends TKImage implements QtNative
{
    use QtPrimitive;

    protected QLabel $label;

    protected QGridLayout $cell;

    /**
     * Gives the host the file's natural size.
     */
    protected QSpacerItem $natural;

    /**
     * The file as loaded; null for no file.
     */
    protected ?QPixmap $pixmap = null;

    /**
     * Whether the label has had its first Resize: only then does it have a size to scale to.
     */
    protected bool $laid_out = false;

    /**
     * Sees the label's Resize events. Its closure holds this image weakly: a strong capture
     * would be a cycle through the C++ filter that PHP's collector cannot see.
     */
    protected QEventFilter $resize_filter;

    /**
     * The label size and pixel ratio the shown pixmap was scaled for; null forces the next render.
     * @var array{int, int, float}|null
     */
    protected ?array $rendered_for = null;

    /**
     * @throws WindowException When the file cannot be loaded.
     */
    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, ?string $file)
    {
        parent::__construct($name, $window, $parent, $placement, $file);
        $host = $this->adoptNative(new QWidget());
        $this->cell = new QGridLayout($host);
        $this->cell->setContentsMargins(0, 0, 0, 0);
        $this->natural = new QSpacerItem(0, 0, Policy::PREFERRED, Policy::PREFERRED);
        $this->cell->addItem($this->natural, 0, 0);

        $this->label = new QLabel();
        $this->label->setSizePolicy(Policy::IGNORED, Policy::IGNORED);
        $this->label->setAlignment(AlignmentFlag::CENTER->value);
        $this->cell->addWidget($this->label, 0, 0);

        $self = WeakReference::create($this);
        $this->resize_filter = new QEventFilter(static function (QObject $watched, EventType|int $type) use ($self): bool {
            $self->get()?->resized();

            return false;
        }, [EventType::RESIZE]);
        $this->label->installEventFilter($this->resize_filter);

        $this->applyFile($file);
    }

    /**
     * The host widget: the image's slot in its container.
     * @return QWidget
     */
    public function native(): QWidget
    {
        return $this->native;
    }

    /**
     * The label showing the pixmap.
     * @return QLabel
     */
    public function label(): QLabel
    {
        return $this->label;
    }

    /**
     * An unloadable file leaves the image empty and throws; file() keeps the path asked for.
     * @param string|null $file
     * @return void
     * @throws WindowException
     */
    protected function applyFile(?string $file): void
    {
        $this->pixmap = null;
        $this->rendered_for = null;

        if (! is_null($file)) {
            $pixmap = new QPixmap();
            if (! $pixmap->load($file)) {
                $this->settle();

                throw new WindowException("Image '{$file}' could not be loaded.");
            }
            $this->pixmap = $pixmap;
        }

        $this->settle();
    }

    protected function applyScaling(ImageScaling $scaling): void
    {
        $this->rendered_for = null;
        $this->render();
    }

    /**
     * Size the host from the file and show it.
     * @return void
     */
    protected function settle(): void
    {
        $this->natural->changeSize(
            $this->pixmap?->width() ?? 0,
            $this->pixmap?->height() ?? 0,
            Policy::PREFERRED,
            Policy::PREFERRED,
        );
        $this->cell->invalidate();
        $this->render();
    }

    protected function resized(): void
    {
        $this->laid_out = true;
        $this->render();
    }

    protected function render(): void
    {
        if (is_null($this->pixmap)) {
            $this->label->setPixmap(null);

            return;
        }

        $this->label->setScaledContents($this->scaling === ImageScaling::STRETCH);

        if (! $this->laid_out || $this->scaling === ImageScaling::CENTER || $this->scaling === ImageScaling::STRETCH) {
            $this->label->setPixmap($this->pixmap);
            $this->rendered_for = null;

            return;
        }

        [$width, $height] = $this->label->size();
        $ratio = $this->label->devicePixelRatioF();
        if ([$width, $height, $ratio] === $this->rendered_for) {
            return;
        }
        $this->rendered_for = [$width, $height, $ratio];

        $scaled = $this->pixmap->scaled(
            (int) round($width * $ratio),
            (int) round($height * $ratio),
            $this->scaling === ImageScaling::FIT ? AspectRatioMode::KEEP : AspectRatioMode::KEEP_BY_EXPANDING,
            TransformationMode::SMOOTH_TRANSFORMATION,
        );
        $scaled->setDevicePixelRatio($ratio);
        $this->label->setPixmap($scaled);
    }
}
