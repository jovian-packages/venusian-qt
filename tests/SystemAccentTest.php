<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Services\QtSystemAccent;
use Surface\NutsAndBolts\Color;

it('is the driver\'s system accent', function (): void {
    expect(driver()->systemAccent())->toBeInstanceOf(QtSystemAccent::class)
        ->and(driver()->systemAccent())->toBe(driver()->systemAccent());
});

it('gives the accent role of the application palette as an opaque colour', function (): void {
    driver()->connect();
    $role = QGuiApplication::palette()->color(QPalette\ColorRole::ACCENT);
    $accent = driver()->systemAccent()->color();

    expect($accent)->toBeInstanceOf(Color::class)
        ->and($accent->alpha)->toBe(1.0)
        ->and($accent->toHex())->toBe(sprintf('#%02x%02x%02x', $role->red(), $role->green(), $role->blue()));
})->skip(version_compare(qVersion(), '6.6.0', '<'), 'QPalette::Accent is Qt 6.6 and later');

it('reports none before Qt 6.6, which has no accent role', function (): void {
    expect(driver()->systemAccent()->color())->toBeNull();
})->skip(version_compare(qVersion(), '6.6.0', '>='), 'this Qt has the accent role');
