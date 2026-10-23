<?php

namespace Jovian\Toolkits\Qt\Windows;

use Jovian\Toolkits\Qt\Bridge\QtSession;
use QAction;
use QAction\MenuRole as QtMenuRole;
use QApplication;
use QKeySequence;
use QKeySequence\SequenceFormat;
use QLineEdit;
use QMenu;
use QMenuBar;
use QObject;
use QPlainTextEdit;
use QWidget;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\Mail\QuitRequested;
use Surface\Contracts\Windows\Menus\MenuRole;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\Accelerator;
use Surface\Windows\Menus\MenuItem;
use Surface\Windows\Menus\MenuProfile;
use Surface\Windows\Menus\MenuStates;

/**
 * A menu profile built once into a QMenuBar: folders become menus, items QActions whose
 * triggered(bool) posts mail (edit roles instead call the focused text widget's slot);
 * toggles are checkable. About and Quit carry Qt's menu roles,
 * so macOS moves them into the application menu; every other item has no role, so Qt's
 * text heuristic never moves one. About shows a non-modal box.
 */
class QtMenuBar
{
    /**
     * Node actions by profile id (a folder's: its menu's menuAction()).
     * @var array<string, QAction>
     */
    protected array $actions = [];

    /**
     * Toggle actions by profile id.
     * @var array<string, QAction>
     */
    protected array $toggles = [];

    /** The own and effective state of each node. */
    protected readonly MenuStates $states;

    /**
     * @param QtSession $session
     * @param MenuProfile $profile
     * @param QMenuBar $bar the bar to fill: a window's, or a parentless one for the app's default bar (macOS)
     * @param string|null $window the window it belongs to; null for the app's default bar, whose items post '' (Quit posts null)
     * @param array{name?: string|null, version?: string|null, copyright?: string|null} $about
     * @param QWidget|null $parent the window About is shown over
     */
    public function __construct(
        protected readonly QtSession $session,
        protected readonly MenuProfile $profile,
        protected readonly QMenuBar $bar,
        protected readonly ?string $window,
        protected readonly array $about,
        protected readonly ?QWidget $parent,
    ) {
        $this->states = new MenuStates($profile);
        foreach ($profile->folders as $folder) {
            $menu = $bar->addMenu($folder->label);
            $this->actions[$folder->id] = $menu->menuAction();
            $this->fill($menu, $folder);
        }
        foreach (array_keys($profile->nodes()) as $id) {
            $this->action($id)->setEnabled($this->states->effective($id));
        }
    }

    /** The Qt name of each accelerator key that is not its own character or upper-case letter. */
    public const array QT_NAMES = [
        'up' => 'Up', 'down' => 'Down', 'left' => 'Left', 'right' => 'Right', 'home' => 'Home', 'end' => 'End',
        'page_up' => 'PgUp', 'page_down' => 'PgDown',
        'space' => 'Space', 'enter' => 'Return', 'tab' => 'Tab', 'escape' => 'Esc', 'backspace' => 'Backspace', 'delete' => 'Del',
    ];

    /**
     * The shortcut as Qt's portable text, in Qt's own modifier order. On macOS Qt's Ctrl is Command
     * and Qt's Meta is Control.
     */
    public static function shortcut(Accelerator $keys, bool $macos = PHP_OS_FAMILY === 'Darwin'): string
    {
        $value = $keys->key->value;
        $key = self::QT_NAMES[$value] ?? match (true) {
            str_starts_with($value, 'f') && strlen($value) > 1 => strtoupper($value),
            default => strtoupper((string) array_search($keys->key, Accelerator::CHARACTERS, true)),
        };
        $ctrl = $macos ? $keys->command(true) : $keys->control(false);
        $meta = $macos ? $keys->control(true) : $keys->super(false);

        return ($meta ? 'Meta+' : '').($ctrl ? 'Ctrl+' : '').($keys->alt ? 'Alt+' : '').($keys->shift ? 'Shift+' : '').$key;
    }

    /**
     * Set the state of a node; the node and the nodes below it show their effective state. Posts nothing.
     * @throws WindowException
     */
    public function setItemEnabled(string $item, bool $enabled): void
    {
        $this->states->set($item, $enabled);
        foreach ($this->states->subtree($item) as $id) {
            $this->action($id)->setEnabled($this->states->effective($id));
        }
    }

    /** @throws WindowException */
    public function isItemEnabled(string $item): bool
    {
        return $this->states->isEnabled($item);
    }

    public function bar(): QMenuBar
    {
        return $this->bar;
    }

    /**
     * One item's action, by its profile id.
     * @param string $id
     * @return QAction
     * @throws WindowException
     */
    public function action(string $id): QAction
    {
        return $this->actions[$id] ?? throw new WindowException("No node '{$id}' in menu profile '{$this->profile->name}'.");
    }

    /**
     * setChecked emits toggled, never triggered, so this posts nothing.
     * @param string $item
     * @param bool $on
     * @return void
     */
    public function setToggle(string $item, bool $on): void
    {
        $this->toggle($item)->setChecked($on);
    }

    public function isToggled(string $item): bool
    {
        return $this->toggle($item)->isChecked();
    }

    protected function toggle(string $item): QAction
    {
        return $this->toggles[$item] ?? throw new WindowException("No toggle item '{$item}' in menu profile '{$this->profile->name}'.");
    }

    protected function fill(QMenu $menu, MenuItem $folder): void
    {
        foreach ($folder->items as $item) {
            if ($item->separator) {
                $menu->addSeparator();
                continue;
            }

            if ($item->isFolder()) {
                $sub = $menu->addMenu($item->label);
                $this->actions[$item->id] = $sub->menuAction();
                $this->fill($sub, $item);
                continue;
            }

            $action = $menu->addAction($item->label);
            $action->setMenuRole(match ($item->role) {
                MenuRole::ABOUT => QtMenuRole::ABOUT_ROLE,
                MenuRole::QUIT => QtMenuRole::QUIT_ROLE,
                default => QtMenuRole::NO_ROLE,
            });

            if (! is_null($item->keys)) {
                $action->setShortcut(QKeySequence::fromString(self::shortcut($item->keys), SequenceFormat::PORTABLE_TEXT));
            }

            if ($item->toggle) {
                $action->setCheckable(true);
                $action->setChecked($item->on);
                $this->toggles[$item->id] = $action;
            }

            QObject::connect($action, 'triggered(bool)', $this->handlerFor($item));
            $this->actions[$item->id] = $action;
        }
    }

    /** Run an edit role on the focused widget: Qt's own slot on a line edit or plain text edit; whether one took it. */
    public static function edit(?QWidget $focus, MenuRole $role): bool
    {
        if (! $focus instanceof QLineEdit && ! $focus instanceof QPlainTextEdit) {
            return false;
        }
        match ($role) {
            MenuRole::UNDO => $focus->undo(),
            MenuRole::REDO => $focus->redo(),
            MenuRole::CUT => $focus->cut(),
            MenuRole::COPY => $focus->copy(),
            MenuRole::PASTE => $focus->paste(),
            MenuRole::SELECT_ALL => $focus->selectAll(),
            default => null,
        };

        return $role->isEdit();
    }

    /**
     * What triggered(bool) runs: the user chose the item, and for a toggle Qt has flipped it already.
     * @param MenuItem $item
     * @return callable
     */
    protected function handlerFor(MenuItem $item): callable
    {
        $window = $this->window ?? '';

        if ($item->toggle) {
            return fn (bool $checked) => $this->session->post(new MenuToggled($window, $item->id, $checked));
        }

        if ($item->role?->isEdit() === true) {
            return fn () => self::edit(QApplication::focusWidget(), $item->role);
        }

        return match ($item->role) {
            MenuRole::ABOUT => function (): void {
                $this->session->showAbout($this->about, $this->parent);
            },
            MenuRole::QUIT => fn () => $this->session->post(new QuitRequested($this->window)),
            default => fn () => $this->session->post(new MenuActivated($window, $item->id)),
        };
    }
}
