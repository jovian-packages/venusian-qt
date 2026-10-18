<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\View\ViewScrolled;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\TextRangeStyle;
use Surface\NutsAndBolts\Color;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.1);
    takeMail(session());
});

it('paints line backgrounds and range colours as extra selections, and clears them with a new value', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('diff', "one\ntwo\nthrée😀x");
    $area->setLineBackgrounds([1 => Color::hex('#ff0000')])
        ->setRangeStyles([new TextRangeStyle(2, 4, 2, foreground: Color::hex('#0000ff'), background: Color::hex('#ffff00'))]);
    [$line, $range] = $area->native()->extraSelections();

    expect($area->native()->extraSelections())->toHaveCount(2)
        ->and($line->format->boolProperty(QTextFormat::FULL_WIDTH_SELECTION))->toBeTrue()
        ->and([$line->cursor->selectionStart(), $line->cursor->selectionEnd()])->toBe([4, 8])
        ->and($line->format->background()->color()->red())->toBe(255)
        ->and([$range->cursor->selectionStart(), $range->cursor->selectionEnd()])->toBe([12, 15])
        ->and($range->format->foreground()->color()->blue())->toBe(255)
        ->and($range->format->background()->color()->green())->toBe(255);

    $area->setValue("x\ny");
    expect($area->native()->extraSelections())->toBe([])
        ->and($area->native()->toPlainText())->toBe("x\ny")
        ->and(viewMail(session()))->toBe([]);
});

it('restyles one kind after the user edits, keeping the other kind where Qt moved it', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('diff', "one\ntwo\nthrée😀x\nlast");
    $edit = $area->native();
    $area->setRangeStyles([new TextRangeStyle(2, 4, 2, foreground: Color::hex('#0000ff'))])
        ->setLineBackgrounds([3 => Color::hex('#ff0000')]);
    $span = fn (QTextEdit\ExtraSelection $selection): array => [$selection->cursor->selectionStart(), $selection->cursor->selectionEnd()];

    // The user's edit: lines 2 and 3 are gone, line 1 is shorter. Both styles name lines the text no longer has.
    $edit->setPlainText("on\ntw");
    expect($area->value())->toBe("on\ntw");
    [, $range] = $edit->extraSelections();
    $held = $span($range);

    $area->setLineBackgrounds([0 => Color::hex('#00ff00')]);
    [$line, $range] = $edit->extraSelections();
    expect($edit->extraSelections())->toHaveCount(2)
        ->and($span($line))->toBe([0, 3])
        ->and($line->format->background()->color()->green())->toBe(255)
        ->and($span($range))->toBe($held)
        ->and($range->format->foreground()->color()->blue())->toBe(255);

    $area->setRangeStyles([new TextRangeStyle(1, 1, 1, background: Color::hex('#ffff00'))]);
    [$line, $range] = $edit->extraSelections();
    expect($edit->extraSelections())->toHaveCount(2)
        ->and($span($line))->toBe([0, 3])
        ->and($line->format->background()->color()->green())->toBe(255)
        ->and($span($range))->toBe([4, 5])
        ->and($range->format->background()->color()->red())->toBe(255);

    $area->setLineBackgrounds([]);
    expect($edit->extraSelections())->toHaveCount(1)
        ->and($span($edit->extraSelections()[0]))->toBe([4, 5]);
});

/** Where each laid-out row of the text from $from to its end starts, first to last. */
function rowStarts(QPlainTextEdit $edit, int $from): array
{
    $cursor = new QTextCursor($edit->document());
    $cursor->movePosition(QTextCursor\MoveOperation::END);
    $starts = [];
    while (true) {
        $cursor->movePosition(QTextCursor\MoveOperation::START_OF_LINE);
        array_unshift($starts, $cursor->position());
        if ($cursor->position() <= $from) {
            return $starts;
        }
        $cursor->setPosition($cursor->position() - 1);
    }
}

it('covers every row of a wrapped line with its background, the text\'s last line too, as the rows change', function (): void {
    $window = driver()->open('main', 300, 400);
    $first = str_repeat('word ', 12);
    $last = str_repeat('word ', 22);
    $area = $window->column('m')->textArea('diff', "{$first}\nmid\n{$last}")->fill();
    $red = Color::hex('#ff000080');
    $area->setLineBackgrounds([0 => $red, 2 => $red]);
    $window->present();
    expect(settled($area))->toBeTrue();
    $edit = $area->native();
    $tail = mb_strlen($first) + 5;
    $span = fn (QTextEdit\ExtraSelection $selection): array => [$selection->cursor->selectionStart(), $selection->cursor->selectionEnd()];
    $end = $edit->document()->characterCount() - 1;
    $rows = rowStarts($edit, $tail);
    $selections = $edit->extraSelections();

    expect(count(rowStarts($edit, 0)) - count($rows))->toBeGreaterThanOrEqual(3)   // the first line wraps: two rows or more, plus "mid"
        ->and(count($rows))->toBeGreaterThanOrEqual(3)
        ->and($selections)->toHaveCount(3)
        ->and($span($selections[0]))->toBe([0, mb_strlen($first) + 1])
        ->and($selections[0]->format->boolProperty(QTextFormat::FULL_WIDTH_SELECTION))->toBeTrue()
        ->and($span($selections[1]))->toBe([$tail, $rows[count($rows) - 1]])
        ->and($selections[1]->format->boolProperty(QTextFormat::FULL_WIDTH_SELECTION))->toBeTrue()
        ->and([$selections[2]->cursor->anchor(), $selections[2]->cursor->position()])->toBe([$end, $end])
        ->and($selections[2]->format->boolProperty(QTextFormat::FULL_WIDTH_SELECTION))->toBeTrue();

    $was = $span($selections[1]);
    $window->native()->resize(220, 400);
    expect(settled($area))->toBeTrue();
    $rows = rowStarts($edit, $tail);
    $selections = $edit->extraSelections();

    expect($span($selections[1]))->toBe([$tail, $rows[count($rows) - 1]])
        ->and($span($selections[1]))->not->toBe($was)
        ->and([$selections[2]->cursor->anchor(), $selections[2]->cursor->position()])->toBe([$end, $end]);
});

it('selects lines over UTF-16 positions and reads the selection back as lines', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('diff', "é😀\ntwo\nthree");
    $edit = $area->native();

    $area->selectLines(1, 2);
    expect([$edit->textCursor()->selectionStart(), $edit->textCursor()->selectionEnd()])->toBe([4, 13])
        ->and($area->selectedLines())->toBe([1, 2])
        ->and($area->caretLine())->toBe(1);

    $cursor = new QTextCursor($edit->document());
    $cursor->setPosition(4);
    $cursor->setPosition(8, QTextCursor\MoveMode::KEEP_ANCHOR);   // "two\n"
    $edit->setTextCursor($cursor);
    expect($area->selectedLines())->toBe([1, 1]);
    $cursor->setPosition(2);
    $edit->setTextCursor($cursor);
    expect($area->selectedLines())->toBe([0, 0])
        ->and(viewMail(session()))->toBe([]);
});

it('selects lines off screen without scrolling the pane', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', implode("\n", range(1, 400)))->fill()->setWrap(false)->watchScroll();
    $window->present();
    expect(settled($area))->toBeTrue();
    takeMail(session());
    $edit = $area->native();
    $before = [$edit->horizontalScrollBar()->value(), $edit->verticalScrollBar()->value()];

    $area->selectLines(300, 310);

    expect(settled($area))->toBeTrue()
        ->and([$edit->horizontalScrollBar()->value(), $edit->verticalScrollBar()->value()])->toBe($before)
        ->and($area->selectedLines())->toBe([300, 310])
        ->and(viewMail(session()))->toBe([]);
});

it('stops wrapping, turns read-only on with keyboard selection, and asks for the fixed system font', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('diff', 'text');
    $area->setWrap(false)->setReadOnly(true)->setFont(new FontSpec(12.0, monospace: true));
    $fixed = QFontDatabase::systemFont(QFontDatabase\SystemFont::FIXED_FONT)->family();

    expect($area->native()->lineWrapMode())->toBe(QPlainTextEdit\LineWrapMode::NO_WRAP)
        ->and($area->native()->isReadOnly())->toBeTrue()
        ->and($area->native()->textInteractionFlags())->toBe(Qt\TextInteractionFlag::TEXT_SELECTABLE_BY_MOUSE->value | Qt\TextInteractionFlag::TEXT_SELECTABLE_BY_KEYBOARD->value)
        ->and($area->native()->styleSheet())->toContain('font-family: "'.addcslashes($fixed, '"\\').'";');

    $area->setWrap(true)->setReadOnly(false);
    expect($area->native()->lineWrapMode())->toBe(QPlainTextEdit\LineWrapMode::WIDGET_WIDTH)
        ->and($area->native()->isReadOnly())->toBeFalse()
        ->and($area->native()->textInteractionFlags())->toBe(Qt\TextInteractionFlag::TEXT_EDITOR_INTERACTION->value);
});

it('scrolls by content fractions and to a line, posting ViewScrolled only for scrolls it did not make', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', implode("\n", range(1, 400)))->fill()->setWrap(false)->watchScroll();
    $window->present();
    $bar = $area->native()->verticalScrollBar();
    expect(settled($area))->toBeTrue();
    takeMail(session());

    $area->scrollTo(0.0, 0.5);
    expect(settled($area))->toBeTrue();
    $at = $area->scrollPosition();
    expect(viewMail(session()))->toBe([])
        ->and($bar->maximum())->toBeGreaterThan(0)
        ->and($at->y)->toEqualWithDelta(0.5, 0.01)
        ->and($at->height)->toBeLessThan(0.5);

    // Told the position it already has: the bars stay put, nothing posts.
    $values = [$area->native()->horizontalScrollBar()->value(), $bar->value()];
    $area->scrollTo($at->x, $at->y);
    expect(settled($area))->toBeTrue()
        ->and([$area->native()->horizontalScrollBar()->value(), $bar->value()])->toBe($values)
        ->and($area->scrollPosition())->toEqual($at)
        ->and(viewMail(session()))->toBe([]);

    $bar->setValue(10);   // as the scroll bar or the wheel does
    expect(settled($area))->toBeTrue();
    $mail = viewMail(session());
    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(ViewScrolled::class)
        ->and($mail[0]->position)->toEqual($area->scrollPosition());

    $area->scrollToLine(200);
    expect(settled($area))->toBeTrue()
        ->and($bar->value())->toBe(200)
        ->and(viewMail(session()))->toBe([]);

    $area->watchScroll(false);
    $bar->setValue(0);
    expect(settled($area))->toBeTrue()
        ->and(viewMail(session()))->toBe([]);
});

it('keeps the visible rect inside the content when scrolled to the far end', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', implode("\n", array_fill(0, 200, str_repeat('wide ', 60))))->fill()->setWrap(false);
    $window->present();
    expect(settled($area))->toBeTrue();

    $area->scrollTo(1.0, 1.0);
    expect(settled($area))->toBeTrue();
    $horizontal = $area->native()->horizontalScrollBar();
    $vertical = $area->native()->verticalScrollBar();
    $at = $area->scrollPosition();

    expect($horizontal->value())->toBe($horizontal->maximum())
        ->and($vertical->value())->toBe($vertical->maximum())
        ->and($at->x + $at->width)->toEqualWithDelta(1.0, 0.0001)
        ->and($at->y + $at->height)->toEqualWithDelta(1.0, 0.0001);
});

it('bounds the unwrapped width by the text under full-width line backgrounds, and reaches its right edge', function (): void {
    $window = driver()->open('main', 600, 300);
    $text = implode("\n", array_fill(0, 40, str_repeat('wide ', 60)));
    $column = $window->column('m');
    $plain = $column->textArea('plain', $text)->fill()->setWrap(false);
    $lined = $column->textArea('lined', $text)->fill()->setWrap(false)
        ->setLineBackgrounds(array_fill(0, 40, Color::hex('#ffeeee')));
    $window->present();
    expect(settled($plain))->toBeTrue()
        ->and(settled($lined))->toBeTrue();
    $horizontal = $lined->native()->horizontalScrollBar();

    expect($horizontal->maximum())->toBeGreaterThan(0)
        ->and($horizontal->maximum())->toBe($plain->native()->horizontalScrollBar()->maximum());

    $lined->scrollTo(1.0, 0.0);
    expect(settled($lined))->toBeTrue()
        ->and($horizontal->value())->toBe($horizontal->maximum())
        ->and($lined->scrollPosition()->x + $lined->scrollPosition()->width)->toEqualWithDelta(1.0, 0.0001);
});

it('posts no ViewScrolled for the scroll a font change makes', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', implode("\n", array_fill(0, 200, str_repeat('wide ', 60))))
        ->fill()->setWrap(false)->setFont(new FontSpec(18.0))->watchScroll();
    $window->present();
    expect(settled($area))->toBeTrue();
    $area->scrollTo(1.0, 1.0);
    expect(settled($area))->toBeTrue();
    takeMail(session());
    $horizontal = $area->native()->horizontalScrollBar();
    $vertical = $area->native()->verticalScrollBar();
    $before = [$horizontal->value(), $vertical->value()];

    $area->setFont(new FontSpec(9.0, monospace: true));

    expect(settled($area))->toBeTrue()
        ->and([$horizontal->value(), $vertical->value()])->not->toBe($before)
        ->and(viewMail(session()))->toBe([]);
});

it('posts no ViewScrolled for a new value or a wrap change that clamps the scroll', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', implode("\n", array_fill(0, 400, str_repeat('wide ', 60))))
        ->fill()->setWrap(false)->watchScroll();
    $window->present();
    expect(settled($area))->toBeTrue();
    $area->scrollTo(1.0, 1.0);
    expect(settled($area))->toBeTrue();
    takeMail(session());
    $horizontal = $area->native()->horizontalScrollBar();
    $vertical = $area->native()->verticalScrollBar();
    $before = $vertical->value();

    $area->setValue(implode("\n", array_fill(0, 100, 'short')));
    expect(settled($area))->toBeTrue()
        ->and($vertical->value())->toBeLessThan($before)
        ->and(viewMail(session()))->toBe([]);

    $area->setValue(implode("\n", array_fill(0, 100, str_repeat('wide ', 60))));
    $area->scrollTo(1.0, 1.0);
    expect(settled($area))->toBeTrue()
        ->and($horizontal->value())->toBeGreaterThan(0);
    takeMail(session());

    $area->setWrap(true);
    expect(settled($area))->toBeTrue()
        ->and($horizontal->value())->toBe(0)
        ->and(viewMail(session()))->toBe([]);
});
