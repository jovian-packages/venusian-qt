<?php

namespace Jovian\Toolkits\Qt;

use Closure;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\QtPrimitiveFactory;
use Jovian\Toolkits\Qt\Windows\QtMenuBar;
use QEvent;
use QEvent\Type as EventType;
use QEventFilter;
use QMainWindow;
use QMenuBar;
use QObject;
use Qt\WidgetAttribute;
use Qt\WindowModality;
use Qt\WindowType;
use QVBoxLayout;
use QWidget;
use Surface\Contracts\Toolkits\Mail\Windows\WindowClosed;
use Surface\Contracts\Toolkits\Mail\Windows\WindowCloseRequested;
use Surface\Contracts\Toolkits\Mail\Windows\WindowFocused;
use Surface\Contracts\Toolkits\Mail\Windows\WindowResized;
use Surface\Contracts\Toolkits\Mail\Windows\WindowScaleChanged;
use Surface\Contracts\Toolkits\Primitives\PrimitiveFactory;
use Surface\Contracts\Toolkits\Primitives\TKPrimitive;
use Surface\Contracts\Toolkits\Primitives\TKPrimitiveGroup;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\Contracts\Toolkits\ToolkitWindow as WindowContract;
use Surface\Toolkits\Menus\MenuProfile;
use Surface\Toolkits\Windows\ChildWindows;
use Surface\Toolkits\Windows\ToolkitWindow;
use WeakReference;

class QtWindow extends ToolkitWindow
{
    /**
     * The native window while open; null once closed. Qt deletes it after close.
     */
    protected ?QMainWindow $window;

    /**
     * The native's wrapper after close, held so it is not freed inside its own close event.
     */
    protected ?QMainWindow $departed = null;

    /**
     * Where the content container goes: the central widget, below the bar.
     */
    protected QWidget $central;

    /**
     * Holds the content container's widget at the central widget's full size.
     */
    protected QVBoxLayout $central_layout;

    /**
     * Sees the central widget's Resize events: the content area's size.
     */
    protected QEventFilter $resize_filter;

    protected ?PrimitiveFactory $factory = null;

    /** The window's own menu bar; null when it has none. */
    protected ?QtMenuBar $menu = null;

    /**
     * Sees the window's close and activation events.
     */
    protected QEventFilter $filter;

    /** The device pixel ratio last posted, or read at the first show. */
    private float $ratio = 1.0;

    /** Before the first show the window is on no screen of its own: a ratio change then is not the user's. */
    private bool $shown_once = false;

    /** @var WeakReference<TKPrimitive>|null focused on each present(); weak, so a removed primitive is freed */
    protected ?WeakReference $initial_focus = null;

    protected bool $confirming = false;

    /** close() runs: its own close event goes through while the window confirms close. */
    protected bool $closing = false;

    public function __construct(
        protected readonly string $name,
        protected readonly QtSession $session,
        protected readonly QtBridge $driver,
        int $width,
        int $height,
        ?MenuProfile $menu,
        protected readonly ?WindowContract $parent = null,
    ) {
        $this->window = new QMainWindow();
        $this->window->setWindowTitle($name);
        $this->window->resize($width, $height);
        // Qt deletes the window after its close event is accepted.
        $this->window->setAttribute(WidgetAttribute::DELETE_ON_CLOSE);
        if ($parent instanceof self) {
            // setParent keeps the one-argument form in PHP; setWindowFlags then makes the child a window.
            $this->window->setParent($parent->native());
            $this->window->setWindowFlags(device_os_family() === 'mac' ? WindowType::SHEET : WindowType::DIALOG);
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
            // Qt 6.6+ sends the ratio change; Qt 6.4 only the screen change.
            if ($type === EventType::DEVICE_PIXEL_RATIO_CHANGE || $type === EventType::SCREEN_CHANGE_INTERNAL) {
                $self->get()?->scaleChanged();
            }
            if ($type === EventType::SHOW) {
                $self->get()?->firstShown();
            }

            return false;
        });
        $this->ratio = $this->window->devicePixelRatioF();
        $this->window->installEventFilter($this->filter);
        // Deleted any other way (a parent, the application going): the same close path.
        QObject::connect($this->window, 'destroyed(QObject*)', fn () => $this->closed());

        // Not ported yet (deprecated/Windows): the right-click, group-click, canvas click/pointer
        // and window-key filters, and QtFocus.
        if (! is_null($menu)) {
            $this->useMenu($menu, $this->window->menuBar());
        }
    }

    /** WindowScaleChanged when the device pixel ratio differs from the last posted. */
    public function scaleChanged(): void
    {
        $ratio = $this->window->devicePixelRatioF();
        if ($ratio === $this->ratio) {
            return;
        }
        $this->ratio = $ratio;
        if ($this->shown_once) {
            $this->session->post(new WindowScaleChanged($this->name, $ratio));
        }
    }

    /** The first show puts the window on its screen: its ratio from then on is the one changes compare with. */
    public function firstShown(): void
    {
        if (! $this->shown_once) {
            $this->shown_once = true;
            $this->ratio = $this->window->devicePixelRatioF();
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
        $window->show();
        $window->raise();
        $window->activateWindow();
        $initial = $this->initial_focus?->get();
        if (! is_null($initial) && ! $initial->isRemoved() && $initial->native()->focusPolicy() !== \Qt\FocusPolicy::NO_FOCUS) {
            $this->quietly($initial, static fn () => $initial->native()->setFocus());
        }

        return $this;
    }

    /** Null until QtFocus is ported. */
    public function focused(): ?TKPrimitive
    {
        return null;
    }

    /**
     * @throws ToolkitRuntimeException When $primitive belongs to another window.
     */
    public function setInitialFocus(?TKPrimitive $primitive): static
    {
        if (! is_null($primitive) && $primitive->window() !== $this) {
            throw new ToolkitRuntimeException("'{$primitive->path()}' is not in window '{$this->name}'.");
        }
        $this->initial_focus = is_null($primitive) ? null : WeakReference::create($primitive);

        return $this;
    }

    /**
     * Run a focus move that code makes, to $target. With a focus tracker it posts no mail for
     * the move; QtFocus is not ported yet, so the move runs as it is.
     */
    public function quietly(?TKPrimitive $target, Closure $move): void
    {
        $move();
    }

    /** The window's focus as primitives. Null until QtFocus is ported. */
    public function focusTracker(): null
    {
        return null;
    }

    public function isOpen(): bool
    {
        return ! is_null($this->window);
    }

    /**
     * Whether this window is the active one.
     */
    public function isActive(): bool
    {
        return ! is_null($this->window) && $this->window->isActiveWindow();
    }

    /**
     * The close event runs inside close(), so the mail and bookkeeping happen there, the same
     * path as the window manager's close button; Qt deletes the window on the next pump.
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

    public function parent(): ?WindowContract
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
     */
    public function userClose(): void
    {
        $this->window?->close();
    }

    /**
     * @throws ToolkitRuntimeException When the window is a child, or closed.
     */
    public function setMenuBar(string $profile): static
    {
        ChildWindows::refuseMenuBar($this);
        $window = $this->live();
        // A new bar takes the place of the window's bar: Qt deletes the one it had.
        $bar = new QMenuBar();
        $window->setMenuBar($bar);
        $this->useMenu($this->driver->profile($profile), $bar);

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

    public function setRadio(string $group, string $item): static
    {
        $this->menuOrFail()->setRadio($group, $item);

        return $this;
    }

    public function radio(string $group): string
    {
        return $this->menuOrFail()->radio($group);
    }

    public function setItemLabel(string $item, string $label): static
    {
        $this->menuOrFail()->setItemLabel($item, $label);

        return $this;
    }

    public function itemLabel(string $item): string
    {
        return $this->menuOrFail()->itemLabel($item);
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

    /** Where views go: the central widget, below the menu bar. */
    public function contentArea(): QWidget
    {
        return $this->central;
    }

    /**
     * The native window, for engines that draw into it.
     * @throws ToolkitRuntimeException Once closed.
     */
    public function native(): QMainWindow
    {
        return $this->live();
    }

    /**
     * The central widget, where the content container goes.
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

    /**
     * This window's minter, one per window. Typed as the contract so the window loads without
     * loading every primitive class.
     */
    public function factory(): PrimitiveFactory
    {
        return $this->factory ??= new QtPrimitiveFactory($this);
    }

    /**
     * The session the window's primitives post through.
     */
    public function session(): QtSession
    {
        return $this->session;
    }

    /**
     * Apply the content container's align() inside the central widget. For the content container.
     * @param TKPrimitiveGroup&QtNative $content
     */
    public function placeContent(TKPrimitiveGroup&QtNative $content): void
    {
        $this->central_layout->setAlignment($content->native(), $content->qtAlignment());
    }

    /**
     * The content container fills the central widget.
     * @throws ToolkitRuntimeException When the container is not a Qt primitive.
     */
    protected function mountContent(TKPrimitiveGroup $content): void
    {
        if (! $content instanceof QtNative) {
            throw new ToolkitRuntimeException("'{$content->path()}' is not a Qt primitive.");
        }
        $this->central_layout->addWidget($content->native(), 1);
    }

    protected function filtered(EventType|int $type): void
    {
        if ($type === EventType::WINDOW_ACTIVATE) {
            // Qt on macOS loses another app's copy once anything read the clipboard: compare the content.
            $this->driver->clipboard()->compare();
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
     */
    protected function closed(): void
    {
        if (is_null($this->window)) {
            return;
        }

        $this->removeContent();
        // Keep the native's PHP wrapper until this object goes. closed() runs inside the window's
        // own close event filter; an ext-qt before 2026-11-12 deleted the QMainWindow when the
        // last reference dropped there, while Qt was still sending it that event. Qt deletes the
        // window itself after the close (DELETE_ON_CLOSE), so nothing is lost by holding it.
        $this->departed = $this->window;
        $this->window = null;
        $this->session->forgetLatest("window.resized.{$this->name}");
        $this->session->post(new WindowClosed($this->name));
        $this->driver->forget($this->name);
    }

    protected function live(): QMainWindow
    {
        return $this->window ?? throw new ToolkitRuntimeException("Window '{$this->name}' is closed.");
    }

    public function menuBar(): ?QtMenuBar
    {
        return $this->menu;
    }

    /**
     * Qt shows a window's bar inside it on Linux and, while it is active, as the app's bar on macOS.
     */
    protected function useMenu(MenuProfile $menu, QMenuBar $bar): void
    {
        // Menu shortcuts come before the focused widget's own editing keys.
        $this->driver->menuKeys();
        $this->menu = new QtMenuBar($this->session, $menu, $bar, $this->name, $this->driver->about(), $this->window);
    }

    protected function menuOrFail(): QtMenuBar
    {
        return $this->menu ?? throw new ToolkitRuntimeException("Window '{$this->name}' has no menu bar.");
    }
}
