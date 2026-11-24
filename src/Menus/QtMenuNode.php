<?php

namespace Jovian\Toolkits\Qt\Menus;

use QMenu;
use QAction;
use Surface\Toolkits\Menus\AboutPanel;
use Surface\Contracts\Toolkits\Menus\BarNode;
use Surface\Contracts\Toolkits\Menus\BarNodeKind;
use Surface\Contracts\Toolkits\Menus\StandardKind;

/**
 * What a QtMenuBar made for one node.
 *
 * @internal
 */
final class QtMenuNode
{
    public readonly BarNodeKind $kind;

    public readonly ?StandardKind $standard;

    /** A standard item the application answers: Qt does none of its work. */
    public readonly bool $replaced;

    /** The menu macOS shows first, under the application's name. */
    public readonly bool $application;

    /** The fields of an About that shows the toolkit's own panel. */
    public readonly ?AboutPanel $panel;

    /** Shown, and never enabled: a kind Qt has no call for where it was put. */
    public bool $inert = false;

    public ?QMenu $submenu = null;

    /**
     * One action, a menu's menuAction(), a choice's one per option, or none: a kind Qt puts in
     * the macOS application menu by itself.
     *
     * @var list<QAction>
     */
    public array $natives = [];

    /** @var list<string> a choice's values, as its natives are ordered */
    public array $options = [];

    /** @var list<string> a menu's nodes, in order */
    public array $children = [];

    /** A toggle's mark as topside last gave it. */
    public ?bool $on;

    /** A choice's option as topside last gave it. */
    public ?string $chosen;

    public function __construct(BarNode $node, public ?string $parent)
    {
        $this->kind = $node->kind;
        $this->standard = $node->standard;
        $this->replaced = $node->replaced;
        $this->application = $node->application;
        $this->panel = $node->panel;
        $this->on = $node->on;
        $this->chosen = $node->chosen;
    }
}
