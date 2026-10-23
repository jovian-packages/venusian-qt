<?php

namespace Jovian\Toolkits\Qt\Windows;

use QCoreApplication;
use QEvent;
use QEvent\Type;
use QEventFilter;
use QObject;

/**
 * Gives a key to a menu shortcut before the focused widget. Before Qt tries a shortcut it sends a
 * ShortcutOverride key event to the focused widget, and a widget that accepts it (QLineEdit and
 * QPlainTextEdit do, for their editing keys) keeps the key from the shortcut. This application
 * filter eats every ShortcutOverride: no widget accepts one, Qt tries the shortcuts, and a key no
 * shortcut takes reaches the widget as a normal key press. The driver makes one when it connects.
 */
final class QtMenuKeys
{
    private ?QEventFilter $filter = null;

    public function __construct()
    {
        $this->attach();
    }

    /** Watch the application, if not watching already. */
    public function attach(): void
    {
        if (! is_null($this->filter)) {
            return;
        }
        $this->filter = new QEventFilter(static fn (QObject $watched, QEvent $event): bool => $event->type() === Type::SHORTCUT_OVERRIDE);
        QCoreApplication::instance()?->installEventFilter($this->filter);
    }

    /** Stop watching the application. */
    public function detach(): void
    {
        if (! is_null($this->filter)) {
            QCoreApplication::instance()?->removeEventFilter($this->filter);
            $this->filter = null;
        }
    }

    public function watching(): bool
    {
        return ! is_null($this->filter);
    }
}
