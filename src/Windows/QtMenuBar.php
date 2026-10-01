<?php

namespace Jovian\Toolkits\Qt\Windows;

use Jovian\Toolkits\Qt\Bridge\QtSession;
use QAction;
use QAction\MenuRole as QtMenuRole;
use QMenu;
use QMenuBar;
use QObject;
use QWidget;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\Mail\QuitRequested;
use Surface\Contracts\Windows\Menus\MenuRole;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\MenuItem;
use Surface\Windows\Menus\MenuProfile;

/**
 * A menu profile built once into a QMenuBar: folders become menus, items QActions whose
 * triggered(bool) posts mail; toggles are checkable. About and Quit carry Qt's menu roles,
 * so macOS moves them into the application menu; every other item has no role, so Qt's
 * text heuristic never moves one. About shows a non-modal box.
 */
class QtMenuBar
{
    /**
     * Item actions by profile id.
     * @var array<string, QAction>
     */
    protected array $actions = [];

    /**
     * Toggle actions by profile id.
     * @var array<string, QAction>
     */
    protected array $toggles = [];

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
        foreach ($profile->folders as $folder) {
            $this->fill($bar->addMenu($folder->label), $folder);
        }
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
        return $this->actions[$id] ?? throw new WindowException("No item '{$id}' in menu profile '{$this->profile->name}'.");
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
                $this->fill($menu->addMenu($item->label), $item);
                continue;
            }

            $action = $menu->addAction($item->label);
            $action->setMenuRole(match ($item->role) {
                MenuRole::ABOUT => QtMenuRole::ABOUT_ROLE,
                MenuRole::QUIT => QtMenuRole::QUIT_ROLE,
                default => QtMenuRole::NO_ROLE,
            });

            if (! is_null($item->hotkey)) {
                // Qt's Ctrl is Command on macOS.
                $action->setShortcut('Ctrl+'.strtoupper($item->hotkey));
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

        return match ($item->role) {
            MenuRole::ABOUT => function (): void {
                $this->session->showAbout($this->about, $this->parent);
            },
            MenuRole::QUIT => fn () => $this->session->post(new QuitRequested($this->window)),
            default => fn () => $this->session->post(new MenuActivated($window, $item->id)),
        };
    }
}
