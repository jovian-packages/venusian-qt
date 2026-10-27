<?php

declare(strict_types=1);

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Windows\QtCanvasPointer;
use Jovian\Toolkits\Qt\Windows\QtWindow;
use Jovian\Toolkits\Qt\Windows\QtWindowKeys;
use QEvent;
use QEvent\Type;
use QEventFilter;
use QInputMethodEvent;
use QKeyEvent;
use QKeySequence\StandardKey;
use QObject;
use Qt\Key as QtKey;
use QWidget;
use Surface\Contracts\Windows\Input\EditCommand;
use Surface\Contracts\Windows\Mail\View\EditCommanded;
use Surface\Contracts\Windows\Mail\View\KeyPressed;
use Surface\Contracts\Windows\Mail\View\KeyReleased;
use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\TextCommitted;
use WeakReference;

/**
 * The keys of a focused canvas: an event filter on the canvas's host widget. A KeyPress posts
 * KeyPressed, then EditCommanded for the first standard key sequence the event matches (Qt's own
 * platform bindings), or InsertTab / InsertBacktab for Tab / Backtab, or TextCommitted for printable
 * text; a KeyRelease posts KeyReleased; an InputMethod event with a commit string posts
 * TextCommitted (text the platform composed: dead keys, candidate windows). Qt's shortcut map and the
 * driver's application-level role filter run before a widget's filter, so accelerators and role keys
 * never arrive. Tab and Backtab are declined when the canvas does not take Tab, so Qt moves focus;
 * an unfocused canvas declines every key.
 */
final class QtCanvasKeys
{
    /** The first match wins: selects before moves, words and lines before characters. */
    private const array COMMANDS = [
        'SELECT_ALL' => EditCommand::SelectAll, 'CUT' => EditCommand::Cut, 'COPY' => EditCommand::Copy, 'PASTE' => EditCommand::Paste,
        'UNDO' => EditCommand::Undo, 'REDO' => EditCommand::Redo,
        'DELETE_START_OF_WORD' => EditCommand::DeleteWordBackward, 'DELETE_END_OF_WORD' => EditCommand::DeleteWordForward,
        'DELETE_END_OF_LINE' => EditCommand::DeleteToLineEnd, 'BACKSPACE' => EditCommand::DeleteBackward, 'DELETE' => EditCommand::DeleteForward,
        'SELECT_NEXT_WORD' => EditCommand::SelectWordRight, 'SELECT_PREVIOUS_WORD' => EditCommand::SelectWordLeft,
        'SELECT_START_OF_LINE' => EditCommand::SelectToLineStart, 'SELECT_END_OF_LINE' => EditCommand::SelectToLineEnd,
        'SELECT_START_OF_DOCUMENT' => EditCommand::SelectToDocumentStart, 'SELECT_END_OF_DOCUMENT' => EditCommand::SelectToDocumentEnd,
        'SELECT_NEXT_PAGE' => EditCommand::SelectPageDown, 'SELECT_PREVIOUS_PAGE' => EditCommand::SelectPageUp,
        'SELECT_NEXT_CHAR' => EditCommand::SelectRight, 'SELECT_PREVIOUS_CHAR' => EditCommand::SelectLeft,
        'SELECT_NEXT_LINE' => EditCommand::SelectDown, 'SELECT_PREVIOUS_LINE' => EditCommand::SelectUp,
        'MOVE_TO_NEXT_WORD' => EditCommand::MoveWordRight, 'MOVE_TO_PREVIOUS_WORD' => EditCommand::MoveWordLeft,
        'MOVE_TO_START_OF_LINE' => EditCommand::MoveToLineStart, 'MOVE_TO_END_OF_LINE' => EditCommand::MoveToLineEnd,
        'MOVE_TO_START_OF_DOCUMENT' => EditCommand::MoveToDocumentStart, 'MOVE_TO_END_OF_DOCUMENT' => EditCommand::MoveToDocumentEnd,
        'MOVE_TO_NEXT_PAGE' => EditCommand::PageDown, 'MOVE_TO_PREVIOUS_PAGE' => EditCommand::PageUp,
        'MOVE_TO_NEXT_CHAR' => EditCommand::MoveRight, 'MOVE_TO_PREVIOUS_CHAR' => EditCommand::MoveLeft,
        'MOVE_TO_NEXT_LINE' => EditCommand::MoveDown, 'MOVE_TO_PREVIOUS_LINE' => EditCommand::MoveUp,
        'INSERT_PARAGRAPH_SEPARATOR' => EditCommand::InsertNewline, 'INSERT_LINE_SEPARATOR' => EditCommand::InsertNewline,
        'CANCEL' => EditCommand::Cancel,
    ];

    /** A modifier key's own flag (Qt::KeyboardModifier): the key's press sets it, its release clears it. */
    private const array MODIFIER_FLAGS = [0x01000020 => 0x02000000, 0x01000021 => 0x04000000, 0x01000022 => 0x10000000, 0x01000023 => 0x08000000];

    private readonly WeakReference $canvas;

    /** @var array<int, true> Qt keys whose KeyPressed posted and whose KeyReleased has not. */
    private array $held = [];

    private ?QEventFilter $filter;

    public function __construct(QtCanvas $canvas, QWidget $host)
    {
        $this->canvas = WeakReference::create($canvas);
        // The C++ filter holds the closure, and the canvas holds the host and this: weak references break the cycles.
        $self = WeakReference::create($this);
        $this->filter = new QEventFilter(static fn (QObject $watched, QEvent $event): bool => $self->get()?->filter($event) ?? false);
        $host->installEventFilter($this->filter);
    }

    public function detach(): void
    {
        $this->filter = null;
    }

    private function filter(QEvent $event): bool
    {
        if (is_null($this->filter)) {
            return false;
        }

        return match ($event->type()) {
            Type::KEY_PRESS => $event instanceof QKeyEvent && $this->pressed($event),
            Type::KEY_RELEASE => $event instanceof QKeyEvent && $this->released($event),
            Type::INPUT_METHOD => $event instanceof QInputMethodEvent && $this->composed($event),
            default => false,
        };
    }

    private function pressed(QKeyEvent $event): bool
    {
        $canvas = $this->canvas();
        if (is_null($canvas)) {
            return false;
        }
        $tab = $event->key() === QtKey::TAB->value || $event->key() === QtKey::BACKTAB->value;
        if ($tab && ! $canvas->takesTab()) {
            return false;
        }
        $modifiers = QtCanvasPointer::modifiers($event->modifiers() | (self::MODIFIER_FLAGS[$event->key()] ?? 0));
        $this->held[$event->key()] = true;
        $this->post($canvas, static fn (string $window, string $path, string $uuid): KeyPressed => new KeyPressed(
            $window, $path, $uuid, QtWindowKeys::keyOf($event), QtWindowKeys::character($event), $modifiers, $event->isAutoRepeat(),
        ));
        $command = self::command($event);
        if (! is_null($command)) {
            $this->post($canvas, static fn (string $window, string $path, string $uuid): EditCommanded => new EditCommanded($window, $path, $uuid, $command));
        } elseif (! in_array($event->key(), QtWindowKeys::MODIFIERS, true) && QtWindowKeys::character($event) !== '') {
            $text = QtWindowKeys::character($event);
            $this->post($canvas, static fn (string $window, string $path, string $uuid): TextCommitted => new TextCommitted($window, $path, $uuid, $text));
        }

        return true;
    }

    private function released(QKeyEvent $event): bool
    {
        $canvas = $this->canvas();
        // Only the release of a key whose press posted: an input method took a composing key's press.
        if (is_null($canvas) || ! isset($this->held[$event->key()])) {
            return false;
        }
        // X11 sends a release before each repeat press: the key is still held.
        if ($event->isAutoRepeat()) {
            return true;
        }
        unset($this->held[$event->key()]);
        $modifiers = QtCanvasPointer::modifiers($event->modifiers() & ~(self::MODIFIER_FLAGS[$event->key()] ?? 0));
        $this->post($canvas, static fn (string $window, string $path, string $uuid): KeyReleased => new KeyReleased(
            $window, $path, $uuid, QtWindowKeys::keyOf($event), $modifiers,
        ));

        return true;
    }

    /** Composed text posts; Qt keeps the event (false), as the widget's own handling ends it. */
    private function composed(QInputMethodEvent $event): bool
    {
        $canvas = $this->canvas();
        $text = $event->commitString();
        if (! is_null($canvas) && $text !== '') {
            $this->post($canvas, static fn (string $window, string $path, string $uuid): TextCommitted => new TextCommitted($window, $path, $uuid, $text));
        }

        return false;
    }

    /**
     * macOS's own Ctrl+K and Ctrl+Y (deleteToEndOfLine:, yank:) first, as AppKit and GTK post them:
     * Qt's Mac table binds them to Cut and Paste. Then the first standard key sequence the event
     * matches, then Tab and Backtab, which no StandardKey names, and Backspace, which none names on X11.
     */
    public static function command(QKeyEvent $event, bool $macos = PHP_OS_FAMILY === 'Darwin'): ?EditCommand
    {
        // Qt reads the physical Control key as Meta (0x10000000) on macOS.
        if ($macos && $event->modifiers() === 0x10000000) {
            $emacs = match ($event->key()) {
                QtKey::K->value => EditCommand::DeleteToLineEnd,
                QtKey::Y->value => EditCommand::Yank,
                default => null,
            };
            if (! is_null($emacs)) {
                return $emacs;
            }
        }
        foreach (self::COMMANDS as $name => $command) {
            if ($event->matches(constant(StandardKey::class.'::'.$name))) {
                return $command;
            }
        }

        return match ($event->key()) {
            QtKey::TAB->value => EditCommand::InsertTab,
            QtKey::BACKTAB->value => EditCommand::InsertBacktab,
            // Qt's X11 table binds plain Backspace to no StandardKey; Qt's own editors read the key itself.
            QtKey::BACKSPACE->value => EditCommand::DeleteBackward,
            default => null,
        };
    }

    /** The canvas while it is placed, focused and in an open window. */
    private function canvas(): ?QtCanvas
    {
        $canvas = $this->canvas->get();
        if (! $canvas instanceof QtCanvas || $canvas->isRemoved() || ! $canvas->hasFocus()) {
            return null;
        }
        $window = $canvas->window();

        return $window instanceof QtWindow && $window->isOpen() ? $canvas : null;
    }

    /** @param \Closure(string, string, string): PrimitiveEventOccurred $mail */
    private function post(QtCanvas $canvas, \Closure $mail): void
    {
        /** @var QtWindow $window */
        $window = $canvas->window();
        $window->session()->post($mail($window->name(), $canvas->path(), $canvas->uuid()));
    }
}
