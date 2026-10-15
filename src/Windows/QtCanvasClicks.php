<?php

namespace Jovian\Toolkits\Qt\Windows;

use Jovian\Toolkits\Qt\Bridge\QtSession;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use QApplication;
use QCoreApplication;
use QEvent;
use QEvent\Type;
use QEventFilter;
use QGuiApplication;
use QMouseEvent;
use QObject;
use QWidget;
use Surface\Contracts\Windows\Mail\View\CanvasClicked;
use Surface\Contracts\Windows\Primitives\TKCanvas;
use WeakReference;

/**
 * A toolkit window's canvas clicks as mail. An application filter (mouse events go to the widget
 * under the pointer) sees a left press on a canvas, counted once: only its delivery to the widget
 * under the pointer. Not with Qt's Meta, which is the Control key on macOS (a right click there). The
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
        $this->filter = new QEventFilter(static function (QObject $watched, Type|int $type, QEvent $event) use ($self): bool {
            $self->get()?->filtered($watched, $type, $event);

            return false;
        }, [Type::MOUSE_BUTTON_PRESS, Type::MOUSE_MOVE, Type::MOUSE_BUTTON_RELEASE]);
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
        if (! $watched instanceof QWidget || ! $event instanceof QMouseEvent) {
            return;
        }
        [$gx, $gy] = $event->globalPosition();
        if (QApplication::widgetAt((int) round($gx), (int) round($gy)) !== $watched) {
            return;
        }

        if ($type === Type::MOUSE_BUTTON_PRESS) {
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
    private function pressed(QWidget $hit, float $gx, float $gy, int $at): void
    {
        [$canvas, $native] = $this->canvasOf($hit);
        if (! is_null($canvas)) {
            [$x, $y] = $native->mapFromGlobal($gx, $gy);
            $this->press = [$canvas, $gx, $gy, $x, $y, $at];
        }
    }

    private function released(QWidget $hit, int $at): void
    {
        $press = $this->press;
        $this->press = null;
        if (is_null($press) || ($this->hold_cancels && $at - $press[5] >= QGuiApplication::styleHints()->mousePressAndHoldInterval())) {
            return;
        }
        [$canvas] = $this->canvasOf($hit);
        if ($canvas === $press[0]) {
            $this->session->post(new CanvasClicked($this->window->name(), $canvas->path(), $canvas->uuid(), $press[3], $press[4]));
        }
    }

    /**
     * The enabled canvas of this window holding $hit, and its native: walked up parent widgets to the
     * first a canvas owns. Null when it or one of its groups is disabled, or no canvas holds it.
     *
     * @return array{TKCanvas|null, QWidget|null}
     */
    private function canvasOf(QWidget $hit): array
    {
        $owners = [];
        foreach ($this->window->registry()->all() as $primitive) {
            if ($primitive instanceof TKCanvas && $primitive instanceof QtNative) {
                $owners[spl_object_id($primitive->native())] = $primitive;
            }
        }
        for ($widget = $hit; ! is_null($widget); $widget = $widget->parentWidget()) {
            $canvas = $owners[spl_object_id($widget)] ?? null;
            if (is_null($canvas)) {
                continue;
            }
            for ($at = $canvas; ! is_null($at); $at = $at->parent()) {
                if (! $at->isEnabled()) {
                    return [null, null];
                }
            }

            return [$canvas, $widget];
        }

        return [null, null];
    }
}
