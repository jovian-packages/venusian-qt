<?php

namespace Jovian\Toolkits\Qt\Windows;

use Jovian\Toolkits\Qt\Bridge\QtBridgeDriver;
use Jovian\Toolkits\Qt\Bridge\QtSession;
use QEvent\Type as EventType;
use QEventFilter;
use QMainWindow;
use QMenuBar;
use QObject;
use Qt\WidgetAttribute;
use QWidget;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowFocused;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\MenuProfile;

class QtWindow implements ToolkitWindow
{
    /**
     * The native window while open; null once closed. Qt deletes it after close.
     * @var QMainWindow|null
     */
    protected ?QMainWindow $window;

    /**
     * Where views go: the central widget, below the bar.
     * @var QWidget
     */
    protected QWidget $content;

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

        $this->content = new QWidget();
        $this->window->setCentralWidget($this->content);

        // false passes every event on: QMainWindow accepts the close, so the mail goes first.
        $this->filter = new QEventFilter(function (QObject $watched, EventType|int $type): bool {
            match ($type) {
                EventType::CLOSE => $this->closed(),
                EventType::WINDOW_ACTIVATE => $this->session->post(new WindowFocused($this->name)),
                default => null,
            };

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
     * The central widget, where views go.
     * @return QWidget
     */
    public function content(): QWidget
    {
        return $this->content;
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
     * The one close path: the native window is going, post it, let the driver forget it.
     * @return void
     */
    protected function closed(): void
    {
        if (is_null($this->window)) {
            return;
        }

        $this->window = null;
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
