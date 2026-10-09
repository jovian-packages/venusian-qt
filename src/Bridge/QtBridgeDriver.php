<?php

namespace Jovian\Toolkits\Qt\Bridge;

use Jovian\Toolkits\Qt\Contracts\Bridge\QtBridgeDriver as BridgeContract;
use Jovian\Toolkits\Qt\Windows\QtMenuBar;
use Jovian\Toolkits\Qt\Windows\QtWindow;
use QMenuBar;
use Surface\Bridge\ToolkitBridgeDriver;
use Surface\Contracts\Bridge\BridgeException;
use Surface\Contracts\Windows\Menus\MenuProfile as MenuProfileContract;
use Surface\Contracts\Windows\ToolkitWindowDriver;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\MenuProfile;

class QtBridgeDriver extends ToolkitBridgeDriver implements BridgeContract, ToolkitWindowDriver
{
    /**
     * Open windows by name; a window leaves when it closes.
     * @var array<string, QtWindow>
     */
    protected array $windows = [];

    /**
     * macOS: the app's bar while no window with a bar of its own is active. Linux has no app-wide bar.
     * @var MenuProfile|null
     */
    protected ?MenuProfile $default_menu = null;

    protected ?QtMenuBar $default_bar = null;

    public function connect(): QtSession
    {
        $this->session ??= new QtSession(
            (string) $this->app->get('config')->get('bridge.qt.application_name', 'Venusian'),
            $this->applicationId(),
        );

        return $this->session->connect();
    }

    /** The desktop identity: config/app.php app.id, the name a packaged build's .desktop file carries. */
    protected function applicationId(): string
    {
        $id = $this->app->get('config')->get('app.id');

        if (! is_string($id) || $id === '') {
            throw new BridgeException("config/app.php has no app.id; add 'id' => env('APP_ID', 'com.venusian.app') under name.");
        }

        return $id;
    }

    public function open(string $name, int $width, int $height, ?MenuProfileContract $menu = null): QtWindow
    {
        if (isset($this->windows[$name])) {
            throw new WindowException("A window named '{$name}' is already open.");
        }

        return $this->windows[$name] = new QtWindow($name, $this->connect(), $this, $width, $height, $this->concrete($menu));
    }

    public function has(string $name): bool
    {
        return isset($this->windows[$name]);
    }

    public function get(string $name): ?QtWindow
    {
        return $this->windows[$name] ?? null;
    }

    /**
     * @return array<string, QtWindow>
     */
    public function all(): array
    {
        return $this->windows;
    }

    public function closeAll(): void
    {
        foreach ($this->windows as $window) {
            $window->close();
        }
    }

    /**
     * macOS: Qt shows a parentless QMenuBar whenever the active window has no bar of its own,
     * or no window is active, so the default bar is one. Linux bars live inside windows.
     * @param MenuProfileContract|null $menu
     * @return void
     */
    public function setDefaultMenuBar(?MenuProfileContract $menu): void
    {
        $menu = $this->concrete($menu);
        // The window manager passes the configured profile on every open: the same profile keeps the bar built.
        if ($menu === $this->default_menu) {
            return;
        }

        $this->default_menu = $menu;

        if (! is_null($this->default_bar)) {
            $this->default_bar->bar()->deleteLater();
            $this->default_bar = null;
        }

        if (! self::appWideBar() || is_null($this->default_menu)) {
            return;
        }

        $this->default_bar = new QtMenuBar($this->connect(), $this->default_menu, new QMenuBar(), null, $this->about(), null);
    }

    /**
     * The default bar while it is built (macOS).
     * @return QtMenuBar|null
     */
    public function defaultMenuBar(): ?QtMenuBar
    {
        return $this->default_bar;
    }

    /**
     * Whether menus live in one app-wide bar (macOS) rather than inside each window.
     * @return bool
     */
    public static function appWideBar(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    /**
     * Forget a window that closed.
     * @param string $name
     * @return void
     */
    public function forget(string $name): void
    {
        unset($this->windows[$name]);
    }

    /**
     * A profile from config/windows.php, through the window manager.
     * @param string $name
     * @return MenuProfile
     * @throws WindowException When no profile is registered under that name.
     */
    public function profile(string $name): MenuProfile
    {
        return $this->app->get('toolkit-windows')->profile($name);
    }

    /**
     * The About identity from config('windows.about').
     * @return array{name?: string|null, version?: string|null, copyright?: string|null}
     */
    public function about(): array
    {
        return (array) $this->app->get('config')->get('windows.about', []);
    }

    /**
     * @param MenuProfileContract|null $menu
     * @return MenuProfile|null
     * @throws WindowException When the profile is not one Surface parsed.
     */
    protected function concrete(?MenuProfileContract $menu): ?MenuProfile
    {
        if (is_null($menu) || $menu instanceof MenuProfile) {
            return $menu;
        }

        throw new WindowException(get_class($menu).' is not a menu profile parsed by Surface\Windows\Menus\MenuProfile.');
    }
}
