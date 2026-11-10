<?php

declare(strict_types=1);

use QEvent\Type;
use Surface\Contracts\Windows\Mail\View\GroupClicked;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** @return list<GroupClicked> */
function qtGroupClickMail(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof GroupClicked));
}

it('posts view.group-clicked for a primary press and release Qt delivers to a label inside a clickable group', function (): void {
    $window = driver()->open('main', 300, 200);
    $card = $window->column('m')->column('card')->setClickable();
    $title = $card->label('title', 'Stargazer');
    [$gx, $gy] = shownAt($window, $title->native(), 4.0, 5.0);
    takeMail(session());

    QCoreApplication::sendEvent($title->native(), qtMouse(Type::MOUSE_BUTTON_PRESS, 4.0, 5.0, $gx, $gy, 1, 1));
    QCoreApplication::sendEvent($title->native(), qtMouse(Type::MOUSE_BUTTON_RELEASE, 4.0, 5.0, $gx, $gy, 1, 0));

    $mail = qtGroupClickMail();
    expect($mail)->toHaveCount(1)
        ->and([$mail[0]->window, $mail[0]->path, $mail[0]->uuid])->toBe(['main', 'm.card', $card->uuid()]);
});

it('posts nothing for a group that is not clickable, a control inside one, or a release elsewhere', function (): void {
    $window = driver()->open('main', 300, 200);
    $m = $window->column('m');
    $text = $m->column('plain')->label('text', 'Plain');
    $card = $m->column('card')->setClickable();
    $title = $card->label('title', 'Stargazer');
    $go = $card->button('go', 'Go');
    $at = shownAt($window, $title->native(), 4.0, 5.0);
    $elsewhere = shownAt($window, $text->native(), 4.0, 5.0);
    $button = shownAt($window, $go->native(), 4.0, 5.0);
    takeMail(session());

    foreach ([[$elsewhere, $elsewhere], [$button, $button], [$at, $elsewhere]] as [$down, $up]) {
        $window->groupClicks()->pressAt(...$down);
        $window->groupClicks()->releaseAt(...$up);
    }

    expect(qtGroupClickMail())->toBe([]);
});

it('stops watching the application once its window closes', function (): void {
    $window = driver()->open('main', 300, 200);
    $clicks = $window->groupClicks();

    $window->close();

    expect($clicks->watching())->toBeFalse();
});
