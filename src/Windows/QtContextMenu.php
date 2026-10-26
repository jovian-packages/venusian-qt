<?php

namespace Jovian\Toolkits\Qt\Windows;

use Jovian\Toolkits\Qt\Bridge\QtSession;
use QAction;
use QActionGroup;
use QMenu;
use QObject;
use QPoint;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuRadioChosen;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\ContextMenu;
use Surface\Windows\Menus\MenuItem;
use Surface\Windows\Menus\MenuStates;
use WeakReference;

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

    /** @var array<string, QActionGroup> each radio group's exclusive group */
    private array $groups = [];

    /** @var array<string, string> group → the item on */
    private array $radios;

    /** @var WeakReference<object>|null the primitive or menu button whose menu this is: it keeps the user's choices */
    private readonly ?WeakReference $owner;

    /**
     * @param object|null $owner a TKPrimitive or TKMenuButton: told the user's toggle and radio choices
     *                           (nativeContextToggled(), nativeContextRadio()) before the mail; null keeps nothing (popupMenu)
     */
    public function __construct(
        ContextMenu $menu,
        private readonly string $window,
        private readonly QtSession $session,
        ?object $owner = null,
    ) {
        $this->owner = is_null($owner) ? null : WeakReference::create($owner);
        $this->radios = $menu->radios();
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
                $sub = $menu->addMenu(QtMenuBar::literal($item->label));
                $this->actions[$item->id] = $sub->menuAction();
                $this->fill($sub, $item->items);
                continue;
            }

            $action = $menu->addAction(QtMenuBar::literal($item->label));
            $group = $item->radio();
            if ($item->toggle) {
                $action->setCheckable(true);
                $action->setChecked($item->isOn());
                QObject::connect($action, 'triggered(bool)', function (bool $checked) use ($item): void {
                    $this->owner?->get()?->nativeContextToggled($item->id, $checked);
                    $this->session->post(new MenuToggled($this->window, $item->id, $checked));
                });
            } elseif (! is_null($group)) {
                $this->groups[$group] ??= new QActionGroup($this->menu);
                $action->setCheckable(true);
                $action->setChecked($this->radios[$group] === $item->id);
                $this->groups[$group]->addAction($action);
                QObject::connect($action, 'triggered(bool)', function () use ($item, $group): void {
                    if ($this->radios[$group] === $item->id) {
                        return;
                    }
                    $this->radios[$group] = $item->id;
                    $this->owner?->get()?->nativeContextRadio($group, $item->id);
                    $this->session->post(new MenuRadioChosen($this->window, $group, $item->id));
                });
            } else {
                QObject::connect($action, 'triggered(bool)', fn () => $this->session->post(new MenuActivated($this->window, $item->id)));
            }
            $this->actions[$item->id] = $action;
        }
    }
}
