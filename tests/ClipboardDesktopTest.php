<?php

declare(strict_types=1);

it('puts text on the application clipboard', function (): void {
    session();
    $clipboard = QGuiApplication::clipboard();
    // The test writes the user's clipboard: it keeps the text there and puts it back.
    $kept = $clipboard->text();
    driver()->clipboard()->setText('c7 path');
    $read = $clipboard->text();
    $clipboard->setText($kept);

    expect($read)->toBe('c7 path');
});

it('refuses to open a missing path', function (string $path): void {
    expect(fn () => driver()->desktop()->open($path))->toThrow(InvalidArgumentException::class, $path);
})->with(['/nonexistent-c7', 'nonexistent-c7']);
