<?php

namespace Jovian\Toolkits\Qt\Windows;

use Jovian\Toolkits\Qt\Bridge\QtSession;
use QAction;
use QMenu;
use QObject;
use QPoint;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\ContextMenu;
use Surface\Windows\Menus\MenuItem;
use Surface\Windows\Menus\MenuStates;

/**
 * A primitive's context menu, built for one right click: a parentless QMenu filled as the bar fills
 * its menus, popped up at a global point without waiting. A chosen action posts MenuActivated.
 * A node that is not effectively enabled is a disabled action (a folder: its menu's action).
 */
final class QtContextMenu
{
    private readonly QMenu $menu;

    /** @var array<string, QAction> node actions by id (a folder's: its menu's menuAction()) */
    private array $actions = [];

    public function __construct(
        ContextMenu $menu,
        private readonly string $window,
        private readonly QtSession $session,
    ) {
        $this->menu = new QMenu();
        $this->fill($this->menu, $menu->items);
        $states = new MenuStates($menu);
        foreach (array_keys($menu->nodes()) as $id) {
            $this->action($id)->setEnabled($states->effective($id));
        }
    }

    public function menu(): QMenu
    {
        return $this->menu;
    }

    /** @throws WindowException */
    public function action(string $id): QAction
    {
        return $this->actions[$id] ?? throw new WindowException("No item '{$id}' in this context menu.");
    }

    public function popupAt(float $gx, float $gy): void
    {
        $this->menu->popup(new QPoint((int) round($gx), (int) round($gy)));
    }

    /** @param list<MenuItem> $items */
    private function fill(QMenu $menu, array $items): void
    {
        foreach ($items as $item) {
            if ($item->separator) {
                $menu->addSeparator();
                continue;
            }

            if ($item->isFolder()) {
                $sub = $menu->addMenu($item->label);
                $this->actions[$item->id] = $sub->menuAction();
                $this->fill($sub, $item->items);
                continue;
            }

            $action = $menu->addAction($item->label);
            QObject::connect($action, 'triggered(bool)', fn () => $this->session->post(new MenuActivated($this->window, $item->id)));
            $this->actions[$item->id] = $action;
        }
    }
}
