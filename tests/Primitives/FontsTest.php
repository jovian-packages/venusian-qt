<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Primitives\Concerns\QtFonts;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\TextAlignment;
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

it('measures the monospace font and a family the system lacks', function (): void {
    $mono = driver()->fontMetrics(new FontSpec(13.0, monospace: true));
    $missing = driver()->fontMetrics(new FontSpec(13.0, family: 'Zzqq Sans'));

    expect($mono->fixedPitch)->toBeTrue()
        ->and($mono->ascent)->toBeGreaterThan(0.0)
        ->and($mono->descent)->toBeGreaterThan(0.0)
        ->and($mono->averageCharWidth)->toBeGreaterThan(0.0)
        ->and($mono->lineHeight)->toBeGreaterThanOrEqual($mono->ascent + $mono->descent)
        ->and($missing->ascent)->toBeGreaterThan(0.0);
});

it('asks for an italic face in the style sheet', function (): void {
    $label = driver()->open('main', 300, 200)->column('m')->label('a', 'x')->setFont(new FontSpec(13.0, italic: true));

    expect($label->native()->styleSheet())->toContain('font-style: italic;');
});

it('lays bold and italic runs out with the line, draws underline and strike-out over it, sets no colour for a flag-only run, and clears old runs', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('t', "zero\nbold italic strike");
    $area->setRangeStyles([
        new TextRangeStyle(1, 0, 4, bold: true, underline: true),
        new TextRangeStyle(1, 5, 6, italic: true),
        new TextRangeStyle(1, 12, 6, strikeout: true),
    ]);
    $layout = fn () => $area->native()->document()->findBlockByNumber(1)->layout()->formats();
    [$bold, $italic] = $layout();
    [$under, $plain, $strike] = $area->native()->extraSelections();

    expect([$bold->start, $bold->length, $italic->start, $italic->length])->toBe([0, 4, 5, 6])
        ->and($bold->format->intProperty(QTextFormat::FONT_WEIGHT))->toBe(700)
        ->and($italic->format->boolProperty(QTextFormat::FONT_ITALIC))->toBeTrue()
        ->and($italic->format->intProperty(QTextFormat::FONT_WEIGHT))->toBe(0)
        ->and($under->format->intProperty(QTextFormat::TEXT_UNDERLINE_STYLE))->toBe(1)
        ->and($strike->format->boolProperty(QTextFormat::FONT_STRIKE_OUT))->toBeTrue()
        ->and($plain->format->foreground()->style())->toBe(Qt\BrushStyle::NO_BRUSH);

    $area->setRangeStyles([new TextRangeStyle(0, 0, 1, foreground: Color::hex('#ff0000'))]);
    expect($area->native()->extraSelections())->toHaveCount(1)
        ->and($layout())->toBe([]);
});

it('spaces tab stops by columns × the space advance and follows a font change', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('t', "a\tb");
    $space = fn (float $size): float => QtFonts::spaceAdvance(QtFonts::qFont(new FontSpec($size, monospace: true)));

    $area->setFont(new FontSpec(13.0, monospace: true))->setTabWidth(4);
    expect($area->native()->tabStopDistance())->toEqualWithDelta(4 * $space(13.0), 0.01);

    $area->setFont(new FontSpec(20.0, monospace: true));
    expect($area->native()->tabStopDistance())->toEqualWithDelta(4 * $space(20.0), 0.01);
});

it('aligns a text area, an input and a button', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $area = $column->textArea('t', 'x')->setAlignment(TextAlignment::RIGHT);
    $input = $column->textInput('i', '12')->setAlignment(TextAlignment::RIGHT);
    $button = $column->button('b', 'Go')->setAlignment(TextAlignment::RIGHT);

    expect($area->native()->document()->defaultTextOption()->alignment() & Qt\AlignmentFlag::RIGHT->value)->toBe(Qt\AlignmentFlag::RIGHT->value)
        ->and($input->native()->alignment() & Qt\AlignmentFlag::RIGHT->value)->toBe(Qt\AlignmentFlag::RIGHT->value)
        ->and($button->native()->styleSheet())->toContain('text-align: right;');
});
