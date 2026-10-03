<?php

namespace Jovian\Toolkits\Qt\Windows;

use Jovian\Toolkits\Qt\Bridge\QtBridgeDriver;
use Jovian\Toolkits\Qt\Bridge\QtSession;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\QtPrimitiveFactory;
use QEvent\Type as EventType;
use QEventFilter;
use QMainWindow;
use QMenuBar;
use QObject;
use QVBoxLayout;
use Qt\WidgetAttribute;
use QWidget;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowFocused;
use Surface\Contracts\Windows\Mail\WindowResized;
use Surface\Contracts\Windows\Primitives\TKPrimitiveGroup;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
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

    public function __construct(
        protected readonly string $name,
        protected readonly QtSession $session,
        protected readonly QtBridgeDriver $driver,
        int $width,
        int $height,
        ?MenuProfile $menu,
    ) {
        $this->window = new QMainWindow();
        $this->window->setWindowTitle($name);
        $this->window->resize($width, $height);
        // Qt deletes the window after its close event is accepted.
        $this->window->setAttribute(WidgetAttribute::DELETE_ON_CLOSE);

        $this->central = new QWidget();
        $this->central_layout = new QVBoxLayout($this->central);
        $this->central_layout->setContentsMargins(0, 0, 0, 0);
        $this->central_layout->setSpacing(0);
        $this->window->setCentralWidget($this->central);

        // The filters hold this window weakly: a strong capture would be a cycle through the
        // C++ filter that PHP's collector cannot see, keeping every closed window alive.
        $self = WeakReference::create($this);

        // A burst of resizes within one pump is one WindowResized, with the last size.
        $this->resize_filter = new QEventFilter(static function (QObject $watched, EventType|int $type) use ($self): bool {
            $self->get()?->resized();

            return false;
        }, [EventType::RESIZE]);
        $this->central->installEventFilter($this->resize_filter);

        // false passes every event on: QMainWindow accepts the close, so the mail goes first.
        $this->filter = new QEventFilter(static function (QObject $watched, EventType|int $type) use ($self): bool {
            $self->get()?->filtered($type);

            return false;
        }, [EventType::CLOSE, EventType::WINDOW_ACTIVATE]);
        $this->window->installEventFilter($this->filter);
        // Deleted any other way (a parent, the application going): the same close path.
        QObject::connect($this->window, 'destroyed(QObject*)', fn () => $this->closed());

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

    public function present(): static
    {
        $window = $this->live();
        $window->show();
        $window->raise();
        $window->activateWindow();

        return $this;
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

    /**
     * The native window, for engines that draw into it.
     * @return QMainWindow
     * @throws WindowException Once closed.
     */
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

        return $this->central->size();
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
        match ($type) {
            EventType::CLOSE => $this->closed(),
            EventType::WINDOW_ACTIVATE => $this->session->post(new WindowFocused($this->name)),
            default => null,
        };
    }

    protected function resized(): void
    {
        [$width, $height] = $this->central->size();
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
