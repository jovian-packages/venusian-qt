<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QAbstractItemView;
use QAbstractItemView\SelectionBehavior;
use QAbstractItemView\SelectionMode;
use QBrush;
use QColor;
use QEvent;
use QEvent\Type as EventType;
use QEventFilter;
use QKeyEvent;
use QObject;
use QSizePolicy\Policy;
use QTreeWidget;
use QTreeWidgetItem;
use Qt\ItemDataRole;
use Qt\Key;
use Surface\Contracts\Windows\Mail\View\NodeActivated;
use Surface\Contracts\Windows\Mail\View\NodeExpanded;
use Surface\Contracts\Windows\Mail\View\NodeSelected;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TreeNode;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTree;
use WeakReference;

/**
 * A read-only QTreeWidget, one row at a time: one QTreeWidgetItem per node, its id in the first
 * column's user role, a coloured cell's foreground in the node's colour. The selected node is
 * read from the selected items, so a deselect (Ctrl-click) reports null where currentItem()
 * would not.
 *
 * Qt keeps a hidden node's expansion and shows it again when an ancestor opens, without a
 * signal; opening a node, by code or by the user, closes any child Qt shows expanded that
 * Surface's record has closed. QAbstractItemView activates on Return and Enter everywhere but
 * macOS, so an event filter takes both keys on every platform and posts the activation itself.
 */
class QtTree extends TKTree implements QtNative
{
    use QtPrimitive;

    /**
     * @var array<string, QTreeWidgetItem> the item for each node id
     */
    protected array $items = [];

    /** Takes Return and Enter before the view does. */
    protected QEventFilter $key_filter;

    /** The node the native had selected after the last write or the last change the user made. */
    private ?string $known_selected = null;

    /**
     * @param list<TableColumn> $columns
     * @param list<TreeNode> $nodes
     */
    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $columns, array $nodes)
    {
        parent::__construct($name, $window, $parent, $placement, $columns, $nodes);
        $tree = $this->adoptNative(new QTreeWidget());
        $tree->setColumnCount(count($this->columns));
        $tree->setHeaderLabels(array_map(fn (TableColumn $column): string => $column->label, $this->columns));
        $tree->setSelectionBehavior(SelectionBehavior::SELECT_ROWS);
        $tree->setSelectionMode(SelectionMode::SINGLE_SELECTION);
        $tree->setEditTriggers(QAbstractItemView::NO_EDIT_TRIGGERS);

        QObject::connect($tree, 'itemSelectionChanged()', fn () => $this->selectionChanged());
        QObject::connect($tree, 'itemExpanded(QTreeWidgetItem*)', fn (QTreeWidgetItem $item) => $this->expansionChanged($item, true));
        QObject::connect($tree, 'itemCollapsed(QTreeWidgetItem*)', fn (QTreeWidgetItem $item) => $this->expansionChanged($item, false));
        QObject::connect($tree, 'itemActivated(QTreeWidgetItem*,int)', fn (QTreeWidgetItem $item, int $column) => $this->activate(self::idOf($item)));

        // The filter holds its callable where PHP's collector cannot see it: a weak reference keeps it from holding the tree.
        $self = WeakReference::create($this);
        $this->key_filter = new QEventFilter(static fn (QObject $watched, QEvent $event): bool => $self->get()?->keyPressed($event) ?? false);
        $tree->installEventFilter($this->key_filter);

        $this->reload();
    }

    public function native(): QTreeWidget
    {
        return $this->native;
    }

    /**
     * @param string $id
     * @return QTreeWidgetItem|null the node's item; null for an unknown id
     */
    public function item(string $id): ?QTreeWidgetItem
    {
        return $this->items[$id] ?? null;
    }

    protected function naturalPolicy(): array
    {
        return [Policy::EXPANDING, Policy::EXPANDING];
    }

    protected function applyNodes(array $nodes): void
    {
        $this->applying(function () use ($nodes): void {
            $tree = $this->native();
            $tree->clear();
            $this->items = [];
            foreach ($nodes as $node) {
                $tree->addTopLevelItem($this->build($node));
            }
        });
        $this->known_selected = null;
    }

    protected function applyExpanded(string $id, bool $expanded): void
    {
        $this->applying(function () use ($id, $expanded): void {
            if ($expanded) {
                $this->native()->expandItem($this->items[$id]);
                $this->closeForgotten($id);
            } else {
                $this->native()->collapseItem($this->items[$id]);
            }
        });
    }

    protected function applySelected(?string $id): void
    {
        $this->applying(function () use ($id): void {
            if (is_null($id)) {
                $this->native()->clearSelection();

                return;
            }

            $this->native()->setCurrentItem($this->items[$id]);
            $this->native()->scrollToItem($this->items[$id]);
        });
        $this->known_selected = $this->nativeSelected();
    }

    protected function nativeSelected(): ?string
    {
        $selected = $this->native()->selectedItems();

        return $selected === [] ? null : self::idOf($selected[0]);
    }

    /**
     * The node's item and its children's, its id in the first column's user role.
     *
     * @param TreeNode $node
     * @return QTreeWidgetItem
     */
    protected function build(TreeNode $node): QTreeWidgetItem
    {
        $item = new QTreeWidgetItem();
        foreach ($this->cellTexts($node) as $column => $text) {
            $item->setText($column, $text);
        }
        foreach ($this->cellColors($node) as $column => $color) {
            if (! is_null($color)) {
                $item->setForeground($column, new QBrush(new QColor(
                    (int) round($color->red * 255), (int) round($color->green * 255), (int) round($color->blue * 255), (int) round($color->alpha * 255),
                )));
            }
        }
        $item->setData(0, ItemDataRole::USER_ROLE->value, $node->id);
        $this->items[$node->id] = $item;
        foreach ($node->children as $child) {
            $item->addChild($this->build($child));
        }

        return $item;
    }

    /**
     * Close each child Qt shows expanded, from its own memory, that the record has closed.
     *
     * @param string $id a node the native just opened
     * @return void
     */
    protected function closeForgotten(string $id): void
    {
        foreach ($this->childIds($id) as $child) {
            if (! isset($this->expanded[$child]) && $this->items[$child]->isExpanded()) {
                $this->native()->collapseItem($this->items[$child]);
            }
        }
    }

    protected function selectionChanged(): void
    {
        if (! $this->handling()) {
            return;
        }

        $selected = $this->nativeSelected();
        if ($selected === $this->known_selected) {
            return;
        }

        $this->known_selected = $selected;
        $this->session()->post(new NodeSelected($this->window->name(), $this->path(), $this->uuid, $selected));
    }

    /**
     * The user opened or closed a node: record it, post it, and on opening bring the children
     * below it to the record.
     *
     * @param QTreeWidgetItem $item
     * @param bool $expanded
     * @return void
     */
    protected function expansionChanged(QTreeWidgetItem $item, bool $expanded): void
    {
        $id = self::idOf($item);
        if (! $this->handling() || is_null($id) || ! $this->nativeNodeExpanded($id, $expanded)) {
            return;
        }

        $this->session()->post(new NodeExpanded($this->window->name(), $this->path(), $this->uuid, $id, $expanded));
        if ($expanded) {
            $this->applying(function () use ($id): void {
                $this->closeForgotten($id);
                $this->reopen($id);
            });
        }
    }

    protected function activate(?string $id): void
    {
        if ($this->handling() && ! is_null($id)) {
            $this->session()->post(new NodeActivated($this->window->name(), $this->path(), $this->uuid, $id));
        }
    }

    /**
     * Return and Enter go no further than the tree: they activate the selected node, if any.
     *
     * @param QEvent $event
     * @return bool whether the event was consumed
     */
    protected function keyPressed(QEvent $event): bool
    {
        if (! $event instanceof QKeyEvent || $event->type() !== EventType::KEY_PRESS || ! in_array($event->key(), [Key::RETURN->value, Key::ENTER->value], true)) {
            return false;
        }

        $this->activate($this->nativeSelected());

        return true;
    }

    /**
     * @param QTreeWidgetItem $item
     * @return string|null the id the item holds in its first column's user role
     */
    protected static function idOf(QTreeWidgetItem $item): ?string
    {
        $id = $item->data(0, ItemDataRole::USER_ROLE->value);

        return is_string($id) ? $id : null;
    }
}
