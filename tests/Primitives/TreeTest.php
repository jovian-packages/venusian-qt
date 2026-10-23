<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Primitives\QtTree;
use QEvent\Type as EventType;
use Surface\Contracts\Windows\Mail\View\NodeActivated;
use Surface\Contracts\Windows\Mail\View\NodeExpanded;
use Surface\Contracts\Windows\Mail\View\NodeSelected;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TreeNode;
use Surface\NutsAndBolts\Color;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

// Leave Qt quiet for the files after this one: an open window keeps posting layout and paint work.
afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.1);
    takeMail(session());
});

/** @return list<TreeNode> a(a1(a1x), a2), b, with a2's name in red */
function treeNodes(): array
{
    return [
        new TreeNode('a', ['name' => 'a', 'size' => '3'], [
            new TreeNode('a1', ['name' => 'a1'], [new TreeNode('a1x', ['name' => 'a1x'])]),
            new TreeNode('a2', ['name' => 'a2', 'size' => '7'], colors: ['name' => Color::rgb(255, 0, 0)]),
        ]),
        new TreeNode('b', ['name' => 'b']),
    ];
}

/** A tree in a presented window, the presenting mail taken. */
function presentedTree(array $nodes = []): QtTree
{
    $window = driver()->open('main', 400, 300);
    $tree = $window->column('m')->tree('t', [new TableColumn('name', 'Name'), new TableColumn('size', 'Size')], $nodes === [] ? treeNodes() : $nodes);
    $window->present();
    pumpFor(0.2);
    takeMail(session());

    return $tree;
}

it('builds a QTreeWidget item per node with its cells, colour and id', function (): void {
    $tree = presentedTree();
    $native = $tree->native();
    $a2 = $tree->item('a2');
    $color = $a2->foreground(0)->color();

    expect($tree)->toBeInstanceOf(QtTree::class)
        ->and($native)->toBeInstanceOf(QTreeWidget::class)
        ->and($native->columnCount())->toBe(2)
        ->and($tree->item('a')->childCount())->toBe(2)
        ->and($tree->item('a')->child(1))->toBe($a2)
        ->and($a2->parent())->toBe($tree->item('a'))
        ->and([$a2->text(0), $a2->text(1)])->toBe(['a2', '7'])
        ->and($a2->data(0, Qt\ItemDataRole::USER_ROLE->value))->toBe('a2')
        ->and([$color->red(), $color->green(), $color->blue()])->toBe([255, 0, 0])
        ->and($tree->item('a1')->text(1))->toBe('')
        ->and($tree->item('b')->parent())->toBeNull()
        ->and($tree->item('nope'))->toBeNull();
});

it('posts nothing for expand, selectNode and setNodes, and the native agrees', function (): void {
    $tree = presentedTree();
    $tree->expand('a');
    $tree->selectNode('a1x');
    $selected = array_map(fn (QTreeWidgetItem $item): mixed => $item->data(0, Qt\ItemDataRole::USER_ROLE->value), $tree->native()->selectedItems());
    $tree->setNodes([new TreeNode('a', ['name' => 'a'], [new TreeNode('a1', ['name' => 'a1'], [new TreeNode('a1x', ['name' => 'a1x'])])])]);
    pumpFor(0.1);

    expect(viewMail(session()))->toBe([])
        ->and($selected)->toBe(['a1x'])
        ->and($tree->selectedNode())->toBe('a1x')
        ->and($tree->item('a')->isExpanded())->toBeTrue()
        ->and($tree->item('a1')->isExpanded())->toBeTrue();

    $tree->expand('a', false);
    $tree->selectNode(null);
    pumpFor(0.1);
    expect(viewMail(session()))->toBe([])
        ->and($tree->item('a')->isExpanded())->toBeFalse()
        ->and($tree->native()->selectedItems())->toBe([]);
});

it('posts NodeExpanded for the user\'s expand and collapse, and reopens what was expanded below', function (): void {
    $tree = presentedTree();
    $native = $tree->native();
    $native->expandItem($tree->item('a'));
    $tree->item('a1')->setExpanded(true);
    $native->collapseItem($tree->item('a'));
    $native->expandItem($tree->item('a'));
    $native->expandItem($tree->item('b'));
    pumpFor(0.1);

    expect(viewMail(session()))->toEqual([
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', true),
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a1', true),
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', false),
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', true),
    ])
        ->and($tree->item('a1')->isExpanded())->toBeTrue()
        ->and($tree->isExpanded('a1'))->toBeTrue();

    // Code collapses a1 while a is closed: Qt's own memory of a1 is overruled when a opens again.
    $native->collapseItem($tree->item('a'));
    $tree->expand('a1', false);
    $native->expandItem($tree->item('a'));
    pumpFor(0.1);
    expect(viewMail(session()))->toEqual([
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', false),
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', true),
    ])
        ->and($tree->item('a1')->isExpanded())->toBeFalse()
        ->and($tree->isExpanded('a1'))->toBeFalse();

    // The same from code: expand() of a reopens nothing the record has closed.
    $native->expandItem($tree->item('a1'));
    $native->collapseItem($tree->item('a'));
    pumpFor(0.1);
    viewMail(session());
    $tree->expand('a1', false);
    $tree->expand('a');
    pumpFor(0.1);
    expect($tree->item('a1')->isExpanded())->toBeFalse()
        ->and($tree->item('a')->isExpanded())->toBeTrue()
        ->and(viewMail(session()))->toBe([]);
});

it('posts NodeSelected for the user\'s selection, and for a collapse only when the selected node changed', function (): void {
    $tree = presentedTree();
    $native = $tree->native();
    $tree->expand('a');
    $native->setCurrentItem($tree->item('a2'));
    pumpFor(0.1);
    expect(viewMail(session()))->toEqual([new NodeSelected('main', 'm.t', $tree->uuid(), 'a2')]);

    $native->collapseItem($tree->item('a'));
    pumpFor(0.1);
    $after = $tree->selectedNode();
    $selections = array_values(array_filter(viewMail(session()), fn (object $mail): bool => $mail instanceof NodeSelected));
    expect($selections)->toEqual($after === 'a2' ? [] : [new NodeSelected('main', 'm.t', $tree->uuid(), $after)]);

    $native->setCurrentItem($tree->item('b'));
    $native->setCurrentItem($tree->item('b'));
    pumpFor(0.1);
    expect(viewMail(session()))->toEqual([new NodeSelected('main', 'm.t', $tree->uuid(), 'b')]);
});

it('posts one NodeActivated for Return and for Enter on the selected node, and consumes the key', function (): void {
    $tree = presentedTree();
    $native = $tree->native();
    $tree->selectNode('b');
    $return = new QKeyEvent(EventType::KEY_PRESS, Qt\Key::RETURN->value, 0);
    QCoreApplication::sendEvent($native, $return);
    QCoreApplication::sendEvent($native, new QKeyEvent(EventType::KEY_PRESS, Qt\Key::ENTER->value, 0));
    pumpFor(0.1);
    $activated = viewMail(session());

    $tree->selectNode(null);
    QCoreApplication::sendEvent($native, new QKeyEvent(EventType::KEY_PRESS, Qt\Key::RETURN->value, 0));
    pumpFor(0.1);

    expect($activated)->toEqual([
        new NodeActivated('main', 'm.t', $tree->uuid(), 'b'),
        new NodeActivated('main', 'm.t', $tree->uuid(), 'b'),
    ])
        ->and(viewMail(session()))->toBe([]);
});

it('keeps a selection setNodes moved under a collapsed node, opening its ancestors behind the record', function (): void {
    $tree = presentedTree();
    $tree->selectNode('a1');
    $tree->setNodes([new TreeNode('a', ['name' => 'a'], [new TreeNode('a2', ['name' => 'a2'])]), new TreeNode('b', ['name' => 'b'], [new TreeNode('a1', ['name' => 'a1'])])]);
    pumpFor(0.1);

    expect($tree->selectedNode())->toBe('a1')
        ->and($tree->item('b')->isExpanded())->toBeTrue()
        ->and($tree->isExpanded('b'))->toBeTrue()
        ->and(viewMail(session()))->toBe([]);

    $tree->native()->collapseItem($tree->item('b'));
    pumpFor(0.1);
    expect(viewMail(session()))->toEqual([new NodeExpanded('main', 'm.t', $tree->uuid(), 'b', false)]);
});

it('is freed once removed', function (): void {
    $tree = presentedTree();
    $tree->expand('a');
    $freed = WeakReference::create($tree);
    $tree->remove();
    unset($tree);
    pumpFor(0.1);
    gc_collect_cycles();

    expect($freed->get())->toBeNull();
});

it('drops the selection when setNodes removes the selected node, posting nothing', function (): void {
    $tree = presentedTree();
    $tree->selectNode('b');
    $tree->setNodes([new TreeNode('a', ['name' => 'a'])]);
    pumpFor(0.1);

    expect($tree->selectedNode())->toBeNull()
        ->and($tree->native()->selectedItems())->toBe([])
        ->and($tree->item('b'))->toBeNull()
        ->and(viewMail(session()))->toBe([]);
});
