<?php

declare(strict_types=1);

use QEvent\Type;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\Contracts\Windows\Primitives\ImagePosition;
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

dataset('image buttons', [
    'push' => ['button'],
    'toggle' => ['toggle'],
]);

/** The labels the button holds as its own children. */
function buttonLabels(QWidget $button): array
{
    return array_values(array_filter($button->children(), fn (QObject $child): bool => $child instanceof QLabel));
}

it('shows a framebuffer image at its size in points beside the label, or alone', function (string $kind): void {
    $window = driver()->open('main', 300, 200);
    $column = $window->column('m');
    $button = $kind === 'button' ? $column->button('b', 'Open') : $column->toggleButton('b', 'Open');
    $image = (new NativeFullFramebuffer(FormatSpec::rgba8(), 32, 32))->fill(0xff4020ff);

    $button->setImage($image, ImagePosition::LEADING, 2.0);
    expect(wh($button->native()->iconSize()))->toBe([16, 16])
        ->and($button->native()->text())->toBe('Open');

    $button->setImage($image, ImagePosition::ONLY);
    expect(wh($button->native()->iconSize()))->toBe([32, 32])
        ->and($button->native()->text())->toBe('')
        ->and($button->native()->accessibleName())->toBe('Open');

    $button->setImage(null);
    expect($button->native()->text())->toBe('Open');
    $window->close();
})->with('image buttons');

it('lays an image-above face inside the rect the style draws a push button\'s contents into', function (string $kind): void {
    $window = driver()->open('main', 300, 200);
    $column = $window->column('m');
    $button = $kind === 'button' ? $column->button('b', 'Previous') : $column->toggleButton('b', 'Previous');
    $image = (new NativeFullFramebuffer(FormatSpec::rgba8(), 32, 32))->fill(0xff4020ff);
    canvasShownAt($window, $button->native(), 0.0, 0.0);

    $button->setImage($image, ImagePosition::ABOVE, 2.0);
    pumpFor(0.1);
    $native = $button->native();
    $option = new QStyleOptionButton();
    $option->initFrom($native);
    $contents = $native->style()->subElementRect(QStyle\SubElement::PUSH_BUTTON_CONTENTS, $option, $native);

    foreach (buttonLabels($native) as $label) {
        $at = $label->geometry();
        expect($at->x())->toBeGreaterThanOrEqual($contents->x())
            ->and($at->y())->toBeGreaterThanOrEqual($contents->y())
            ->and($at->x() + $at->width())->toBeLessThanOrEqual($contents->x() + $contents->width())
            ->and($at->y() + $at->height())->toBeLessThanOrEqual($contents->y() + $contents->height());
    }
    $window->close();
})->with('image buttons');

it('keeps its size while it measures an image-above face', function (string $kind): void {
    $window = driver()->open('main', 300, 200);
    $column = $window->column('m');
    $button = $kind === 'button' ? $column->button('b', 'Previous') : $column->toggleButton('b', 'Previous');
    $image = (new NativeFullFramebuffer(FormatSpec::rgba8(), 32, 32))->fill(0xff4020ff);
    canvasShownAt($window, $button->native(), 0.0, 0.0);
    $before = wh($button->native()->size());

    $button->setImage($image, ImagePosition::ABOVE, 2.0);

    expect($before[0])->toBeGreaterThan($button->native()->minimumWidth())
        ->and(wh($button->native()->size()))->toBe([$before[0], max($before[1], $button->native()->minimumHeight())]);
    $window->close();
})->with('image buttons');

it('puts the image above the label in labels a click goes through, and takes them out after', function (string $kind): void {
    $window = driver()->open('main', 300, 200);
    $column = $window->column('m');
    $button = $kind === 'button' ? $column->button('b', 'Open') : $column->toggleButton('b', 'Open');
    $image = (new NativeFullFramebuffer(FormatSpec::rgba8(), 32, 32))->fill(0xff4020ff);
    canvasShownAt($window, $button->native(), 0.0, 0.0);

    $button->setImage($image, ImagePosition::ABOVE, 2.0);
    pumpFor(0.1);
    $labels = buttonLabels($button->native());
    expect($labels)->toHaveCount(2)
        ->and($labels[1]->text())->toBe('Open')
        ->and($labels[0]->pixmap()->isNull())->toBeFalse()
        ->and($labels[0]->testAttribute(Qt\WidgetAttribute::TRANSPARENT_FOR_MOUSE_EVENTS))->toBeTrue()
        ->and($labels[1]->testAttribute(Qt\WidgetAttribute::TRANSPARENT_FOR_MOUSE_EVENTS))->toBeTrue()
        ->and($button->native()->text())->toBe('')
        ->and($button->native()->accessibleName())->toBe('Open')
        ->and($button->native()->height())->toBeGreaterThanOrEqual(16 + $labels[1]->sizeHint()->height());

    $centre = $button->native()->mapToGlobal(new QPointF($button->native()->width() / 2, $button->native()->height() / 2));
    $at = QApplication::widgetAt((int) round($centre->x()), (int) round($centre->y()));
    expect($at)->toBe($button->native());
    $local = new QPointF($button->native()->width() / 2, $button->native()->height() / 2);
    QCoreApplication::sendEvent($at, new QMouseEvent(Type::MOUSE_BUTTON_PRESS, $local, $centre, 1, 1, 0, QPointingDevice::primaryPointingDevice()));
    QCoreApplication::sendEvent($at, new QMouseEvent(Type::MOUSE_BUTTON_RELEASE, $local, $centre, 1, 0, 0, QPointingDevice::primaryPointingDevice()));
    $mail = $kind === 'button' ? new ButtonClicked('main', 'm.b', $button->uuid()) : new Toggled('main', 'm.b', $button->uuid(), true);
    expect(viewMail(session()))->toEqual([$mail]);

    $button->setLabel('Close');
    expect(buttonLabels($button->native())[1]->text())->toBe('Close')
        ->and($button->native()->accessibleName())->toBe('Close');

    $button->setImage($image);
    pumpFor(0.1);
    expect(buttonLabels($button->native()))->toBe([])
        ->and($button->native()->text())->toBe('Close');
    $window->close();
})->with('image buttons');

it('draws flat when not bordered', function (string $kind): void {
    $window = driver()->open('main', 300, 200);
    $column = $window->column('m');
    $button = $kind === 'button' ? $column->button('b', 'Open') : $column->toggleButton('b', 'Open');

    $button->setBordered(false);
    expect($button->native()->isFlat())->toBeTrue();

    $button->setBordered(true);
    expect($button->native()->isFlat())->toBeFalse();
    $window->close();
})->with('image buttons');
