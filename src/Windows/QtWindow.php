<?php

namespace Jovian\Toolkits\Qt\Windows;

use Jovian\Toolkits\Qt\Bridge\QtBridgeDriver;
use Jovian\Toolkits\Qt\Bridge\QtSession;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\QtPrimitiveFactory;
use QEvent;
use QEvent\Type as EventType;
use QEventFilter;
use QMainWindow;
use QMenuBar;
use QObject;
use QVBoxLayout;
use Qt\WidgetAttribute;
use Qt\WindowModality;
use Qt\WindowType;
use QWidget;
use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowFocused;
use Surface\Contracts\Windows\Mail\WindowResized;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use Surface\Contracts\Windows\Primitives\TKPrimitiveGroup;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\ChildWindows;
use Surface\Windows\Menus\MenuProfile;
use Surface\Windows\Primitives\HostsPrimitives;
use WeakReference;

class QtWindow implements ToolkitWindow
{
    use HostsPrimitives;

    /**
     * The native window while open; null once closed. Qt deletes it after close.
     * @var QMainWindow|null
     */
    protected ?QMainWindow $window;

    /**
     * Where the content container goes: the central widget, below the bar.
     * @var QWidget
     */
    protected QWidget $central;

    /**
     * Holds the content container's widget at the central widget's full size.
     * @var QVBoxLayout
     */
    protected QVBoxLayout $central_layout;

    /**
     * Sees the central widget's Resize events: the content area's size.
     * @var QEventFilter
     */
    protected QEventFilter $resize_filter;

    protected ?QtPrimitiveFactory $factory = null;

    /**
     * Sees the window's close and activation events.
     * @var QEventFilter
     */
    protected QEventFilter $filter;

    protected ?QtMenuBar $menu = null;

    protected QtRightClicks $right_clicks;

    protected QtCanvasClicks $canvas_clicks;

    protected QtCanvasPointer $canvas_pointer;

    protected QtFocus $focus;

    protected QtWindowKeys $window_keys;

    /** @var WeakReference<TKPrimitive>|null focused on each present(); weak, so a removed primitive is freed */
    protected ?WeakReference $initial_focus = null;

    protected bool $confirming = false;

    /** close() runs: its own close event goes through while the window confirms close. */
    protected bool $closing = false;

    public function __construct(
        protected readonly string $name,
        protected readonly QtSession $session,
        protected readonly QtBridgeDriver $driver,
        int $width,
        int $height,
        ?MenuProfile $menu,
        protected readonly ?ToolkitWindow $parent = null,
    ) {
        $this->window = new QMainWindow();
        $this->window->setWindowTitle($name);
        $this->window->resize($width, $height);
        // Qt deletes the window after its close event is accepted.
        $this->window->setAttribute(WidgetAttribute::DELETE_ON_CLOSE);
        if ($parent instanceof self) {
            // setParent keeps the one-argument form in PHP; setWindowFlags then makes the child a window.
            $this->window->setParent($parent->native());
            $this->window->setWindowFlags(PHP_OS_FAMILY === 'Darwin' ? WindowType::SHEET : WindowType::DIALOG);
            $this->window->setWindowModality(WindowModality::WINDOW_MODAL);
        }

        $this->central = new QWidget();
        $this->central_layout = new QVBoxLayout($this->central);
        $this->central_layout->setContentsMargins(0, 0, 0, 0);
        $this->central_layout->setSpacing(0);
        $this->window->setCentralWidget($this->central);

        // The filters hold this window weakly: a strong capture would be a cycle through the
        // C++ filter that PHP's collector cannot see, keeping every closed window alive.
        $self = WeakReference::create($this);

        // A burst of resizes within one pump is one WindowResized, with the last size.
        $this->resize_filter = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            if ($event->type() === EventType::RESIZE) {
                $self->get()?->resized();
            }

            return false;
        });
        $this->central->installEventFilter($this->resize_filter);

        // false passes every event on: QMainWindow accepts the close, so the mail goes first. While
        // the window confirms close, the user's close is ignored (Qt keeps a window whose close event
        // is not accepted), posts WindowCloseRequested, and true keeps it from the window.
        $this->filter = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            $type = $event->type();
            if ($type === EventType::CLOSE) {
                return $self->get()?->closeEvent($event) ?? false;
            }
            if ($type === EventType::WINDOW_ACTIVATE) {
                $self->get()?->filtered($type);
            }

            return false;
        });
        $this->window->installEventFilter($this->filter);
        // Deleted any other way (a parent, the application going): the same close path.
        QObject::connect($this->window, 'destroyed(QObject*)', fn () => $this->closed());
        $this->right_clicks = new QtRightClicks($this, $this->window, $this->central, $session);
        $this->canvas_clicks = new QtCanvasClicks($this, $session);
        $this->canvas_pointer = new QtCanvasPointer($this, $session);
        $this->focus = new QtFocus($this);
        $this->window_keys = new QtWindowKeys($this, $this->window);

        if (! is_null($menu)) {
            $this->useMenu($menu, $this->window->menuBar());
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function title(): string
    {
        return $this->live()->windowTitle();
    }

    public function setTitle(string $title): static
    {
        $this->live()->setWindowTitle($title);

        return $this;
    }

    /** A child presents its parent first when the parent is not shown. */
    public function present(): static
    {
        $window = $this->live();
        if ($this->parent instanceof self && ! $this->parent->native()->isVisible()) {
            $this->parent->present();
        }
        // Showing the window gives its first focusable widget focus: present() posts no focus mail.
        $this->focus->awaitFirstFocus($window);
        $window->show();
        $window->raise();
        $window->activateWindow();
        $initial = $this->initial_focus?->get();
        if (! is_null($initial) && ! $initial->isRemoved() && $initial->native()->focusPolicy() !== \Qt\FocusPolicy::NO_FOCUS) {
            $this->focus->quietly($initial, static fn () => $initial->native()->setFocus());
        }

        return $this;
    }

    public function focused(): ?TKPrimitive
    {
        return $this->focus->holder();
    }

    /**
     * @throws WindowException When $primitive belongs to another window.
     */
    public function setInitialFocus(?TKPrimitive $primitive): static
    {
        if (! is_null($primitive) && $primitive->window() !== $this) {
            throw new WindowException("'{$primitive->path()}' is not in window '{$this->name}'.");
        }
        $this->initial_focus = is_null($primitive) ? null : WeakReference::create($primitive);

        return $this;
    }

    /** The window's focus as primitives; primitives move focus through it. */
    public function focusTracker(): QtFocus
    {
        return $this->focus;
    }

    public function isOpen(): bool
    {
        return ! is_null($this->window);
    }

    /**
     * Whether this window is the active one, and so (macOS) whose bar the app shows.
     * @return bool
     */
    public function isActive(): bool
    {
        return ! is_null($this->window) && $this->window->isActiveWindow();
    }

    /**
     * The close event runs inside close(), so the mail and bookkeeping happen there, the same
     * path as the window manager's close button; Qt deletes the window on the next pump.
     * @return void
     */
    public function close(): void
    {
        if (is_null($this->window)) {
            return;
        }
        ChildWindows::childOf($this->driver, $this)?->close();
        $this->driver->dialogs()->closeOn($this);
        $this->closing = true;
        $this->window?->close();
        $this->closing = false;
    }

    public function parent(): ?ToolkitWindow
    {
        return $this->parent;
    }

    public function confirmClose(bool $on = true): static
    {
        $this->confirming = $on;

        return $this;
    }

    public function isConfirmingClose(): bool
    {
        return $this->confirming;
    }

    /**
     * The user's close from the keyboard (Esc in a child with no cancel button): the same path as
     * the title-bar button, through the close event.
     * @return void
     */
    public function userClose(): void
    {
        $this->window?->close();
    }

    /**
     * A new bar replaces the window's; QMainWindow deletes the old one.
     * @param string $profile
     * @return $this
     */
    public function setMenuBar(string $profile): static
    {
        $window = $this->live();
        ChildWindows::refuseMenuBar($this);
        $menu = $this->driver->profile($profile);
        $bar = new QMenuBar();
        $window->setMenuBar($bar);
        $this->useMenu($menu, $bar);

        return $this;
    }

    public function setToggle(string $item, bool $on): static
    {
        $this->menuOrFail()->setToggle($item, $on);

        return $this;
    }

    public function isToggled(string $item): bool
    {
        return $this->menuOrFail()->isToggled($item);
    }

    public function setItemEnabled(string $item, bool $enabled): static
    {
        $this->menuOrFail()->setItemEnabled($item, $enabled);

        return $this;
    }

    public function isItemEnabled(string $item): bool
    {
        return $this->menuOrFail()->isItemEnabled($item);
    }

    /**
     * The native window, for engines that draw into it.
     * @return QMainWindow
     * @throws WindowException Once closed.
     */
    /** The window's right clicks, posted as mail. */
    public function rightClicks(): QtRightClicks
    {
        return $this->right_clicks;
    }

    /** The window's canvas clicks, posted as mail. */
    public function canvasClicks(): QtCanvasClicks
    {
        return $this->canvas_clicks;
    }

    /** The window's canvas presses, drags, releases and wheels, posted as mail. */
    public function canvasPointer(): QtCanvasPointer
    {
        return $this->canvas_pointer;
    }

    /** Where views go: the central widget, below the menu bar. */
    public function contentArea(): QWidget
    {
        return $this->central;
    }

    public function native(): QMainWindow
    {
        return $this->live();
    }

    /**
     * The central widget, where the content container goes.
     * @return QWidget
     */
    public function centralWidget(): QWidget
    {
        return $this->central;
    }

    public function size(): array
    {
        $this->live();
        $size = $this->central->size();

        return [$size->width(), $size->height()];
    }

    public function factory(): QtPrimitiveFactory
    {
        return $this->factory ??= new QtPrimitiveFactory($this);
    }

    /**
     * The session the window's primitives post through.
     * @return QtSession
     */
    public function session(): QtSession
    {
        return $this->session;
    }

    /**
     * Apply the content container's align() inside the central widget. For the content container.
     * @param TKPrimitiveGroup&QtNative $content
     * @return void
     */
    public function placeContent(TKPrimitiveGroup&QtNative $content): void
    {
        $this->central_layout->setAlignment($content->native(), $content->qtAlignment());
    }

    /**
     * The content container fills the central widget.
     * @param TKPrimitiveGroup $content
     * @return void
     * @throws WindowException When the container is not a Qt primitive.
     */
    protected function mountContent(TKPrimitiveGroup $content): void
    {
        if (! $content instanceof QtNative) {
            throw new WindowException("'{$content->path()}' is not a Qt primitive.");
        }
        $this->central_layout->addWidget($content->native(), 1);
    }

    public function menuBar(): ?QtMenuBar
    {
        return $this->menu;
    }

    /**
     * Qt shows a window's bar inside it on Linux and, while it is active, as the app's bar on macOS.
     * @param MenuProfile $menu
     * @param QMenuBar $bar
     * @return void
     */
    protected function useMenu(MenuProfile $menu, QMenuBar $bar): void
    {
        $this->menu = new QtMenuBar($this->session, $menu, $bar, $this->name, $this->driver->about(), $this->window);
    }

    /**
     * @param EventType|int $type
     * @return void
     */
    protected function filtered(EventType|int $type): void
    {
        if ($type === EventType::WINDOW_ACTIVATE) {
            $this->session->post(new WindowFocused($this->name));
        }
    }

    /**
     * The window's close event. Confirming, and not inside close(): ignore it, post
     * WindowCloseRequested, and answer true so the window does not see it. Else the close path.
     */
    protected function closeEvent(QEvent $event): bool
    {
        // A parent with an open child or dialog keeps the user's close silently: Qt's modal block
        // holds only once the child shows, and only for window-system closes.
        if (! $this->closing && (! is_null(ChildWindows::childOf($this->driver, $this)) || ! is_null($this->driver->dialogs()->openOn($this)))) {
            $event->ignore();

            return true;
        }
        if ($this->confirming && ! $this->closing) {
            $event->ignore();
            $this->session->post(new WindowCloseRequested($this->name));

            return true;
        }
        $this->closed();

        return false;
    }

    protected function resized(): void
    {
        $size = $this->central->size();
        [$width, $height] = [$size->width(), $size->height()];
        $this->session->postLatest("window.resized.{$this->name}", new WindowResized($this->name, $width, $height));
    }

    /**
     * The one close path: tear the content tree down while the native window still holds it,
     * drop the window's pending resize mail, post the close, let the driver forget the window.
     * @return void
     */
    protected function closed(): void
    {
        if (is_null($this->window)) {
            return;
        }

        $this->right_clicks->detach();
        $this->canvas_clicks->detach();
        $this->canvas_pointer->detach();
        $this->focus->detach();
        $this->window_keys->detach();
        $this->removeContent();
        $this->window = null;
        $this->session->forgetLatest("window.resized.{$this->name}");
        $this->session->post(new WindowClosed($this->name));
        $this->driver->forget($this->name);
    }

    protected function live(): QMainWindow
    {
        return $this->window ?? throw new WindowException("Window '{$this->name}' is closed.");
    }

    protected function menuOrFail(): QtMenuBar
    {
        return $this->menu ?? throw new WindowException("Window '{$this->name}' has no menu bar.");
    }
}
