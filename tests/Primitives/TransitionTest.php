<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Primitives\TKTransition;

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

it('draws a widget through an opacity effect only while it is not opaque', function (): void {
    $window = driver()->open('main', 300, 200);
    $label = $window->column('m')->label('a', 'A');
    $window->present();

    $label->setOpacity(0.4);

    expect($label->native()->graphicsEffect())->toBeInstanceOf(QGraphicsOpacityEffect::class)
        ->and($label->native()->graphicsEffect()->opacity())->toEqualWithDelta(0.4, 0.0001);

    $label->setOpacity(1.0);

    expect($label->native()->graphicsEffect())->toBeNull();
});

it('shows the widget at once when it appears, and lands opaque where layout put it', function (): void {
    $window = driver()->open('main', 300, 200);
    $card = $window->column('m', spacing: 8, padding: 10)->column('card');
    $card->label('text', 'Card');
    $window->present();
    pumpFor(0.2);
    $rest = $card->native()->pos();

    $card->appear(TKTransition::SLIDE_UP, 0.2)->hide();
    pumpFor(0.05);
    $card->show();

    expect($card->native()->isVisible())->toBeTrue();

    pumpFor(0.5);

    expect($card->native()->graphicsEffect())->toBeNull()
        ->and($card->native()->pos()->x())->toBe($rest->x())
        ->and($card->native()->pos()->y())->toBe($rest->y());
});

it('keeps the widget up while it disappears, then hides it opaque again', function (): void {
    $window = driver()->open('main', 300, 200);
    $label = $window->column('m')->label('a', 'A');
    $window->present();
    pumpFor(0.2);

    $label->disappear(TKTransition::FADE, 0.2)->hide();

    expect($label->isVisible())->toBeFalse()
        ->and($label->native()->isVisible())->toBeTrue();

    pumpFor(0.5);

    expect($label->native()->isVisible())->toBeFalse()
        ->and($label->native()->graphicsEffect())->toBeNull();
});

it('stays shown when shown again before its disappearance lands', function (): void {
    $window = driver()->open('main', 300, 200);
    $label = $window->column('m')->label('a', 'A');
    $window->present();
    pumpFor(0.2);

    $label->disappear(TKTransition::FADE, 0.3)->appear(TKTransition::FADE, 0.1);
    $label->hide();
    pumpFor(0.05);
    $label->show();
    pumpFor(0.6);

    expect($label->isVisible())->toBeTrue()
        ->and($label->native()->isVisible())->toBeTrue()
        ->and($label->native()->graphicsEffect())->toBeNull();
});
