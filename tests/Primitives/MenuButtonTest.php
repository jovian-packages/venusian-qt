<?php

declare(strict_types=1);

use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Framebuffers\Native\NativeFullFramebuffer;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

it('is a tool button popping the menu at once, text only', function (): void {
    $window = driver()->open('main', 300, 200);
    $button = $window->column('m')->menuButton('case', 'Ignore case', [['label' => 'None'], ['label' => 'Unimportant'], ['label' => 'All']]);

    expect($button->native())->toBeInstanceOf(QToolButton::class)
        ->and($button->native()->popupMode())->toBe(QToolButton\ToolButtonPopupMode::INSTANT_POPUP)
        ->and($button->native()->toolButtonStyle())->toBe(Qt\ToolButtonStyle::TOOL_BUTTON_TEXT_ONLY)
        ->and($button->native()->menu())->toBe($button->menuItems()->menu())
        ->and($button->menuItems()->action('unimportant')->text())->toBe('Unimportant')
        ->and($button->native()->text())->toBe('Ignore case')
        ->and($button->native()->autoRaise())->toBeFalse();

    $button->setLabel('Case');
    expect($button->native()->text())->toBe('Case');
    $window->close();
});

it('posts MenuActivated for a triggered item', function (): void {
    $window = driver()->open('main', 300, 200);
    $button = $window->column('m')->menuButton('case', 'Ignore case', [['label' => 'None'], ['label' => 'All']]);

    $button->menuItems()->action('all')->trigger();
    pumpFor(0.05);

    expect(takeMail(session()))->toEqual([new MenuActivated('main', 'all')]);
    $window->close();
});

it('rebuilds its menu, and shows an image beside the text and no raise', function (): void {
    $window = driver()->open('main', 300, 200);
    $button = $window->column('m')->menuButton('case', 'Ignore case', [['label' => 'None']]);

    $button->setMenu([['label' => 'A'], ['label' => 'B']]);
    $button->setImage((new NativeFullFramebuffer(FormatSpec::rgba8(), 32, 32))->fill(0xffffffff), 2.0);
    $button->setBordered(false);

    expect($button->native()->menu())->toBe($button->menuItems()->menu())
        ->and($button->menuItems()->action('b')->text())->toBe('B')
        ->and($button->native()->toolButtonStyle())->toBe(Qt\ToolButtonStyle::TOOL_BUTTON_TEXT_BESIDE_ICON)
        ->and(wh($button->native()->iconSize()))->toBe([16, 16])
        ->and($button->native()->autoRaise())->toBeTrue();

    $button->menuItems()->action('b')->trigger();
    pumpFor(0.05);
    expect(takeMail(session()))->toEqual([new MenuActivated('main', 'b')]);

    $button->setImage(null);
    expect($button->native()->toolButtonStyle())->toBe(Qt\ToolButtonStyle::TOOL_BUTTON_TEXT_ONLY);
    $window->close();
});

it('keeps a radio choice and a toggle made in its menu', function (): void {
    $window = driver()->open('main', 300, 200);
    $button = $window->column('m')->menuButton('case', 'Case', [
        ['id' => 'case.none', 'label' => 'None', 'radio' => 'case', 'on' => true],
        ['id' => 'case.all', 'label' => 'All', 'radio' => 'case'],
        ['id' => 'wrap', 'label' => 'Wrap', 'toggle' => true],
    ]);

    $button->menuItems()->action('case.all')->trigger();
    $button->menuItems()->action('wrap')->trigger();
    pumpFor(0.05);

    expect(takeMail(session()))->toEqual([new Surface\Contracts\Windows\Mail\MenuRadioChosen('main', 'case', 'case.all'), new Surface\Contracts\Windows\Mail\MenuToggled('main', 'wrap', true)])
        ->and($button->menu()->radios())->toBe(['case' => 'case.all'])
        ->and($button->menu()->items()['wrap']->isOn())->toBeTrue()
        ->and($button->menuItems()->action('case.none')->isChecked())->toBeFalse();
    $window->close();
});
