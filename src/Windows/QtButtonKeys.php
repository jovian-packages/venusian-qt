<?php

namespace Jovian\Toolkits\Qt\Windows;

use Jovian\Toolkits\Qt\Bridge\QtBridgeDriver;
use Jovian\Toolkits\Qt\Primitives\QtTextArea;
use QApplication;
use QCoreApplication;
use QEvent;
use QEvent\Type;
use QEventFilter;
use QKeyEvent;
use QObject;
use QWidget;
use Qt\Key;
use Surface\Contracts\Windows\Primitives\ButtonRole;
use Surface\Windows\Primitives\TKButton;
use WeakReference;

/**
 * The windows' default and cancel buttons on the keyboard: one application filter that sees each
 * key press before the widget does. Return or keypad Enter clicks the default holder, Esc the
 * cancel holder, with no modifier but the keypad one; the filter then eats the press. Return goes
 * on to a focused text area, and any key goes on when its holder cannot take it (hidden or
 * disabled, its parents included: a tab not selected hides its page). A popup (a QMenu, a combo
 * box list) is its own window, not one of the driver's, so its keys go on. Esc in a child with no
 * cancel button is the user's close. A window QShortcut cannot do this: QtMenuKeys eats every
 * ShortcutOverride, so a Return shortcut would win over a focused text area (probe 2026-10-20).
 * Qt can send one press to the focused widget and then to each parent; the choice reads the
 * window and the focus widget, not the receiver, so it is the same each time.
 */
final class QtButtonKeys
{
    /** Qt::ShiftModifier | ControlModifier | AltModifier | MetaModifier. */
    private const int MODIFIERS = 0x02000000 | 0x04000000 | 0x08000000 | 0x10000000;

    private ?QEventFilter $filter = null;

    /** @var WeakReference<QtBridgeDriver> the driver holds this */
    private readonly WeakReference $driver;

    public function __construct(QtBridgeDriver $driver)
    {
        $this->driver = WeakReference::create($driver);
        $self = WeakReference::create($this);
        $this->filter = new QEventFilter(static fn (QObject $watched, QEvent $event): bool => $event->type() === Type::KEY_PRESS
            && $event instanceof QKeyEvent
            && $watched instanceof QWidget
            && ($self->get()?->press($watched, $event) ?? false));
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

    /** A key press for $watched: whether it clicked or closed, and so is eaten. */
    public function press(QWidget $watched, QKeyEvent $event): bool
    {
        if (($event->modifiers() & self::MODIFIERS) !== 0) {
            return false;
        }
        $role = match ($event->key()) {
            Key::RETURN->value, Key::ENTER->value => ButtonRole::DEFAULT,
            Key::ESCAPE->value => ButtonRole::CANCEL,
            default => null,
        };
        $window = is_null($role) ? null : $this->windowOf($watched);
        if (is_null($window)) {
            return false;
        }
        if ($role === ButtonRole::DEFAULT && $this->textAreaFocused($window)) {
            return false;
        }

        $holder = TKButton::holder($window, $role);
        if (! is_null($holder)) {
            $button = $holder->native();
            if (! $button->isVisible() || ! $button->isEnabled()) {
                return false;
            }
            $button->click();

            return true;
        }
        if ($role === ButtonRole::CANCEL && ! is_null($window->parent())) {
            $window->userClose();

            return true;
        }

        return false;
    }

    /** The open window of the driver whose native holds $watched. */
    private function windowOf(QWidget $watched): ?QtWindow
    {
        $top = $watched->window();
        foreach ($this->driver->get()?->all() ?? [] as $window) {
            if ($window->isOpen() && $window->native() === $top) {
                return $window;
            }
        }

        return null;
    }

    private function textAreaFocused(QtWindow $window): bool
    {
        $focus = QApplication::focusWidget();
        foreach ($window->registry()->all() as $primitive) {
            if ($primitive instanceof QtTextArea && $primitive->native() === $focus) {
                return true;
            }
        }

        return false;
    }
}
