<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\Mail\Views\SelectionChanged;

/** A 32 x 32 red file. */
function qtDropdownIconFile(): string
{
    $path = sys_get_temp_dir().'/qt-dropdown-icon.png';
    $image = imagecreatetruecolor(32, 32);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 0, 0));
    imagepng($image, $path);

    return $path;
}

function qtKindDropdown(): array
{
    $window = openWindow('dropdown', 400, 300);
    $dropdown = $window->column('root')->dropdown('kind', ['All', 'Images', 'Videos', 'Audio'], 0);
    pumpFor(0.05);
    takeMail();

    return [$window, $dropdown];
}

/** The holder's two faces: the combo box and the tool button. */
function faces(object $dropdown): array
{
    return (fn (): array => [$this->combo, $this->button, $this->actions])->call($dropdown);
}

it('gives each option an icon on its combo box item and its menu action, and none where there is none', function (): void {
    [$window, $dropdown] = qtKindDropdown();
    [$combo] = faces($dropdown);
    $plain = $combo->sizeHint()->width();

    $dropdown->setIcons([null, ['file' => qtDropdownIconFile()]]);
    pumpFor(0.05);
    [$combo, , $actions] = faces($dropdown);

    expect($combo->sizeHint()->width())->toBeGreaterThan($plain)
        ->and(array_map(fn (QAction $a): string => $a->text(), $actions))->toBe(['All', 'Images', 'Videos', 'Audio'])
        ->and($combo->itemText(1))->toBe('Images');
    $window->close();
});

it('shows a frameless tool button with the selected option\'s icon once it is icon-only, and the combo box again after', function (): void {
    [$window, $dropdown] = qtKindDropdown();
    $dropdown->setIcons([['file' => qtDropdownIconFile()], ['file' => qtDropdownIconFile()]]);

    $dropdown->setIconOnly();
    pumpFor(0.05);
    [$combo, $button] = faces($dropdown);
    $iconOnly = [$combo->isVisible(), $button->isVisible(), $button->autoRaise(), $button->popupMode(), $button->toolButtonStyle()];
    $width = $dropdown->native()->sizeHint()->width();

    $dropdown->setIconOnly(false);
    pumpFor(0.05);

    expect($iconOnly)->toBe([false, true, true, QToolButton\ToolButtonPopupMode::INSTANT_POPUP, Qt\ToolButtonStyle::TOOL_BUTTON_ICON_ONLY])
        ->and($width)->toBeLessThan($dropdown->native()->sizeHint()->width())
        ->and([$combo->isVisible(), $button->isVisible()])->toBe([true, false]);
    $window->close();
});

it('posts the option a menu action picks, and the combo box follows', function (): void {
    [$window, $dropdown] = qtKindDropdown();
    $dropdown->setIconOnly();
    [$combo, , $actions] = faces($dropdown);

    $actions[2]->trigger();
    $mail = array_values(array_filter(mailUntil('SelectionChanged'), fn (object $m): bool => $m instanceof SelectionChanged));

    expect($mail)->toHaveCount(1)
        ->and($mail[0]->index)->toBe(2)
        ->and($mail[0]->option)->toBe('Videos')
        ->and($dropdown->selected())->toBe(2)
        ->and($combo->currentIndex())->toBe(2);
    $window->close();
});

it('gives rebuilt options their actions and icons again', function (): void {
    [$window, $dropdown] = qtKindDropdown();
    $dropdown->setIcons([['file' => qtDropdownIconFile()]]);
    $dropdown->setOptions(['All', 'Images']);
    [$combo, , $actions] = faces($dropdown);

    expect(array_map(fn (QAction $a): string => $a->text(), $actions))->toBe(['All', 'Images'])
        ->and($combo->count())->toBe(2);
    $window->close();
});
