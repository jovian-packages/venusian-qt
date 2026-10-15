<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Windows\QtMenuBar;
use Surface\Contracts\Windows\Menus\MenuRole;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

it('gives edit items no shortcut and no Qt menu role: the text widgets keep their own keys', function (): void {
    $bar = driver()->open('main', 320, 200, driver()->profile('editing'))->menuBar();

    expect($bar->action('edit.copy')->shortcut())->toBe('')
        ->and($bar->action('edit.select-all')->shortcut())->toBe('')
        ->and($bar->action('edit.copy')->menuRole())->toBe(QAction\MenuRole::NO_ROLE)
        ->and($bar->action('app.quit')->shortcut())->toBe('Ctrl+Q');
});

it('runs an edit role on a focused line edit or plain text edit, and on nothing else', function (): void {
    $line = new QLineEdit('hello world');
    $text = new QPlainTextEdit();

    $selected = QtMenuBar::edit($line, MenuRole::SELECT_ALL);

    expect($selected)->toBeTrue()
        ->and($line->selectedText())->toBe('hello world')
        ->and(QtMenuBar::edit($text, MenuRole::UNDO))->toBeTrue()
        ->and(QtMenuBar::edit(new QWidget(), MenuRole::COPY))->toBeFalse()
        ->and(QtMenuBar::edit(null, MenuRole::COPY))->toBeFalse();
});

it('triggers on the application\'s focus widget, posting nothing', function (): void {
    $window = driver()->open('main', 360, 160, driver()->profile('editing'));
    $input = $window->column('c')->textInput('name', 'hello world');
    $window->present();
    pumpFor(0.2);
    $input->native()->setFocus();
    pumpFor(0.1);
    if (QApplication::focusWidget() !== $input->native()) {
        test()->markTestSkipped('The test app is not the active app here (macOS keeps focus with the frontmost app).');
    }

    takeMail(session());                // the window's own focus and size mail
    $window->menuBar()->action('edit.select-all')->trigger();

    expect($input->native()->selectedText())->toBe('hello world')
        ->and(takeMail(session()))->toBe([]);
});
