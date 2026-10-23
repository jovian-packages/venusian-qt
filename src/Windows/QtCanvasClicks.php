<?php

namespace Jovian\Toolkits\Qt\Windows;

use Jovian\Toolkits\Qt\Bridge\QtSession;
use QCoreApplication;
use QEvent;
use QEvent\Type;
use QEventFilter;
use QGuiApplication;
use QMouseEvent;
use QObject;
use QPointF;
use Surface\Contracts\Windows\Mail\View\CanvasClicked;
use Surface\Contracts\Windows\Primitives\TKCanvas;
use WeakReference;

/**
 * A toolkit window's canvas clicks as mail. An application filter (mouse events go to the widget
 * under the pointer) sees a left press on a canvas, counted once: only its delivery to the widget
 * under the pointer, or to the QWindow a canvas lends to Metal or Vulkan (QtCanvasPointer::canvasOf()
 * finds the canvas either way). Not with Qt's Meta, which is the Control key on macOS (a right click there). The
 * release over the same canvas posts, unless the pointer moved past startDragDistance() in between or,
 * on Linux, the press was held past mousePressAndHoldInterval() by the events' timestamps (a right
 * click there). The mail
 * carries where the press landed, from the canvas's top-left. The filter returns false.
 */
final class QtCanvasClicks
{
    /** Qt::MetaModifier: the Control key on macOS, where Qt swaps Control and Command. */
    public const int META_MODIFIER = 0x10000000;

    private ?QEventFilter $filter;

    private bool $hold_cancels = PHP_OS_FAMILY === 'Linux';

    /** @var array{TKCanvas, float, float, float, float, int}|null the press: its canvas, its global point, its canvas point, its event timestamp (ms) */
    private ?array $press = null;

    public function __construct(
        private readonly QtWindow $window,
        private readonly QtSession $session,
    ) {
        $self = WeakReference::create($this);
        // Held weakly, as the window's own filters: a strong capture is a cycle through C++.
        $this->filter = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            $type = $event->type();
            if ($type === Type::MOUSE_BUTTON_PRESS || $type === Type::MOUSE_BUTTON_DBL_CLICK || $type === Type::MOUSE_MOVE || $type === Type::MOUSE_BUTTON_RELEASE) {
                $self->get()?->filtered($watched, $type, $event);
            }

            return false;
        });
        QCoreApplication::instance()?->installEventFilter($this->filter);
    }

    /** Whether a press held past the hold interval is no click (it is a right click): on Linux by default. */
    public function holdCancels(bool $on): void
    {
        $this->hold_cancels = $on;
    }

    /** Whether a press of $button under $modifiers can start a click. */
    public static function primary(int $button, int $modifiers, bool $macos = PHP_OS_FAMILY === 'Darwin'): bool
    {
        return $button === 1 && ! ($macos && ($modifiers & self::META_MODIFIER) !== 0);
    }

    public function watching(): bool
    {
        return ! is_null($this->filter);
    }

    /** The window closed: stop watching the application. */
    public function detach(): void
    {
        $this->press = null;
        if (! is_null($this->filter)) {
            QCoreApplication::instance()?->removeEventFilter($this->filter);
            $this->filter = null;
        }
    }

    private function filtered(QObject $watched, Type|int $type, QEvent $event): void
    {
        if (! $event instanceof QMouseEvent) {
            return;
        }
        $global = $event->globalPosition();
        [$gx, $gy] = [$global->x(), $global->y()];
        // The widget under the pointer, or a lent QWindow (a Metal or Vulkan canvas), whose events never enter the widget tree.
        if (! $this->window->canvasPointer()->firstDelivery($watched, $gx, $gy)) {
            return;
        }

        // Qt delivers the second press of a double click to a widget as the double click alone.
        if ($type === Type::MOUSE_BUTTON_PRESS || $type === Type::MOUSE_BUTTON_DBL_CLICK) {
            $this->press = null;
            if (self::primary($event->button(), $event->modifiers())) {
                $this->pressed($watched, $gx, $gy, $event->timestamp());
            }
        } elseif ($type === Type::MOUSE_MOVE) {
            if (! is_null($this->press) && hypot($gx - $this->press[1], $gy - $this->press[2]) > QGuiApplication::styleHints()->startDragDistance()) {
                $this->press = null;
            }
        } elseif ($type === Type::MOUSE_BUTTON_RELEASE && $event->button() === 1) {
            $this->released($watched, $event->timestamp());
        }
    }

    /** $at: the press event's timestamp (ms), which holds are timed by, not the time it is handled. */
    private function pressed(QObject $hit, float $gx, float $gy, int $at): void
    {
        [$canvas, $native] = $this->window->canvasPointer()->canvasOf($hit);
        if (! is_null($canvas)) {
            $local = $native->mapFromGlobal(new QPointF($gx, $gy));
            [$x, $y] = [$local->x(), $local->y()];
            $this->press = [$canvas, $gx, $gy, $x, $y, $at];
        }
    }

    private function released(QObject $hit, int $at): void
    {
        $press = $this->press;
        $this->press = null;
        if (is_null($press) || ($this->hold_cancels && $at - $press[5] >= QGuiApplication::styleHints()->mousePressAndHoldInterval())) {
            return;
        }
        [$canvas] = $this->window->canvasPointer()->canvasOf($hit);
        if ($canvas === $press[0]) {
            $this->session->post(new CanvasClicked($this->window->name(), $canvas->path(), $canvas->uuid(), $press[3], $press[4]));
        }
    }
}
