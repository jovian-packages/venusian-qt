<?php

namespace Jovian\Toolkits\Qt\Primitives;

use CALayer;
use CAMetalLayer;
use CGSize;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use NSLayoutConstraint;
use NSView;
use QCoreApplication;
use QEventLoop\ProcessEventsFlag;
use QGridLayout;
use QImage;
use QImage\Format;
use QLabel;
use QOpenGLPainter;
use QPixmap;
use QSizePolicy\Policy;
use QSurfaceFormat;
use QSurfaceFormat\OpenGLContextProfile;
use QSurfaceFormat\RenderableType;
use QSurface\SurfaceType;
use QVulkanInstance;
use QWidget;
use QWindow;
use SDL_Window;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\DamageTrackingFramebuffer;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKCanvas;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A canvas over a host QWidget holding one QLabel. The label is Ignored×Ignored with scaled
 * contents, so the layout sizes the canvas and the pixmap is stretched over it, whatever the
 * framebuffer's size. Each present() copies the framebuffer's RGBA8 bytes into a QImage in the
 * RGBX8888 format (the fourth byte ignored, so opaque) and shows it as the label's pixmap; an
 * ext-fb framebuffer is copied by address, never through a PHP string.
 *
 * On macOS with ext-appkit and ext-metal, the canvas lends a Metal layer: a QWindow with a Metal
 * surface in a window container over the label; the layer Qt gives that window is read through
 * ext-appkit's NSView::fromPointer(). Qt hosts its CAMetalLayer as the content sublayer of a
 * container layer it sets on the view, so the Metal layer is the view's layer or one of its
 * sublayers. With ext-sdl3 too, it lends an SDL window over Qt's NSWindow, SDL_GPU's swapchain
 * view moved into such a Metal QWindow's view.
 *
 * With ext-vulkan loaded and a Qt built with Vulkan, it lends a Vulkan surface first: a QWindow
 * with a Vulkan surface in a window container over the label, given a QVulkanInstance over the
 * borrower's own VkInstance, and the VkSurfaceKHR Qt makes for it. Qt loads the Vulkan loader by
 * name; on macOS Homebrew's lies outside dyld's search path and is reached only when
 * QT_VULKAN_LIB names it, so there the kind is offered when a probe instance can be made.
 *
 * With ext-opengl loaded it lends a GL context on every platform: a QOpenGLPainter (a
 * QOpenGLWidget calling PHP from paintGL()) over the label, its context read back once Qt has
 * made it. present() schedules a paint, and paintGL() copies the borrower's frame into the
 * widget's framebuffer object, so a paint Qt asks for on its own copies the last frame again.
 */
class QtCanvas extends TKCanvas implements QtNative
{
    use QtPrimitive;

    protected QLabel $label;

    protected QGridLayout $cell;

    protected ?QWindow $metal_window = null;

    protected ?QWidget $container = null;

    /** The Vulkan QWindow while a Vulkan surface is lent. */
    protected ?QWindow $vulkan_window = null;

    /** Qt's instance over the borrower's VkInstance, held while the window uses it. */
    protected ?QVulkanInstance $vulkan_instance = null;

    /** Whether Qt can make a Vulkan instance here: probed once a process. */
    private static ?bool $vulkan = null;

    /** True while reclaim() runs: the lent surface's release is the canvas's own then. */
    private bool $reclaiming = false;

    /** The SDL window over Qt's NSWindow, held while lent. */
    protected ?SDL_Window $sdl_window = null;

    /** The Metal QWindow's view SDL's swapchain view is moved into. */
    protected ?NSView $sdl_host = null;

    /** Qt's NSWindow content view, where SDL_GPU puts its swapchain view. */
    protected ?NSView $sdl_content = null;

    /** SDL's swapchain view, moved into the canvas. */
    protected ?NSView $sdl_view = null;

    /** @var list<int> The content view's subviews before SDL claimed the window, by address. */
    protected array $sdl_before = [];

    /** @var list<NSLayoutConstraint> */
    protected array $sdl_pins = [];

    /** @var array<int, array{SDL_Window, NSView}> SDL windows reclaimed while a device still held them, with SDL's swapchain view. */
    protected static array $sdl_parked = [];

    /** The GL view while a GL context is lent; null otherwise. */
    protected ?QOpenGLPainter $gl_widget = null;

    /** paintGL() calls the GL view has run, for tests. */
    protected int $renders = 0;

    /** SDL_PROP_WINDOW_CREATE_COCOA_WINDOW_POINTER's value: ext-sdl3 0.10.0 defines the view property's constant, not this one. */
    private const string SDL_COCOA_WINDOW = 'SDL.window.create.cocoa.window';

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

    /** The Vulkan window standing in for the label while a Vulkan surface is lent. */
    public function vulkanWindow(): ?QWindow
    {
        return $this->vulkan_window;
    }

    /** The GL view standing in for the label while a GL context is lent. */
    public function glWidget(): ?QOpenGLPainter
    {
        return $this->gl_widget;
    }

    /** paintGL() calls the GL view has run. */
    public function renders(): int
    {
        return $this->renders;
    }

    /** Device pixels per logical pixel on the widget's screen. */
    protected function nativeScale(): float
    {
        return $this->native->devicePixelRatioF();
    }

    protected function applyPixels(string $rgba8, int $width, int $height, array $damage): void
    {
        $this->label->setPixmap(QPixmap::fromImage(new QImage($rgba8, $width, $height, $width * 4, Format::RGBX8888)));
    }

    protected function applyAddress(int $address, int $width, int $height, int $stride, array $damage): void
    {
        $this->label->setPixmap(QPixmap::fromImage(new QImage($address, $width, $height, $stride, Format::RGBX8888)));
    }

    /**
     * A Vulkan surface first where Qt can make one for ext-vulkan's instance. On macOS with
     * ext-appkit and ext-metal: a Metal layer, and an SDL window when ext-sdl3 is loaded too.
     * A GL context wherever ext-opengl is loaded.
     */
    public function surfaces(): array
    {
        $kinds = self::lendsVulkan() ? [SurfaceKind::VULKAN_SURFACE] : [];
        if (PHP_OS_FAMILY === 'Darwin' && class_exists(NSView::class) && class_exists(CAMetalLayer::class)) {
            $kinds[] = SurfaceKind::METAL_LAYER;
            if (function_exists('SDL_CreateWindowWithProperties')) {
                $kinds[] = SurfaceKind::SDL_WINDOW;
            }
        }
        if (self::hasOpenGL()) {
            $kinds[] = SurfaceKind::GL_CONTEXT;
        }

        return $kinds;
    }

    /**
     * A Vulkan surface is taken back when its borrower releases it first (a device let go before
     * its engine): the device has dropped its swapchain by then, and Qt's surface must go while
     * the device's instance still exists.
     */
    public function lend(SurfaceKind $kind, SurfaceBorrower $to): LentSurface
    {
        $surface = parent::lend($kind, $to);
        if ($kind === SurfaceKind::VULKAN_SURFACE) {
            $surface->onRelease(function () use ($surface): void {
                if (! $this->reclaiming && $this->lent === $surface) {
                    $this->reclaim();
                }
            });
        }

        return $surface;
    }

    public function reclaim(): void
    {
        $this->reclaiming = true;
        try {
            parent::reclaim();
        } finally {
            $this->reclaiming = false;
        }
    }

    /** Parked SDL windows whose device has let go are destroyed first. */
    public function present(): static
    {
        self::sweepSdlWindows();

        return parent::present();
    }

    protected function makeSurface(SurfaceKind $kind, array $handles): array
    {
        return match ($kind) {
            SurfaceKind::SDL_WINDOW => $this->makeSdlWindow(),
            SurfaceKind::GL_CONTEXT => $this->makeGLWidget(),
            SurfaceKind::VULKAN_SURFACE => $this->makeVulkanWindow($handles),
            default => $this->makeMetalLayer(),
        };
    }

    protected function removeSurface(SurfaceKind $kind): void
    {
        if ($kind === SurfaceKind::GL_CONTEXT) {
            $this->removeGLWidget();

            return;
        }
        if ($kind === SurfaceKind::VULKAN_SURFACE) {
            $this->removeVulkanWindow();

            return;
        }
        if ($kind === SurfaceKind::SDL_WINDOW) {
            $this->removeSdlWindow();
        }
        $this->removeMetalWindow();
    }

    /**
     * A QOpenGLPainter over the label. Qt makes its context when the widget is first shown in a
     * shown window: the window must be presented, and Qt is pumped (at most 2 s) until it is.
     *
     * @return array<string, int>
     * @throws WindowException Before the window is presented, or when Qt makes no context.
     */
    private function makeGLWidget(): array
    {
        if (! $this->native->isVisible()) {
            throw new WindowException("Canvas '{$this->path()}' has no GL context yet: present the window first.");
        }
        // Qt makes the widget's context current to paint while it is shown here, and the view's context is
        // read with it current: whatever was current before is put back at the end (Qt's own notion cleared first).
        $previous = self::currentContext();
        $widget = new QOpenGLPainter(function (QOpenGLPainter $widget): void {
            $this->renders++;
            $this->renderLent();
        }, $this->native);
        $widget->setFormat(self::glFormat());
        $this->cell->addWidget($widget, 0, 0);
        $this->label->hide();
        $widget->show();
        $until = microtime(true) + 2.0;
        while (! $widget->isValid() && microtime(true) < $until) {
            QCoreApplication::processEvents(ProcessEventsFlag::ALL_EVENTS, 10);
        }
        if (! $widget->isValid()) {
            $this->gl_widget = $widget;
            $this->removeGLWidget();
            self::restoreContext($previous);

            throw new WindowException("Canvas '{$this->path()}' has no GL context: Qt made none for its QOpenGLWidget.");
        }

        $widget->makeCurrent();
        try {
            $handles = $this->contextHandles();
        } finally {
            $widget->doneCurrent();
            self::restoreContext($previous);
        }
        $this->gl_widget = $widget;

        return $handles;
    }

    /**
     * The context the opengl engine draws in: OpenGL 4.1 core on macOS (Qt's default there is a
     * legacy 2.1 context), OpenGL ES 3 elsewhere (the Pi's default desktop context is GL 3.1,
     * GLSL 1.40).
     */
    private static function glFormat(): QSurfaceFormat
    {
        $format = new QSurfaceFormat();
        if (PHP_OS_FAMILY === 'Darwin') {
            $format->setRenderableType(RenderableType::OPEN_GL);
            $format->setVersion(4, 1);
            $format->setProfile(OpenGLContextProfile::CORE_PROFILE);
        } else {
            $format->setRenderableType(RenderableType::OPEN_GLES);
            $format->setVersion(3, 0);
        }

        return $format;
    }

    /** The GL view goes; the label shows the framebuffer again. */
    private function removeGLWidget(): void
    {
        $this->gl_widget?->hide();
        $this->gl_widget?->deleteLater();
        $this->gl_widget = null;
        $this->label->show();
    }

    /**
     * paintGL(), the widget's context current and its framebuffer object bound: the borrower
     * copies its frame in, and the frame's epoch begins anew. Called with no borrower
     * (reclaimed, a paint still queued) the widget is cleared to black.
     */
    protected function renderLent(): void
    {
        $surface = $this->lent;
        $borrower = $this->borrower;
        if (is_null($surface) || is_null($borrower) || $surface->released()) {
            \glClearColor(0.0, 0.0, 0.0, 1.0);
            \glClear(GL_COLOR_BUFFER_BIT);

            return;
        }
        $borrower->presentInto($surface);
        $frame = $borrower->framebuffer();
        if ($frame instanceof DamageTrackingFramebuffer) {
            $frame->beginEpoch();
        }
    }

    /**
     * The current context, read back through ext-opengl: CGL on macOS, EGL (with its display) elsewhere.
     *
     * @return array<string, int>
     * @throws WindowException When no context is current.
     */
    private function contextHandles(): array
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $context = \CGLGetCurrentContext() ?? throw new WindowException("Canvas '{$this->path()}': the GL view made no context current.");

            return ['context' => $context->pointer()];
        }
        $context = \eglGetCurrentContext() ?? throw new WindowException("Canvas '{$this->path()}': the GL view made no context current.");
        $display = \eglGetCurrentDisplay() ?? throw new WindowException("Canvas '{$this->path()}': the GL view's context has no display.");

        return ['context' => $context->pointer(), 'display' => $display->pointer()];
    }

    /**
     * Whatever context is current now, to put back after reading the view's: the CGL context on
     * macOS; on EGL the display, draw and read surfaces with it (null when none is current).
     *
     * @return array<int, mixed>|null
     */
    private static function currentContext(): ?array
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return [\CGLGetCurrentContext()];
        }
        $context = \eglGetCurrentContext();

        return is_null($context) ? null : [\eglGetCurrentDisplay(), \eglGetCurrentSurface(EGL_DRAW), \eglGetCurrentSurface(EGL_READ), $context];
    }

    /** @param array<int, mixed>|null $previous What currentContext() answered; null leaves nothing current. */
    private static function restoreContext(?array $previous): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            \CGLSetCurrentContext($previous[0] ?? null);

            return;
        }
        if (! is_null($previous)) {
            \eglMakeCurrent(...$previous);
        }
    }

    /**
     * ext-opengl is loaded, so the context can be read back and the paint callback can clear; off
     * macOS, Qt's GL is EGL too (Wayland, eglfs, or xcb with QT_XCB_GL_INTEGRATION=xcb_egl). On
     * X11 Qt uses GLX by default, whose context ext-opengl cannot read.
     */
    private static function hasOpenGL(): bool
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return function_exists('CGLGetCurrentContext');
        }
        if (! function_exists('eglGetCurrentContext')) {
            return false;
        }
        $platform = \QGuiApplication::platformName();

        return str_starts_with($platform, 'wayland') || $platform === 'eglfs'
            || ($platform === 'xcb' && getenv('QT_XCB_GL_INTEGRATION') === 'xcb_egl');
    }

    /**
     * Whether Qt was built with Vulkan, ext-vulkan is loaded, Qt would load the same Vulkan loader
     * ext-vulkan calls, and Qt can load it: a probe instance of Qt's own is made and destroyed once
     * a process. Before the application exists Qt has no platform to ask: false then, and asked
     * again later.
     */
    public static function lendsVulkan(): bool
    {
        if (! self::sameLoader()) {
            return false;
        }
        if (! is_null(self::$vulkan)) {
            return self::$vulkan;
        }
        if (! class_exists(QVulkanInstance::class) || ! extension_loaded('vulkan')) {
            return self::$vulkan = false;
        }
        $probe = new QVulkanInstance();
        try {
            $made = $probe->create();
        } catch (\QtException) {
            return false;
        }
        $probe->destroy();

        return self::$vulkan = $made;
    }

    /**
     * Qt resolves its Vulkan calls through the loader it loads itself: QT_VULKAN_LIB, else the
     * system's by name. A VkInstance from ext-vulkan is valid only in the loader that made it, so
     * a QT_VULKAN_LIB that names another file is refused; unset, the name resolves to the system
     * loader ext-vulkan links on Linux, and to nothing on macOS (Homebrew's lies outside dyld's
     * search path).
     */
    private static function sameLoader(): bool
    {
        if (! function_exists('vk_loader_path')) {
            return false;
        }
        $named = getenv('QT_VULKAN_LIB');
        if ($named === false || $named === '') {
            return PHP_OS_FAMILY !== 'Darwin';
        }
        $ours = vk_loader_path();

        return ! is_null($ours) && realpath($named) !== false && realpath($named) === realpath($ours);
    }

    /**
     * A QWindow with a Vulkan surface in a window container over the label, its QVulkanInstance
     * over the borrower's VkInstance (its 'instance' handle): the surface Qt makes is one the
     * borrower's device presents into. Qt makes it once the window exists and is exposed: the
     * window must be presented, and Qt is pumped (at most 2 s) until it is.
     *
     * @param  array<string, int>  $handles
     * @return array{surface: int}
     * @throws WindowException Before the window is presented, without an instance, or when Qt makes no surface.
     */
    private function makeVulkanWindow(array $handles): array
    {
        if (! $this->native->isVisible()) {
            throw new WindowException("Canvas '{$this->path()}' has no Vulkan surface yet: present the window first.");
        }
        $instance = new QVulkanInstance();
        $instance->setVkInstance($handles['instance'] ?? throw new WindowException("Canvas '{$this->path()}': the borrower lends no 'instance' to make a Vulkan surface against."));
        if (! $instance->create()) {
            throw new WindowException("Canvas '{$this->path()}': Qt could not adopt the borrower's Vulkan instance (VkResult {$instance->errorCode()}).");
        }
        $window = new QWindow();
        $window->setSurfaceType(SurfaceType::VULKAN_SURFACE);
        $window->setVulkanInstance($instance);
        $container = QWidget::createWindowContainer($window, $this->native);
        $this->cell->addWidget($container, 0, 0);
        $this->label->hide();
        $container->show();
        $window->create();
        $this->vulkan_instance = $instance;
        $this->vulkan_window = $window;
        $this->container = $container;
        $until = microtime(true) + 2.0;
        while (! $window->isExposed() && microtime(true) < $until) {
            QCoreApplication::processEvents(ProcessEventsFlag::ALL_EVENTS, 10);
        }
        $surface = QVulkanInstance::surfaceForWindow($window);
        if ($surface === 0) {
            $this->removeVulkanWindow();

            throw new WindowException("Canvas '{$this->path()}': Qt made no Vulkan surface for the window.");
        }

        return ['surface' => $surface];
    }

    /**
     * The window's platform window goes first, and with it the VkSurfaceKHR Qt made (the
     * borrower's device let go of its swapchain when the surface was released, and its VkInstance
     * is still alive); then the window gives up Qt's instance and the instance is destroyed, so
     * nothing a caller may still hold reaches Qt's surface again. Then the container; the label
     * shows the framebuffer again.
     */
    private function removeVulkanWindow(): void
    {
        $this->vulkan_window?->destroy();
        $this->vulkan_window?->setVulkanInstance(null);
        $this->vulkan_instance?->destroy();
        $this->vulkan_instance = null;
        $this->vulkan_window = null;
        $this->container?->hide();
        $this->container?->deleteLater();
        $this->container = null;
        $this->label->show();
    }

    /** @return array{layer: int} */
    private function makeMetalLayer(): array
    {
        $window = $this->makeMetalWindow();
        $layer = self::metalLayerOf(NSView::fromPointer($window->winId())->layer());
        if (is_null($layer)) {
            $this->removeMetalWindow();

            throw new WindowException("Canvas '{$this->path()}' got no layer from Qt's Metal window.");
        }

        return ['layer' => $layer->pointer()];
    }

    /** A QWindow with a Metal surface in a window container over the label: a native view in the canvas's cell. */
    private function makeMetalWindow(): QWindow
    {
        $window = new QWindow();
        $window->setSurfaceType(SurfaceType::METAL_SURFACE);
        $container = QWidget::createWindowContainer($window, $this->native);
        $this->cell->addWidget($container, 0, 0);
        $this->label->hide();
        $container->show();
        $window->create();
        $this->metal_window = $window;
        $this->container = $container;

        return $window;
    }

    /**
     * An SDL window over Qt's NSWindow, wrapped as SDL wraps an existing
     * window: its content view stays the content view. SDL 3.4 makes any view
     * it is handed its window's content view, so Qt's views are never handed
     * over. SDL_GPU puts its swapchain view in the content view when a device
     * claims the window; presentLent() moves it into the canvas's Metal
     * QWindow view and pins it to that view's edges. SDL's video subsystem
     * comes up here (reference-counted) and is never quit by the canvas.
     *
     * @return array{window: int}
     */
    private function makeSdlWindow(): array
    {
        if (! SDL_InitSubSystem(SDL_INIT_VIDEO)) {
            throw new WindowException("Canvas '{$this->path()}' could not start SDL video: ".SDL_GetError());
        }
        self::sweepSdlWindows();
        $view = NSView::fromPointer($this->makeMetalWindow()->winId());
        $host = $view->window();
        $content = $host?->contentView();
        if (is_null($host) || is_null($content)) {
            $this->removeMetalWindow();

            throw new WindowException("Canvas '{$this->path()}' found no NSWindow around Qt's Metal window.");
        }
        $this->sdl_before = array_map(fn (NSView $subview): int => $subview->pointer(), $content->subviews());
        // SDL hooks its listener in as the next responder of the window and its content view; the
        // links are put back at once, so events still reach Qt, and SDL's close leaves them.
        $chain = [$host->nextResponder(), $content->nextResponder()];
        $props = SDL_CreateProperties();
        SDL_SetPointerProperty($props, self::SDL_COCOA_WINDOW, $host->pointer());
        SDL_SetPointerProperty($props, SDL_PROP_WINDOW_CREATE_COCOA_VIEW_POINTER, $content->pointer());
        SDL_SetBooleanProperty($props, SDL_PROP_WINDOW_CREATE_HIGH_PIXEL_DENSITY_BOOLEAN, true);
        $window = SDL_CreateWindowWithProperties($props);
        SDL_DestroyProperties($props);
        $host->setNextResponder($chain[0]);
        $content->setNextResponder($chain[1]);
        if (is_null($window)) {
            $this->removeMetalWindow();

            throw new WindowException("Canvas '{$this->path()}' could not wrap Qt's window in an SDL window: ".SDL_GetError());
        }
        $this->sdl_window = $window;
        $this->sdl_host = $view;
        $this->sdl_content = $content;

        return ['window' => $window->pointer()];
    }

    /** SDL's swapchain view into the canvas, once a device has claimed the window; its drawable at the canvas's pixel size. */
    protected function presentLent(LentSurface $surface, SurfaceBorrower $borrower): static
    {
        // GL frames are copied inside paintGL(): presenting schedules one.
        if (! is_null($this->gl_widget)) {
            $this->gl_widget->update();

            return $this;
        }
        if ($surface->kind === SurfaceKind::SDL_WINDOW) {
            $this->holdSdlView();
        }

        return parent::presentLent($surface, $borrower);
    }

    /**
     * The view SDL_GPU added to the content view when a device claimed the
     * window: the one subview that was not there before the lend.
     */
    private function claimedSdlView(): ?NSView
    {
        foreach ($this->sdl_content?->subviews() ?? [] as $view) {
            if (! in_array($view->pointer(), $this->sdl_before, true)) {
                return $view;
            }
        }

        return null;
    }

    private function holdSdlView(): void
    {
        if (is_null($this->sdl_view)) {
            $view = $this->claimedSdlView();
            if (is_null($view) || is_null($this->sdl_host)) {
                return;
            }
            $view->removeFromSuperview();
            $this->sdl_host->addSubview($view);
            $view->setTranslatesAutoresizingMaskIntoConstraints(false);
            $this->sdl_pins = [
                $view->leadingAnchor()->constraintEqualToAnchor($this->sdl_host->leadingAnchor()),
                $view->trailingAnchor()->constraintEqualToAnchor($this->sdl_host->trailingAnchor()),
                $view->topAnchor()->constraintEqualToAnchor($this->sdl_host->topAnchor()),
                $view->bottomAnchor()->constraintEqualToAnchor($this->sdl_host->bottomAnchor()),
            ];
            NSLayoutConstraint::activateConstraints($this->sdl_pins);
            $this->sdl_view = $view;
        }
        // SDL sizes the drawable only when its window's pixel size changes; the canvas can change size inside an unchanged window.
        [$width, $height] = $this->pixelSize();
        CAMetalLayer::fromPointer($this->sdl_view->layer()->pointer())->setDrawableSize(new CGSize((float) $width, (float) $height));
    }

    /**
     * The SDL window goes once no device holds it. SDL requires a window
     * released from its GPU device before it is destroyed, and the lend is
     * reclaimed before the borrower's device lets go: while SDL's swapchain
     * view is still in place the window is parked, its view hidden back in
     * the content view (the Metal QWindow it sat in is about to go), and the
     * window is destroyed by the first sweep (the session's pump, a canvas
     * present or SDL lend) after SDL takes the view back.
     */
    private function removeSdlWindow(): void
    {
        NSLayoutConstraint::deactivateConstraints($this->sdl_pins);
        $this->sdl_pins = [];
        $view = $this->sdl_view ?? $this->claimedSdlView();
        if (! is_null($this->sdl_window)) {
            if (! is_null($view) && ! is_null($view->superview()) && ! is_null($this->sdl_content)) {
                $view->setHidden(true);
                $view->removeFromSuperview();
                $this->sdl_content->addSubview($view);
                self::$sdl_parked[] = [$this->sdl_window, $view];
            } else {
                SDL_DestroyWindow($this->sdl_window);
            }
        }
        $this->sdl_window = null;
        $this->sdl_view = null;
        $this->sdl_host = null;
        $this->sdl_content = null;
        $this->sdl_before = [];
    }

    /**
     * Destroy every parked SDL window whose swapchain view SDL has removed (its device let go of
     * it). The session calls this every pump; a canvas, at every present and SDL lend.
     */
    public static function sweepSdlWindows(): void
    {
        foreach (self::$sdl_parked as $at => [$window, $view]) {
            if (is_null($view->superview())) {
                SDL_DestroyWindow($window);
                unset(self::$sdl_parked[$at]);
            }
        }
    }

    /** The CAMetalLayer Qt made: $layer itself, or the sublayer Qt's container layer hosts it as. */
    protected static function metalLayerOf(?CALayer $layer): ?CALayer
    {
        if (is_null($layer) || $layer->isKindOfClass('CAMetalLayer')) {
            return $layer;
        }
        foreach ($layer->sublayers() as $sublayer) {
            if ($sublayer->isKindOfClass('CAMetalLayer')) {
                return $sublayer;
            }
        }

        return null;
    }

    /** The container goes; the label shows the framebuffer again. */
    private function removeMetalWindow(): void
    {
        $this->container?->hide();
        $this->container?->deleteLater();
        $this->container = null;
        $this->metal_window = null;
        $this->label->show();
    }
}
