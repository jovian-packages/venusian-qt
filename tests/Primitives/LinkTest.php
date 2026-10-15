<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Primitives\QtLink;
use Qt\AlignmentFlag;
use Qt\TextFormat;
use Qt\TextInteractionFlag;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\TextAlignment;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

it('shows a link as a rich-text label that opens it, its text and URL escaped', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $link = $column->link('docs', 'Read <the> "docs"', 'https://example.com/docs?a=1&b=2');
    $empty = $column->link('empty', '', 'https://example.com/');

    expect($link)->toBeInstanceOf(QtLink::class)
        ->and($link->native())->toBeInstanceOf(QLabel::class)
        ->and($link->native()->text())->toBe('<a href="https://example.com/docs?a=1&amp;b=2">Read &lt;the&gt; &quot;docs&quot;</a>')
        ->and($link->native()->textFormat())->toBe(TextFormat::RICH_TEXT)
        ->and($link->native()->openExternalLinks())->toBeTrue()
        ->and($link->native()->textInteractionFlags())->toBe(TextInteractionFlag::LINKS_ACCESSIBLE_BY_MOUSE->value | TextInteractionFlag::LINKS_ACCESSIBLE_BY_KEYBOARD->value)
        ->and($empty->native()->text())->toBe('<a href="https://example.com/"></a>');

    $link->setText('Docs')->setUrl('mailto:help@example.com')->setAlignment(TextAlignment::RIGHT)->setFont(new FontSpec(13.5));

    expect($link->native()->text())->toBe('<a href="mailto:help@example.com">Docs</a>')
        ->and($link->native()->alignment())->toBe(AlignmentFlag::RIGHT->value | AlignmentFlag::V_CENTER->value)
        ->and($link->native()->styleSheet())->toContain('font-size: 13.5pt;')
        ->and(takeMail(session()))->toBe([]);
});

it('disables a link in a disabled group', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $link = $column->link('docs', 'Docs', 'https://example.com/');
    $column->disable();

    expect($link->native()->isEnabled())->toBeFalse();
});

it('keeps a link\'s line breaks and runs of spaces, which rich text would collapse', function (): void {
    $link = driver()->open('main', 300, 200)->column('m')->link('lines', "Line 1\r\nLine 2  end", 'https://example.com/');

    expect($link->native()->text())->toBe('<a href="https://example.com/">Line 1<br>Line 2 &nbsp;end</a>');
});
