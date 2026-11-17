<?php

namespace Jovian\Toolkits\Qt;

use Jovian\Toolkits\Qt\Windows\QtMenuBar;
use Jovian\Toolkits\Qt\Windows\QtMenuKeys;
use QMenuBar;
use ReflectionException;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtFonts;
use Jovian\Toolkits\Qt\Services\QtClipboard;
use Jovian\Toolkits\Qt\Services\QtDesktop;
use Jovian\Toolkits\Qt\Services\QtDialogs;
use Jovian\Toolkits\Qt\Services\QtSystemAccent;
use Surface\Contracts\Toolkits\Drivers\ToolkitWindowDriver;
use Surface\Contracts\Toolkits\Menus\MenuProfile as MenuProfileContract;
use Surface\Contracts\Toolkits\Styling\FontMetrics;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Surface\Contracts\Toolkits\ToolkitWindow;
use Surface\Toolkits\Menus\MenuProfile;
use Surface\Toolkits\Windows\ChildWindows;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\Toolkits\ToolkitBridge;

class QtBridge extends ToolkitBridge implements ToolkitWindowDriver
{
    /**
     * Open windows by name; a window leaves when it closes.
     * @var array<string, QtWindow>
     */
    protected array $windows = [];

    protected ?QtDialogs $dialogs = null;

    protected ?QtClipboard $clipboard = null;

    protected ?QtDesktop $desktop = null;

    protected ?QtSystemAccent $systemAccent = null;

    protected ?QMenuBar $empty_bar = null;

    protected ?QtMenuBar $default_bar = null;

    protected ?QtMenuKeys $menu_keys = null;

    protected string $session_class = QtSession::class;

    /**
     * @throws ReflectionException
     */
    protected function instanceSession(): void
    {
        $session_class  = $this->session_class;
        $this->session ??= new $session_class(
            (string) config('qt.application_name', 'Venusian'),
            $this->applicationId(),
        );
    }

    protected function connected(): void
    {
        // dataChanged() reports another app's copy from the start, not from the app's first clipboard call.
        /** @var QtSession $session */
        $session = $this->session;
        $this->clipboard ??= new QtClipboard($session);
        //$this->menu_keys ??= new QtMenuKeys();
        //$this->button_keys ??= new QtButtonKeys($this);

        //$this->keepGlobalBar();
    }

    /** With a parent, the window is modal to it: a sheet on macOS, a dialog on Linux. */
    public function open(string $name, int $width, int $height, ?MenuProfileContract $menu = null, ?ToolkitWindow $parent = null): QtWindow
    {
        if (isset($this->windows[$name])) {
            throw ToolkitRuntimeException::instanceOpen($name);
        }

        if (! is_null($parent)) {
            ChildWindows::check($this, $parent, $menu);
            $this->dialogs()->refuseChild($parent);
        }

        /** @var QtSession $session */
        $session = $this->connect();

        return $this->windows[$name] = new QtWindow($name, $session, $this, $width, $height, $this->concrete($menu), $parent);
    }

    public function has(string $name): bool
    {
        return isset($this->windows[$name]);
    }

    public function get(string $name): ?QtWindow
    {
        return $this->windows[$name] ?? null;
    }

    /** @return array<string, QtWindow> */
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

    /** A window closed: it leaves the open windows. */
    public function forget(string $name): void
    {
        unset($this->windows[$name]);
    }

    /** Alert and file dialogs, modal to their parent. */
    public function dialogs(): QtDialogs
    {
        /** @var QtSession $session */
        $session = $this->connect();

        return $this->dialogs ??= new QtDialogs($this, $session);
    }

    /** The application's clipboard; the session makes the application first. */
    public function clipboard(): QtClipboard
    {
        $this->connect();

        return $this->clipboard;
    }

    public function desktop(): QtDesktop
    {
        return $this->desktop ??= new QtDesktop();
    }

    /** The palette is the application's: the session connects first. */
    public function systemAccent(): QtSystemAccent
    {
        $this->connect();

        return $this->systemAccent ??= new QtSystemAccent();
    }

    /** Qt's font database needs the QApplication: the session connects first. */
    public function fontMetrics(FontSpec $font): FontMetrics
    {
        $this->connect();

        return QtFonts::metrics($font);
    }

    /**
     * A profile from config/windows.php, through the window manager.
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
     * The application filter that gives keys to menu shortcuts before the focused widget.
     * Made for the first menu bar, so an application with no menus has no filter.
     */
    public function menuKeys(): QtMenuKeys
    {
        $this->connect();

        return $this->menu_keys ??= new QtMenuKeys();
    }

    /**
     * @throws ToolkitRuntimeException When the profile is not one Surface parsed.
     */
    protected function concrete(?MenuProfileContract $menu): ?MenuProfile
    {
        if (is_null($menu) || $menu instanceof MenuProfile) {
            return $menu;
        }

        throw new ToolkitRuntimeException(get_class($menu).' is not a menu profile parsed by Surface\\Toolkits\\Menus\\MenuProfile.');
    }

    /**
     * The desktop identity: config/app.php app.id, the name a packaged build's .desktop file carries.
     * @throws ReflectionException
     */
    protected function applicationId(): string
    {
        $id = config('app.id');

        if (! is_string($id) || $id === '') {
            throw new ToolkitRuntimeException("config/app.php has no app.id; add 'id' => env('APP_ID', 'com.venusian.app') under name.");
        }

        return $id;
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

    /**
     * Whether menus live in one app-wide bar (macOS) rather than inside each window.
     * @return bool
     */
    public static function appWideBar(): bool
    {
        return device_os_family() === 'mac';
    }
}