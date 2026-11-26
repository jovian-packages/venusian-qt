<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\QtSession;
use Jovian\Toolkits\Qt\Qt;
use Surface\Contracts\Toolkits\Styling\ColorScheme;

it('connects one session for the driver', function (): void {
    $session = session();

    expect($session)->toBeInstanceOf(QtSession::class)
        ->and($session->connected())->toBeTrue()
        ->and($session->application())->toBeInstanceOf(Qt::class)
        ->and(driver()->connect())->toBe($session);
});

it('names the QApplication from config', function (): void {
    session();

    expect(QCoreApplication::applicationName())->toBe('Qt Integration Tests')
        ->and(QGuiApplication::desktopFileName())->toBe(APPLICATION_ID);
});

it('reconnects to the same toolkit library', function (): void {
    $toolkit = session()->application();

    session()->disconnect();
    expect(session()->connected())->toBeTrue()
        ->and(session()->application())->toBe($toolkit);
});

it('draws in the colour scheme it is given: forced for dark and light, the system\'s again for none', function (): void {
    $apply = new ReflectionMethod(QtSession::class, 'applyColorScheme');
    $hints = QGuiApplication::styleHints();
    $system = $hints->colorScheme();

    $apply->invoke(session(), ColorScheme::DARK);
    expect($hints->colorScheme())->toBe(\Qt\ColorScheme::DARK);

    $apply->invoke(session(), ColorScheme::LIGHT);
    expect($hints->colorScheme())->toBe(\Qt\ColorScheme::LIGHT);

    $apply->invoke(session(), ColorScheme::SYSTEM);
    expect($hints->colorScheme())->toBe($system);
});
