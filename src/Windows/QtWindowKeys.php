<?php

namespace Jovian\Toolkits\Qt\Windows;

use QEvent;
use QEvent\Type;
use QEventFilter;
use QKeyEvent;
use QMainWindow;
use QObject;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\Windows\Mail\WindowKeyPressed;
use Surface\Contracts\Windows\Primitives\TKComboBox;
use Surface\Contracts\Windows\Primitives\TKTextInput;
use Surface\HumanInput\Keymaps\LinuxKeycodes;
use Surface\HumanInput\Keymaps\MacKeycodes;
use WeakReference;

/**
 * A toolkit window's keys that nothing took: a filter on the window's top-level widget. Qt sends a
 * key press on to the parent only while the focused widget leaves it unaccepted, and Tab never
 * comes (the focused widget moves focus), nor a menu accelerator (Qt's shortcut map takes it), nor
 * a default or cancel button's key (QtButtonKeys takes it first). A modifier pressed alone goes on
 * and posts nothing, as AppKit sends no key for one. Every other key press that reaches the window
 * posts WindowKeyPressed and is stopped there.
 */
final class QtWindowKeys
{
    /** Qt::Key_Shift, Key_Control, Key_Meta, Key_Alt, Key_CapsLock, Key_Super_L, Key_Super_R, Key_Hyper_L, Key_Hyper_R, Key_AltGr */
    /** Qt::Key_Shift … Key_Hyper_R and Key_AltGr: a modifier pressed alone. Shared with the canvas keys. */
    public const array MODIFIERS = [0x01000020, 0x01000021, 0x01000022, 0x01000023, 0x01000024, 0x01000053, 0x01000054, 0x01000056, 0x01000057, 0x01001103];

    /** Qt::Key_Return, Key_Enter */
    private const array SUBMIT = [0x01000004, 0x01000005];

    private ?QEventFilter $filter;

    /** @var WeakReference<QtWindow> the window holds this */
    private readonly WeakReference $window;

    private readonly WeakReference $native;

    public function __construct(QtWindow $window, QMainWindow $native)
    {
        $this->window = WeakReference::create($window);
        $this->native = WeakReference::create($native);
        $self = WeakReference::create($this);
        $this->filter = new QEventFilter(static fn (QObject $watched, QEvent $event): bool => $event->type() === Type::KEY_PRESS
            && $event instanceof QKeyEvent
            && ($self->get()?->pressed($watched, $event) ?? false));
        $native->installEventFilter($this->filter);
    }

    /** The window closed: its filter goes with it. */
    public function detach(): void
    {
        $this->filter = null;
    }

    private function pressed(QObject $watched, QKeyEvent $event): bool
    {
        $window = $this->window->get();
        if (is_null($this->filter) || ! $window instanceof QtWindow || ! $window->isOpen() || $watched !== $this->native->get()) {
            return false;
        }
        // A modifier pressed alone posts nothing, as AppKit sends no key for one.
        if (in_array($event->key(), self::MODIFIERS, true)) {
            return false;
        }
        // A line edit (a text input's, a combo's) submits on Return and leaves the key unaccepted
        // (for a dialog's default button): it posted TextSubmitted already, as GTK's and AppKit's inputs take the key.
        $focused = $window->focused();
        if (in_array($event->key(), self::SUBMIT, true) && ($focused instanceof TKTextInput || $focused instanceof TKComboBox)) {
            return false;
        }
        $window->session()->post(new WindowKeyPressed($window->name(), self::keyOf($event), self::character($event), QtCanvasPointer::modifiers($event->modifiers()), $event->isAutoRepeat()));

        return true;
    }

    /**
     * The physical key: LinuxKeycodes of the scan code - 8 on X11, MacKeycodes of the virtual key on
     * macOS. macOS key code 0 is A: Qt gives every real macOS key event the scan code 1, and an event
     * made in code both 0, which reads UNKNOWN. Shared with the canvas keys.
     */
    public static function keyOf(QKeyEvent $event): Key
    {
        return match (true) {
            PHP_OS_FAMILY !== 'Darwin' => LinuxKeycodes::key($event->nativeScanCode() - 8),
            // Both native codes 0 on an event with no timestamp: made in code. A real one is kVK_ANSI_A (0).
            $event->nativeScanCode() === 0 && $event->nativeVirtualKey() === 0 && $event->timestamp() === 0 => Key::UNKNOWN,
            default => MacKeycodes::key($event->nativeVirtualKey()),
        };
    }

    /** The event's text when it is printable, '' otherwise. Shared with the canvas keys. */
    public static function character(QKeyEvent $event): string
    {
        $text = $event->text();

        return preg_match('/^[^\x00-\x1F\x7F]+$/u', $text) === 1 ? $text : '';
    }
}
