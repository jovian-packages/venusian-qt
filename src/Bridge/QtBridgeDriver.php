<?php

namespace Jovian\Toolkits\Qt\Bridge;

use Jovian\Toolkits\Qt\Contracts\Bridge\QtBridgeDriver as BridgeContract;
use Jovian\Toolkits\Qt\Services\QtClipboard;
use Jovian\Toolkits\Qt\Services\QtDesktop;
use Jovian\Toolkits\Qt\Services\QtDialogs;
use Jovian\Toolkits\Qt\Windows\QtButtonKeys;
use Jovian\Toolkits\Qt\Windows\QtMenuBar;
use Jovian\Toolkits\Qt\Windows\QtMenuKeys;
use Jovian\Toolkits\Qt\Windows\QtWindow;
use QMenuBar;
use Surface\Bridge\ToolkitBridgeDriver;
use Surface\Contracts\Bridge\BridgeException;
use Surface\Contracts\Windows\Menus\MenuProfile as MenuProfileContract;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\ToolkitWindowDriver;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\ChildWindows;
use Surface\Windows\Menus\MenuProfile;
use Surface\Windows\Menus\MenuStates;

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

    /** The states of the default profile's nodes, on every platform. */
    protected ?MenuStates $default_states = null;

    protected ?QtDialogs $dialogs = null;

    protected ?QtClipboard $clipboard = null;

    protected ?QtDesktop $desktop = null;

    /** Gives keys to menu shortcuts before a focused text widget; made on the first connect. */
    protected ?QtMenuKeys $menu_keys = null;

    /** Gives Return, Enter and Esc to the windows' default and cancel buttons; made on the first connect. */
    protected ?QtButtonKeys $button_keys = null;

    /**
     * macOS, with no default profile: an empty bar with no parent. Qt shows the bar with no parent
     * for a focused window without its own, such as a child; with none it keeps the last bar, and
     * the parent's items stay enabled under its child (probe 2026-10-20).
     */
    protected ?QMenuBar $empty_bar = null;

    public function connect(): QtSession
    {
        $this->session ??= new QtSession(
            (string) $this->app->get('config')->get('bridge.qt.application_name', 'Venusian'),
            $this->applicationId(),
        );

        $session = $this->session->connect();
        $this->menu_keys ??= new QtMenuKeys();
        $this->button_keys ??= new QtButtonKeys($this);
        $this->keepGlobalBar();

        return $session;
    }

    /** The application filter that gives Return, Enter and Esc to the role holders. */
    public function buttonKeys(): QtButtonKeys
    {
        $this->connect();

        return $this->button_keys;
    }

    /**
     * The bar with no parent that Qt shows for a window without its own (macOS): the default bar,
     * or the empty bar when no default profile is set. Null elsewhere.
     */
    public function globalMenuBar(): ?QMenuBar
    {
        return $this->default_bar?->bar() ?? $this->empty_bar;
    }

    /** macOS: an empty bar with no parent exactly while there is no default bar. */
    protected function keepGlobalBar(): void
    {
        if (! self::appWideBar() || is_null($this->session)) {
            return;
        }
        if (is_null($this->default_bar)) {
            $this->empty_bar ??= new QMenuBar();
        } elseif (! is_null($this->empty_bar)) {
            $this->empty_bar->deleteLater();
            $this->empty_bar = null;
        }
    }

    /** The application filter that gives keys to menu shortcuts first. */
    public function menuKeys(): QtMenuKeys
    {
        $this->connect();

        return $this->menu_keys;
    }

    /**
     * Set the state of a node of the default profile, on every platform; it shows where a default
     * bar shows (macOS). Posts nothing.
     * @throws WindowException
     */
    public function setItemEnabled(string $item, bool $enabled): void
    {
        $this->defaultStates()->set($item, $enabled);
        $this->default_bar?->setItemEnabled($item, $enabled);
    }

    /** @throws WindowException */
    public function isItemEnabled(string $item): bool
    {
        return $this->defaultStates()->isEnabled($item);
    }

    /**
     * The default bar's toggles, radios and labels: kept in its states, which the bar (macOS) shares. Post nothing.
     * @throws WindowException
     */
    public function setToggle(string $item, bool $on): void
    {
        $this->defaultStates()->setToggle($item, $on);
        $this->default_bar?->setToggle($item, $on);
    }

    /** @throws WindowException */
    public function isToggled(string $item): bool
    {
        return $this->defaultStates()->toggle($item);
    }

    /** @throws WindowException */
    public function setRadio(string $group, string $item): void
    {
        $this->defaultStates()->setRadio($group, $item);
        $this->default_bar?->setRadio($group, $item);
    }

    /** @throws WindowException */
    public function radio(string $group): string
    {
        return $this->defaultStates()->radio($group);
    }

    /** @throws WindowException */
    public function setItemLabel(string $item, string $label): void
    {
        $this->defaultStates()->setLabel($item, $label);
        $this->default_bar?->setItemLabel($item, $label);
    }

    /** @throws WindowException */
    public function itemLabel(string $item): string
    {
        return $this->defaultStates()->label($item);
    }

    protected function defaultStates(): MenuStates
    {
        return $this->default_states ?? throw new WindowException('No default menu bar profile is set.');
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

    /** With a parent, the window is window-modal to it: a sheet on macOS, a dialog elsewhere. */
    public function open(string $name, int $width, int $height, ?MenuProfileContract $menu = null, ?ToolkitWindow $parent = null): QtWindow
    {
        if (isset($this->windows[$name])) {
            throw new WindowException("A window named '{$name}' is already open.");
        }
        if (! is_null($parent)) {
            ChildWindows::check($this, $parent, $menu);
            $this->dialogs()->refuseChild($parent);
        }

        return $this->windows[$name] = new QtWindow($name, $this->connect(), $this, $width, $height, $this->concrete($menu), $parent);
    }

    /** Message boxes and file dialogs, window-modal to their parent. */
    public function dialogs(): QtDialogs
    {
        return $this->dialogs ??= new QtDialogs($this, $this->connect());
    }

    /** The application's clipboard; the session makes the application first. */
    public function clipboard(): QtClipboard
    {
        $this->connect();

        return $this->clipboard ??= new QtClipboard();
    }

    public function desktop(): QtDesktop
    {
        return $this->desktop ??= new QtDesktop();
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
        $this->default_states = is_null($menu) ? null : new MenuStates($menu);

        if (! is_null($this->default_bar)) {
            $this->default_bar->bar()->deleteLater();
            $this->default_bar = null;
        }

        if (self::appWideBar() && ! is_null($this->default_menu)) {
            // The bar builds from the driver's states and keeps them, the user's choices included.
            $this->default_bar = new QtMenuBar($this->connect(), $this->default_menu, new QMenuBar(), null, $this->about(), null, $this->default_states);
        }
        $this->keepGlobalBar();
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
