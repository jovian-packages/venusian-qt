<?php

namespace Jovian\Toolkits\Qt\Menus;

use QKeySequence;
use QKeySequence\SequenceFormat;
use Surface\Toolkits\Menus\Shortcut;
use Surface\Contracts\Toolkits\Menus\ShortcutKey;

/**
 * A Shortcut as Qt has it: a key sequence, written in Qt's portable text.
 *
 * @internal
 */
final class QtShortcut
{
    /** Qt's name of each key that is not its own character. */
    private const array NAMES = [
        'Up' => 'Up', 'Down' => 'Down', 'Left' => 'Left', 'Right' => 'Right',
        'Home' => 'Home', 'End' => 'End', 'PageUp' => 'PgUp', 'PageDown' => 'PgDown',
        'Space' => 'Space', 'Enter' => 'Return', 'Tab' => 'Tab', 'Escape' => 'Esc',
        'Backspace' => 'Backspace', 'Delete' => 'Del',
    ];

    public static function sequence(?Shortcut $shortcut, bool $mac): QKeySequence
    {
        return is_null($shortcut)
            ? new QKeySequence('')
            : QKeySequence::fromString(self::portable($shortcut, $mac), SequenceFormat::PORTABLE_TEXT);
    }

    /**
     * In Qt's own modifier order. On macOS Qt's Ctrl is Command and its Meta is Control; elsewhere
     * Primary is Control, so the two are one key.
     */
    public static function portable(Shortcut $shortcut, bool $mac): string
    {
        $ctrl = $shortcut->primary || (! $mac && $shortcut->ctrl);
        $meta = $mac && $shortcut->ctrl;

        return ($meta ? 'Meta+' : '').($ctrl ? 'Ctrl+' : '').($shortcut->alt ? 'Alt+' : '').($shortcut->shift ? 'Shift+' : '')
            .self::key($shortcut->key);
    }

    private static function key(ShortcutKey $key): string
    {
        return self::NAMES[$key->value] ?? $key->value;    // a letter, a digit, a mark, F1 to F12
    }
}
