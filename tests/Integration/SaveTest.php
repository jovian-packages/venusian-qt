<?php

declare(strict_types=1);

use Qt\WindowModality;
use Jovian\Toolkits\Qt\QtWindow;
use Surface\Contracts\Toolkits\Dialogs\SaveRequest;
use Surface\Contracts\Toolkits\Mail\Windows\SaveAnswered;

/*
| The save panel is a QFileDialog in save mode, window-modal, opened with open(). Under the
| offscreen platform Qt shows its own widget panel, which a test can end with accept() and
| reject(); the system's panel on macOS is out of reach of a test.
*/

afterEach(fn () => closeAll());

function shownPanel(QtWindow $window): QFileDialog
{
    $panel = (fn (): ?QFileDialog => $this->save)->call($window) ?? throw new RuntimeException("No panel on '{$window->name()}'.");
    pumpFor(0.2);

    return $panel;
}

/** @return list<SaveAnswered> */
function saveAnswers(array $mail): array
{
    return array_values(array_filter($mail, fn (object $m): bool => $m instanceof SaveAnswered));
}

it('opens a window-modal QFileDialog with the title, folder and name, and returns at once', function (): void {
    $window = openWindow();
    takeMail();
    $directory = realpath(sys_get_temp_dir());

    $id = $window->saveFile(new SaveRequest('report.txt', $directory, 'Save the report'));
    $panel = shownPanel($window);

    expect($id)->toMatch('/^[0-9a-f]{16}$/')
        ->and($window->isSaving())->toBeTrue()
        ->and($panel->windowTitle())->toBe('Save the report')
        ->and($panel->windowModality())->toBe(WindowModality::WINDOW_MODAL)
        ->and(array_map('basename', $panel->selectedFiles()))->toBe(['report.txt'])
        ->and(dirname($panel->selectedFiles()[0]))->toBe($directory)
        ->and(saveAnswers(takeMail()))->toBe([]);
});

it('posts the chosen path under the panel\'s id when it is accepted', function (): void {
    $window = openWindow();
    takeMail();
    $directory = realpath(sys_get_temp_dir());
    $name = 'venusian-qt-save-'.bin2hex(random_bytes(4)).'.txt';
    $id = $window->saveFile(new SaveRequest($name, $directory));
    $panel = shownPanel($window);

    $panel->accept();
    $answer = saveAnswers(mailUntil('SaveAnswered'));

    expect($answer)->toHaveCount(1)
        ->and($answer[0]->id)->toBe($id)
        ->and($answer[0]->window)->toBe('main')
        ->and($answer[0]->path)->toBe($directory.'/'.$name)
        ->and($window->isSaving())->toBeFalse();
});

it('posts null when the panel is cancelled', function (): void {
    $window = openWindow();
    takeMail();
    $window->saveFile(new SaveRequest('a.txt'));
    $panel = shownPanel($window);

    $panel->reject();
    $answer = saveAnswers(mailUntil('SaveAnswered'));

    expect($answer)->toHaveCount(1)
        ->and($answer[0]->path)->toBeNull()
        ->and($window->isSaving())->toBeFalse();
});

it('takes the panel down and answers null when the window closes first', function (): void {
    $window = openWindow();
    takeMail();
    $id = $window->saveFile(new SaveRequest('a.txt'));
    $panel = shownPanel($window);

    $window->close();
    $mail = takeMail();
    pumpFor(0.2);

    expect(saveAnswers($mail))->toHaveCount(1)
        ->and(saveAnswers($mail)[0]->id)->toBe($id)
        ->and(saveAnswers($mail)[0]->path)->toBeNull()
        ->and(nativeShown($panel))->toBeFalse()
        ->and(saveAnswers(takeMail()))->toBe([]);
});
