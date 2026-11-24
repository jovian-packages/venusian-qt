<?php

namespace Jovian\Toolkits\Qt\UI;

use QBrush;
use QColor;
use QEvent;
use QObject;
use QKeyEvent;
use QTreeWidget;
use QEventFilter;
use WeakReference;
use QTreeWidgetItem;
use QAbstractItemView;
use Qt\Key;
use Qt\ItemDataRole;
use QEvent\Type as EventType;
use Surface\Toolkits\UI\TKTree;
use QAbstractItemView\SelectionMode;
use QAbstractItemView\SelectionBehavior;
use Surface\Contracts\Toolkits\UI\TreeNode;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Surface\Contracts\Toolkits\UI\TableColumn;

/**
 * A QTreeWidget, each item holding its node's id. Return activates and goes no further.
 */
class QtTree extends TKTree
{
    use QtNative;

    /**
     * @var array<string, QTreeWidgetItem> the item for each node id
     */
    protected array $items = [];

    /** Takes Return and Enter before the view does. */
    private ?QEventFilter $key_filter = null;

    protected function bootstrapNative(): void
    {
        $tree = new QTreeWidget();
        $tree->setColumnCount(count($this->columns));
        $tree->setHeaderLabels(array_map(static fn (TableColumn $column): string => $column->label, $this->columns));
        $tree->setSelectionBehavior(SelectionBehavior::SELECT_ROWS);
        $tree->setSelectionMode(SelectionMode::SINGLE_SELECTION);
        $tree->setEditTriggers(QAbstractItemView::NO_EDIT_TRIGGERS);
        $this->adoptNative($tree);
        if ($this->untitled()) {
            $tree->setStyleSheet($this->subControlSheet());
        }

        $self = WeakReference::create($this);
        QObject::connect($tree, 'itemSelectionChanged()', static function () use ($self): void {
            $self->get()?->selectionChanged();
        });
        QObject::connect($tree, 'itemExpanded(QTreeWidgetItem*)', static function (QTreeWidgetItem $item) use ($self): void {
            $self->get()?->expansionChanged($item, true);
        });
        QObject::connect($tree, 'itemCollapsed(QTreeWidgetItem*)', static function (QTreeWidgetItem $item) use ($self): void {
            $self->get()?->expansionChanged($item, false);
        });
        QObject::connect($tree, 'itemActivated(QTreeWidgetItem*,int)', static function (QTreeWidgetItem $item) use ($self): void {
            $self->get()?->activateId(self::idOf($item));
        });
        $this->key_filter = new QEventFilter(static fn (QObject $watched, QEvent $event): bool => $self->get()?->keyPressed($event) ?? false);
        $tree->installEventFilter($this->key_filter);

        $this->write(fn () => $this->reload());
    }

    /** ext-qt binds no setHeaderHidden(): a header with no titles gets no height. */
    protected function subControlSheet(): string
    {
        return $this->untitled() ? "#{$this->styleId()} QHeaderView { max-height: 0px; }" : '';
    }

    private function untitled(): bool
    {
        return count($this->columns) === 1 && $this->columns[0]->label === '';
    }

    protected function applyNodes(array $nodes): void
    {
        $this->write(function () use ($nodes): void {
            $tree = $this->treeNative();
            $tree->clear();
            $this->items = [];
            foreach ($nodes as $node) {
                $tree->addTopLevelItem($this->build($node));
            }
        });
    }

    protected function applyExpanded(string $id, bool $expanded): void
    {
        $this->write(function () use ($id, $expanded): void {
            if ($expanded) {
                $this->treeNative()->expandItem($this->items[$id]);
                $this->closeForgotten($id);
            } else {
                $this->treeNative()->collapseItem($this->items[$id]);
            }
        });
    }

    protected function applySelected(?string $id): void
    {
        $this->write(function () use ($id): void {
            if (is_null($id)) {
                $this->treeNative()->clearSelection();

                return;
            }

            $this->treeNative()->setCurrentItem($this->items[$id]);
            $this->treeNative()->scrollToItem($this->items[$id]);
        });
    }

    private function selectedInNative(): ?string
    {
        $selected = $this->treeNative()->selectedItems();

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
                $this->treeNative()->collapseItem($this->items[$child]);
            }
        }
    }

    protected function selectionChanged(): void
    {
        if (! $this->isRemoved() && ! $this->isWriting()) {
            $this->choseNode($this->selectedInNative());
        }
    }

    protected function expansionChanged(QTreeWidgetItem $item, bool $expanded): void
    {
        $id = self::idOf($item);
        if (is_null($id) || $this->isRemoved() || $this->isWriting()) {
            return;
        }

        $this->openedNode($id, $expanded);
        if ($expanded) {
            $this->write(fn () => $this->closeForgotten($id));
        }
    }

    protected function activateId(?string $id): void
    {
        if (! is_null($id)) {
            $this->activatedNode($id);
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

        $this->activateId($this->selectedInNative());

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

    private function treeNative(): QTreeWidget
    {
        /** @var QTreeWidget */
        return $this->native();
    }
}
