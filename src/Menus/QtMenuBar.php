<?php

namespace Jovian\Toolkits\Qt\Menus;

use Closure;
use QMenu;
use QAction;
use QWidget;
use QMenuBar;
use QObject;
use QLineEdit;
use QMetaObject;
use QtException;
use QApplication;
use QPlainTextEdit;
use WeakReference;
use RuntimeException;
use Qt\ConnectionType;
use QAction\MenuRole;
use Jovian\Toolkits\Qt\QtWindow;
use Jovian\Toolkits\Qt\QtSession;
use Surface\Toolkits\Menus\Shortcut;
use Surface\Toolkits\Menus\Runtime\ToolkitMenuBar;
use Surface\Contracts\Toolkits\ToolkitPoweredWindow;
use Surface\Contracts\Toolkits\Menus\BarNode;
use Surface\Contracts\Toolkits\Menus\BarNodeKind;
use Surface\Contracts\Toolkits\Menus\StandardKind;

/**
 * A QMenuBar per window, and a parentless one for when none is focused. Qt shows the bar
 * itself: inside the window on Linux, and on macOS in the system bar, the active window's.
 *
 * Every native is a child of the bar and is deleted when its node goes, so a node keeps its
 * natives when it moves to another menu.
 *
 * The macOS application menu is Qt's. Qt fills it from the actions that carry a menu role,
 * so the nodes of the application's own menu get one: About, Preferences and Quit their own,
 * every other one the application-specific role. Qt orders them (About, Preferences, the
 * application-specific ones as they were added, then Services, Hide, Hide Others, Show All
 * of its own, and Quit), and labels About, Preferences and Quit itself. Its own Quit is
 * hidden while the application menu has none. Elsewhere on macOS, and everywhere on Linux,
 * an action has no role and stays where it was put.
 */
class QtMenuBar extends ToolkitMenuBar
{
    protected QMenuBar $bar;

    /** @var array<string, QtMenuNode> */
    protected array $nodes = [];

    /** @var list<string> the bar's menus, in order */
    protected array $top = [];

    protected readonly bool $mac;

    /** macOS: a menu that never shows, last in the bar, holding $no_quit. */
    protected ?QMenu $roles = null;

    /** macOS: an invisible action with the Quit role. While it is in the bar, Qt shows no Quit. */
    protected ?QAction $no_quit = null;

    protected bool $hides_quit = false;

    public function __construct(QtSession $session, ?ToolkitPoweredWindow $window = null, ?bool $mac = null)
    {
        parent::__construct($session, $window);

        $this->mac = $mac ?? device_os_family() === 'mac';
        $this->bar = $window instanceof QtWindow ? $window->native()->menuBar() : new QMenuBar();

        if ($this->mac) {
            $this->roles = new QMenu('', $this->bar);
            $this->bar->insertAction(null, $this->roles->menuAction());
            $this->no_quit = new QAction('', $this->bar);
            $this->no_quit->setMenuRole(MenuRole::QUIT_ROLE);
            QMetaObject::invokeMethod($this->no_quit, 'setVisible', ConnectionType::DIRECT_CONNECTION, false);
            $this->hideQuitUnlessWritten();
        }
    }

    public function bar(): QMenuBar
    {
        return $this->bar;
    }

    /**
     * The natives of one node: one, a choice's one per option, or none.
     *
     * @return list<QAction>
     */
    public function natives(string $uuid): array
    {
        return $this->node($uuid)->natives;
    }

    /**
     * The role each native of a node carries.
     */
    public function role(string $uuid): MenuRole
    {
        return $this->roleOf($this->node($uuid));
    }

    public function hidesQuit(): bool
    {
        return $this->hides_quit;
    }

    public function insert(?string $parent, int $index, BarNode $node): void
    {
        $this->hostOf($parent);    // throws when $parent is no menu
        $made = new QtMenuNode($node, $parent);

        match ($node->kind) {
            BarNodeKind::SEPARATOR => $this->makeSeparator($made),
            BarNodeKind::MENU => $this->makeMenu($made, $node),
            BarNodeKind::CHOICE => $this->makeChoice($made, $node),
            BarNodeKind::STANDARD => $this->makeStandard($made, $node),
            BarNodeKind::ITEM, BarNodeKind::TOGGLE => $this->makeItem($made, $node),
        };

        $this->nodes[$node->uuid] = $made;
        $siblings = &$this->siblings($parent);
        array_splice($siblings, $index, 0, [$node->uuid]);
        unset($siblings);

        $this->applyRole($made);
        $this->applyShortcut($made, $node->shortcut);
        $this->applyMark($made);
        $this->applyEnabled($made, $node->enabled);
        // Before the node's own Quit goes in: Qt shows the Quit it is given only once the hidden one is out.
        $this->hideQuitUnlessWritten();
        $this->place($made, $index);
    }

    public function remove(string $uuid): void
    {
        $node = $this->node($uuid);
        $this->takeOut($node);

        $siblings = &$this->siblings($node->parent);
        array_splice($siblings, (int) array_search($uuid, $siblings, true), 1);
        unset($siblings);

        $this->forget($uuid);
        $this->hideQuitUnlessWritten();
    }

    public function move(string $uuid, ?string $parent, int $index): void
    {
        $node = $this->node($uuid);
        $this->hostOf($parent);
        $this->takeOut($node);

        $from = &$this->siblings($node->parent);
        array_splice($from, (int) array_search($uuid, $from, true), 1);
        unset($from);

        $node->parent = $parent;
        $to = &$this->siblings($parent);
        array_splice($to, $index, 0, [$uuid]);
        unset($to);

        $this->applyRole($node);
        $this->place($node, $index);
        $this->hideQuitUnlessWritten();
    }

    public function change(string $uuid, array $changes): void
    {
        $node = $this->node($uuid);

        if (array_key_exists('label', $changes) && $node->kind !== BarNodeKind::CHOICE && $node->kind !== BarNodeKind::SEPARATOR) {
            $label = self::literal($changes['label']);
            is_null($node->submenu) ? ($node->natives[0] ?? null)?->setText($label) : $node->submenu->setTitle($label);
        }
        if (array_key_exists('enabled', $changes)) {
            $this->applyEnabled($node, $changes['enabled']);
        }
        if (array_key_exists('on', $changes) || array_key_exists('chosen', $changes)) {
            $node->on = array_key_exists('on', $changes) ? $changes['on'] : $node->on;
            $node->chosen = array_key_exists('chosen', $changes) ? $changes['chosen'] : $node->chosen;
            $this->applyMark($node);
        }
        if (array_key_exists('shortcut', $changes)) {
            $this->applyShortcut($node, $changes['shortcut']);
        }
    }

    /**
     * Qt shows a window's bar when the window is the active one, and the parentless bar when
     * none is: there is nothing to install.
     */
    public function show(): void {}

    public function release(): void
    {
        foreach ($this->top as $uuid) {
            $this->forget($uuid);
        }
        foreach (array_filter([$this->no_quit, $this->roles]) as $native) {
            self::delete($native);
        }

        [$this->nodes, $this->top, $this->roles, $this->no_quit, $this->hides_quit] = [[], [], null, null, false];
    }

    /**
     * ext-qt binds no call that minimizes a window or makes it full screen.
     */
    public function supports(StandardKind $kind): bool
    {
        return ! in_array($kind, [StandardKind::MINIMIZE, StandardKind::FULLSCREEN], true);
    }

    /**
     * A native was chosen. Qt has flipped a checkable one already: the mark goes back to what
     * topside last gave, and topside changes it through change().
     *
     * @internal
     */
    public function triggered(string $uuid, ?string $option, bool $checked): void
    {
        if (is_null($node = $this->nodes[$uuid] ?? null)) {
            return;
        }

        if ($node->kind === BarNodeKind::TOGGLE || $node->kind === BarNodeKind::CHOICE) {
            $this->applyMark($node);
            $this->activated($uuid, $node->kind === BarNodeKind::TOGGLE ? $checked : $option);

            return;
        }

        if ($node->kind === BarNodeKind::STANDARD && ! $node->replaced && $this->does($node)) {
            return;
        }

        $this->activated($uuid);
    }

    /**
     * Run an edit kind on the focused widget: Qt's own slot of a line edit or a plain text edit.
     * Whether one took it.
     */
    public static function edit(?QWidget $focus, StandardKind $kind): bool
    {
        if (! $kind->isEdit() || (! $focus instanceof QLineEdit && ! $focus instanceof QPlainTextEdit)) {
            return false;
        }

        match ($kind) {
            StandardKind::UNDO => $focus->undo(),
            StandardKind::REDO => $focus->redo(),
            StandardKind::CUT => $focus->cut(),
            StandardKind::COPY => $focus->copy(),
            StandardKind::PASTE => $focus->paste(),
            // These widgets hold plain text only: their paste is already paste and match style.
            StandardKind::PASTE_AND_MATCH_STYLE => $focus->paste(),
            StandardKind::SELECT_ALL => $focus->selectAll(),
        };

        return true;
    }

    /**
     * Menu text as Qt shows it literally: it reads "&" as the mark of a mnemonic.
     */
    public static function literal(string $text): string
    {
        return str_replace('&', '&&', $text);
    }

    /**
     * What Qt does by itself for a standard item. False when the kind is the application's to
     * answer (Quit, and an About with no panel).
     */
    protected function does(QtMenuNode $node): bool
    {
        if ($node->standard->isEdit()) {
            self::edit(QApplication::focusWidget(), $node->standard);

            return true;
        }

        if ($node->standard === StandardKind::CLOSE_WINDOW) {
            if ($this->window instanceof QtWindow && $this->window->isOpen()) {
                $this->window->native()->close();
            }

            return true;
        }

        if ($node->standard === StandardKind::ABOUT && ! is_null($node->panel)) {
            $over = $this->window instanceof QtWindow && $this->window->isOpen() ? $this->window->native() : null;
            $this->qt()->showAbout($node->panel, $over);

            return true;
        }

        return false;
    }

    protected function makeSeparator(QtMenuNode $made): void
    {
        $line = new QAction('', $this->bar);
        $line->setSeparator(true);
        $made->natives = [$line];
    }

    protected function makeMenu(QtMenuNode $made, BarNode $node): void
    {
        $made->submenu = new QMenu(self::literal($node->label), $this->bar);
        $made->natives = [$made->submenu->menuAction()];
    }

    protected function makeChoice(QtMenuNode $made, BarNode $node): void
    {
        foreach ($node->options as $value => $label) {
            $native = new QAction(self::literal((string) $label), $this->bar);
            $native->setCheckable(true);
            QObject::connect($native, 'triggered(bool)', $this->runs($node->uuid, (string) $value));
            $made->natives[] = $native;
            $made->options[] = (string) $value;
        }
    }

    protected function makeStandard(QtMenuNode $made, BarNode $node): void
    {
        if (! $node->replaced && $node->standard->isMacOnly()) {
            if ($this->merges($made->parent)) {
                return;    // Qt puts its own in the application menu
            }
            $made->inert = true;    // Qt has no call for it anywhere else
        }

        $this->makeItem($made, $node);
    }

    protected function makeItem(QtMenuNode $made, BarNode $node): void
    {
        $native = new QAction(self::literal($node->label), $this->bar);
        $native->setCheckable($node->kind === BarNodeKind::TOGGLE);
        QObject::connect($native, 'triggered(bool)', $this->runs($node->uuid));
        $made->natives = [$native];
    }

    /**
     * What a native's signal runs. It holds the bar weakly: the bar holds the native.
     */
    protected function runs(string $uuid, ?string $option = null): Closure
    {
        $bar = WeakReference::create($this);

        return static function (bool $checked = false) use ($bar, $uuid, $option): void {
            $bar->get()?->triggered($uuid, $option, $checked);
        };
    }

    protected function applyRole(QtMenuNode $node): void
    {
        if ($node->kind === BarNodeKind::SEPARATOR) {
            return;
        }

        foreach ($node->natives as $native) {
            $native->setMenuRole($this->roleOf($node));
        }
    }

    protected function roleOf(QtMenuNode $node): MenuRole
    {
        if (! $this->merges($node->parent)) {
            return MenuRole::NO_ROLE;
        }

        return match ($node->standard) {
            StandardKind::ABOUT => MenuRole::ABOUT_ROLE,
            StandardKind::PREFERENCES => MenuRole::PREFERENCES_ROLE,
            StandardKind::QUIT => MenuRole::QUIT_ROLE,
            default => MenuRole::APPLICATION_SPECIFIC_ROLE,
        };
    }

    /**
     * Whether what is put under $parent goes to Qt's macOS application menu.
     */
    protected function merges(?string $parent): bool
    {
        return $this->mac && ! is_null($parent) && $this->node($parent)->application;
    }

    protected function applyEnabled(QtMenuNode $node, bool $enabled): void
    {
        foreach ($node->natives as $native) {
            $native->setEnabled($enabled && ! $node->inert);
        }
    }

    protected function applyMark(QtMenuNode $node): void
    {
        foreach ($node->natives as $at => $native) {
            match ($node->kind) {
                BarNodeKind::TOGGLE => $native->setChecked($node->on === true),
                BarNodeKind::CHOICE => $native->setChecked(! is_null($node->chosen) && $node->options[$at] === $node->chosen),
                default => null,
            };
        }
    }

    protected function applyShortcut(QtMenuNode $node, ?Shortcut $shortcut): void
    {
        if (in_array($node->kind, [BarNodeKind::MENU, BarNodeKind::CHOICE, BarNodeKind::SEPARATOR], true)) {
            return;
        }

        ($node->natives[0] ?? null)?->setShortcut(QtShortcut::sequence($shortcut, $this->mac));
    }

    /**
     * Put $node's natives in its menu so it is the $index-th of its siblings: before the first
     * native of the next one that has any.
     *
     * Qt adds an action to the macOS application menu after the ones already there, whatever
     * it was put before. So there the ones after it are taken out and put back, in order.
     */
    protected function place(QtMenuNode $node, int $index): void
    {
        $host = $this->hostOf($node->parent);
        $after = array_slice($this->siblings($node->parent), $index + 1);

        $before = is_null($node->parent) ? $this->roles?->menuAction() : null;
        foreach ($after as $uuid) {
            if ($natives = $this->node($uuid)->natives) {
                $before = $natives[0];
                break;
            }
        }

        foreach ($node->natives as $native) {
            $host->insertAction($before, $native);
        }

        if (! $this->merges($node->parent)) {
            return;
        }

        foreach ($after as $uuid) {
            foreach ($this->node($uuid)->natives as $native) {
                $host->removeAction($native);
                $host->insertAction(null, $native);
            }
        }
    }

    protected function takeOut(QtMenuNode $node): void
    {
        $host = $this->hostOf($node->parent);
        foreach ($node->natives as $native) {
            $host->removeAction($native);
        }
    }

    /**
     * Drop a node and everything under it, and delete their natives.
     */
    protected function forget(string $uuid): void
    {
        $node = $this->node($uuid);
        foreach ($node->children as $child) {
            $this->forget($child);
        }

        // A menu deletes its own menuAction().
        foreach (is_null($node->submenu) ? $node->natives : [$node->submenu] as $native) {
            self::delete($native);
        }
        unset($this->nodes[$uuid]);
    }

    /**
     * Qt's own Quit shows while the application menu holds no Quit; this takes it away, and
     * gives way to the application's.
     */
    protected function hideQuitUnlessWritten(): void
    {
        if (is_null($this->roles)) {
            return;
        }

        $written = false;
        foreach ($this->nodes as $node) {
            $written = $written || ($node->standard === StandardKind::QUIT && $node->natives !== [] && $this->merges($node->parent));
        }

        if ($written === $this->hides_quit) {
            $written ? $this->roles->removeAction($this->no_quit) : $this->roles->insertAction(null, $this->no_quit);
            $this->hides_quit = ! $written;
        }
    }

    /**
     * Deleted in Qt's next pass. A native whose window Qt deleted already is gone with it.
     */
    private static function delete(QObject $native): void
    {
        try {
            $native->deleteLater();
        } catch (QtException) {
        }
    }

    /**
     * @return list<string>
     */
    protected function &siblings(?string $parent): array
    {
        if (is_null($parent)) {
            return $this->top;
        }

        $node = $this->node($parent);

        return $node->children;
    }

    /**
     * The bar, or the menu a node is.
     */
    protected function hostOf(?string $parent): QWidget
    {
        if (is_null($parent)) {
            return $this->bar;
        }

        return $this->node($parent)->submenu
            ?? throw new RuntimeException("Menu node '{$parent}' is not a menu: nothing goes inside it.");
    }

    protected function node(string $uuid): QtMenuNode
    {
        return $this->nodes[$uuid] ?? throw new RuntimeException("This bar has no menu node '{$uuid}'.");
    }

    protected function qt(): QtSession
    {
        /** @var QtSession */
        return $this->session;
    }
}
