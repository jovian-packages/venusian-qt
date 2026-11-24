<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\QtSession;
use Jovian\Toolkits\Qt\Qt;

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
