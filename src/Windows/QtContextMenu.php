<?php

namespace Jovian\Toolkits\Qt\Windows;

use Jovian\Toolkits\Qt\Bridge\QtSession;
use QAction;
use QMenu;
use QObject;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\ContextMenu;
use Surface\Windows\Menus\MenuItem;

/**
 * A primitive's context menu, built for one right click: a parentless QMenu filled as the bar fills
 * its menus, popped up at a global point without waiting. A chosen action posts MenuActivated.
 */
final class QtContextMenu
{
    private readonly QMenu $menu;

    /** @var array<string, QAction> item actions by id */
    private array $actions = [];

    public function __construct(
        ContextMenu $menu,
        private readonly string $window,
        private readonly QtSession $session,
    ) {
        $this->menu = new QMenu();
        $this->fill($this->menu, $menu->items);
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
        $this->menu->popup((int) round($gx), (int) round($gy));
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
                $this->fill($menu->addMenu($item->label), $item->items);
                continue;
            }

            $action = $menu->addAction($item->label);
            QObject::connect($action, 'triggered(bool)', fn () => $this->session->post(new MenuActivated($this->window, $item->id)));
            $this->actions[$item->id] = $action;
        }
    }
}
