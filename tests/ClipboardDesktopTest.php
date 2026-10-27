<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\Services\ClipboardChanged;
use Surface\Contracts\Windows\Mail\Services\ClipboardText;

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

/** The clipboard mail the session holds after a pump, in order. */
function clipboardMail(): array
{
    pumpFor(0.1);

    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof ClipboardText || $mail instanceof ClipboardChanged));
}

/** Present $window and pump until Qt makes it the active window, up to 2 s. */
function presentActive(Jovian\Toolkits\Qt\Windows\QtWindow $window): void
{
    $window->present();
    $end = microtime(true) + 2.0;
    while (! $window->native()->isActiveWindow() && microtime(true) < $end) {
        pumpFor(0.02);
    }
}

describe('the clipboard\'s text and changes', function (): void {
    // The tests write the user's clipboard: they keep the text there and put it back.
    beforeEach(function (): void {
        session();
        $this->kept = QGuiApplication::clipboard()->text();
        clipboardMail();
    });

    afterEach(function (): void {
        QGuiApplication::clipboard()->setText($this->kept);
    });

    it('answers a text request with the clipboard\'s text', function (): void {
        driver()->clipboard()->setText('c14 round');
        clipboardMail();
        $id = driver()->clipboard()->requestText();

        expect(driver()->clipboard()->hasText())->toBeTrue()
            ->and(clipboardMail())->toEqual([new ClipboardText($id, 'c14 round')]);
    });

    it('answers null and has no text after the clipboard is cleared', function (): void {
        QGuiApplication::clipboard()->clear();
        pumpFor(0.1);
        clipboardMail();
        $id = driver()->clipboard()->requestText();

        expect(driver()->clipboard()->hasText())->toBeFalse()
            ->and(clipboardMail())->toEqual([new ClipboardText($id, null)]);
    });

    it('posts ClipboardChanged once for its own text', function (): void {
        driver()->clipboard()->setText('c14 changed');

        expect(clipboardMail())->toEqual([new ClipboardChanged()]);
    });

    it('posts ClipboardChanged when a window becomes active after another app copied, even after a read', function (): void {
        driver()->closeAll();
        $first = driver()->open('first', 300, 200);
        $second = driver()->open('second', 300, 200);
        presentActive($first);
        presentActive($second);
        clipboardMail();
        shell_exec('printf c14-qt-other | pbcopy');
        // A read clears Qt's own modified flag on macOS (QMacPasteboard::sync), so Qt's dataChanged() never comes.
        driver()->clipboard()->hasText();
        presentActive($first);

        expect($first->native()->isActiveWindow())->toBeTrue()
            ->and(clipboardMail())->toEqual([new ClipboardChanged()]);

        presentActive($second);

        expect(clipboardMail())->toBe([]);
        driver()->closeAll();
    })->skip(PHP_OS_FAMILY !== 'Darwin', 'pbcopy: the Mac only');
});

/** Run a command while this process serves Qt's clipboard (xclip asks the owner); its standard output. */
function readWhilePumping(string $command): string
{
    $proc = proc_open($command, [1 => ['pipe', 'w']], $pipes);
    stream_set_blocking($pipes[1], false);
    $out = '';
    $end = microtime(true) + 2.0;
    while (proc_get_status($proc)['running'] && microtime(true) < $end) {
        pumpFor(0.01);
        $out .= stream_get_contents($pipes[1]);
    }
    $out .= stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);

    return $out;
}

it('posts ClipboardChanged when another X11 client copies', function (): void {
    // The test writes the user's clipboard: it keeps the text there and puts it back.
    $kept = readWhilePumping('xclip -selection clipboard -o 2>/dev/null');
    clipboardMail();
    shell_exec('printf c14-other | xclip -selection clipboard >/dev/null 2>&1');
    pumpFor(0.9);
    $mail = clipboardMail();
    shell_exec('printf %s ' . escapeshellarg($kept) . ' | xclip -selection clipboard >/dev/null 2>&1');

    expect($mail)->toEqual([new ClipboardChanged()]);
})->skip(PHP_OS_FAMILY !== 'Linux' || is_null(shell_exec('command -v xclip')), 'X11 with xclip only');

it('does not read a hung X11 owner\'s data on a change or a window activation', function (): void {
    // The compare that macOS needs reads the owner's data synchronously; X11 reports every change itself.
    $kept = readWhilePumping('xclip -selection clipboard -o 2>/dev/null');
    driver()->closeAll();
    $first = driver()->open('first', 300, 200);
    $second = driver()->open('second', 300, 200);
    presentActive($first);
    presentActive($second);
    shell_exec('printf c14-hung | xclip -selection clipboard >/dev/null 2>&1');
    $owner = (int) shell_exec('pgrep -n xclip');
    posix_kill($owner, SIGSTOP);
    $start = microtime(true);
    pumpFor(0.3);
    presentActive($first);
    $elapsed = microtime(true) - $start;
    posix_kill($owner, SIGCONT);
    posix_kill($owner, SIGTERM);
    driver()->closeAll();
    shell_exec('printf %s ' . escapeshellarg($kept) . ' | xclip -selection clipboard >/dev/null 2>&1');

    expect($elapsed)->toBeLessThan(2.0);
})->skip(PHP_OS_FAMILY !== 'Linux' || is_null(shell_exec('command -v xclip')) || ! function_exists('posix_kill'), 'X11 with xclip and ext-posix only');

it('refuses to open a missing path', function (string $path): void {
    expect(fn () => driver()->desktop()->open($path))->toThrow(InvalidArgumentException::class, $path);
})->with(['/nonexistent-c7', 'nonexistent-c7']);
