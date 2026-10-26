<?php

namespace Jovian\Toolkits\Qt\Windows;

use Jovian\Toolkits\Qt\Bridge\QtSession;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\QtCanvas;
use QApplication;
use QCoreApplication;
use QEvent;
use QEvent\Type;
use QEventFilter;
use QGuiApplication;
use QMouseEvent;
use QObject;
use QWheelEvent;
use QWidget;
use QWindow;
use Qt\MouseEventSource;
use Qt\ScrollPhase as QtScrollPhase;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\CanvasDragged;
use Surface\Contracts\Windows\Mail\View\CanvasEntered;
use Surface\Contracts\Windows\Mail\View\CanvasLeft;
use Surface\Contracts\Windows\Mail\View\CanvasMoved;
use Surface\Contracts\Windows\Mail\View\CanvasPressed;
use Surface\Contracts\Windows\Mail\View\CanvasReleased;
use Surface\Contracts\Windows\Mail\View\CanvasWheeled;
use Surface\Contracts\Windows\Mail\View\ScrollPhase;
use Surface\Contracts\Windows\Primitives\TKCanvas;
use WeakReference;

/**
 * A toolkit window's canvas presses, drags, releases and wheels as mail, from an application event
 * filter: a canvas lent to the metal or vulkan engine shows a QWindow in a window container, whose
 * events never enter the widget tree a widget's own filter would see.
 *
 * An event Qt passes up to parents counts once: a press, a double click or a wheel at its delivery to
 * the widget under the pointer (or to a lent QWindow, which passes nothing on); a move or a release
 * at its delivery to the object the press went to, where Qt's implicit grab sends them, inside the
 * window or out of it. The first button down on a watching, enabled canvas owns the press: other
 * buttons' presses and releases post nothing until it ends. Exactly one CanvasReleased follows every
 * CanvasPressed: at the owning button's release, or at once at the last point posted when a context
 * menu opens for it (QtRightClicks calls interrupt()). A press the code ends — watchPointer(false),
 * the canvas or a group above it disabled, the canvas removed, the window closed — drops silently.
 *
 * Qt sends no triple click, so presses are counted here from the events' timestamps, with Qt's
 * double-click interval and distance. A wheel over a watching canvas posts and the filter eats it,
 * so an enclosing scroll view never scrolls with it.
 */
final class QtCanvasPointer
{
    /** Qt::KeyboardModifier bits. */
    private const int SHIFT = 0x02000000;

    private const int CONTROL = 0x04000000;

    private const int ALT = 0x08000000;

    private const int META = 0x10000000;

    private ?QEventFilter $filter;

    /** @var array{TKCanvas, QWidget, QObject, int, MouseButton, float, float, Modifiers}|null the press owned: canvas, its native, its receiver, Qt button, button, last canvas point, modifiers last seen */
    private ?array $press = null;

    /** @var array{TKCanvas, int, float, float, int, int}|null the press counted last: canvas, Qt button, global point, timestamp (ms), count */
    private ?array $counted = null;

    /** @var WeakReference<TKCanvas>|null the watching canvas the pointer is over */
    private ?WeakReference $inside = null;

    /** The last wheel's phase: a scroll end after momentum ends the momentum. */
    private QtScrollPhase $last_phase = QtScrollPhase::NO_SCROLL_PHASE;

    public function __construct(
        private readonly QtWindow $window,
        private readonly QtSession $session,
    ) {
        $self = WeakReference::create($this);
        // Held weakly, as the window's own filters: a strong capture is a cycle through C++.
        $this->filter = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            $type = $event->type();
            if ($type !== Type::MOUSE_BUTTON_PRESS && $type !== Type::MOUSE_BUTTON_DBL_CLICK && $type !== Type::MOUSE_MOVE
                && $type !== Type::MOUSE_BUTTON_RELEASE && $type !== Type::WHEEL && $type !== Type::ENTER && $type !== Type::LEAVE) {
                return false;
            }

            return $self->get()?->filtered($watched, $type, $event) ?? false;
        });
        QCoreApplication::instance()?->installEventFilter($this->filter);
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

    /**
     * The press ended without a release: the release goes out at the last point posted, with the
     * modifiers last seen; nothing when no press is owned.
     */
    public function interrupt(): void
    {
        $press = $this->holding();
        $this->press = null;
        if (is_null($press)) {
            return;
        }
        [$canvas, , , , $button, $x, $y, $modifiers] = $press;
        $this->session->post(new CanvasReleased($this->window->name(), $canvas->path(), $canvas->uuid(), $x, $y, $button, $modifiers));
    }

    /**
     * The enabled canvas of this window holding $hit, and its native: a widget walked up its parents
     * to a canvas's native, or the QWindow a canvas lends. Null when it or one of its groups is
     * disabled, or no canvas holds it.
     *
     * @return array{TKCanvas|null, QWidget|null}
     */
    public function canvasOf(QObject $hit): array
    {
        $owners = [];
        foreach ($this->window->registry()->all() as $primitive) {
            if ($primitive instanceof TKCanvas && $primitive instanceof QtNative) {
                $owners[spl_object_id($primitive->native())] = $primitive;
                if ($primitive instanceof QtCanvas && ! is_null($primitive->lentWindow())) {
                    $owners[spl_object_id($primitive->lentWindow())] = $primitive;
                }
            }
        }
        $canvas = null;
        if ($hit instanceof QWidget) {
            for ($widget = $hit; ! is_null($widget) && is_null($canvas); $widget = $widget->parentWidget()) {
                $canvas = $owners[spl_object_id($widget)] ?? null;
            }
        } else {
            $canvas = $owners[spl_object_id($hit)] ?? null;
        }
        if (is_null($canvas)) {
            return [null, null];
        }
        for ($at = $canvas; ! is_null($at); $at = $at->parent()) {
            if (! $at->isEnabled()) {
                return [null, null];
            }
        }

        return [$canvas, $canvas->native()];
    }

    /** Whether $watched is the QWindow a canvas of this window lends to Metal or Vulkan, enabled or not. */
    public function lends(QObject $watched): bool
    {
        if (! $watched instanceof QWindow) {
            return false;
        }
        foreach ($this->window->registry()->all() as $primitive) {
            if ($primitive instanceof QtCanvas && $primitive->lentWindow() === $watched) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $watched is where an event at the global point is delivered first: the widget under it,
     * or a lent QWindow. Real input reaches a top level's own QWindow before the widget; that is no
     * canvas's, so it is not a first delivery.
     */
    public function firstDelivery(QObject $watched, float $gx, float $gy): bool
    {
        return $watched instanceof QWindow
            ? $this->lends($watched)
            : $watched instanceof QWidget && QApplication::widgetAt((int) round($gx), (int) round($gy)) === $watched;
    }

    /** Qt::MouseButton as Surface's: left, right, middle and the two side buttons; null for the others. */
    public static function button(int $button): ?MouseButton
    {
        return match ($button) {
            1 => MouseButton::LEFT,
            2 => MouseButton::RIGHT,
            4 => MouseButton::MIDDLE,
            8 => MouseButton::X1,
            16 => MouseButton::X2,
            default => null,
        };
    }

    /** Qt::KeyboardModifiers as Surface's. On macOS Qt swaps them: ControlModifier is Command (meta) and MetaModifier is Control. */
    public static function modifiers(int $modifiers, bool $macos = PHP_OS_FAMILY === 'Darwin'): Modifiers
    {
        $control = ($modifiers & self::CONTROL) !== 0;
        $meta = ($modifiers & self::META) !== 0;

        return new Modifiers(
            shift: ($modifiers & self::SHIFT) !== 0,
            ctrl: $macos ? $meta : $control,
            alt: ($modifiers & self::ALT) !== 0,
            meta: $macos ? $control : $meta,
        );
    }

    /**
     * Whether a wheel's deltas are trackpad points, by Qt's platform plugin ($platform: Qt's name, the
     * running one unless given). cocoa fills pixelDelta for mouse wheels too (scrollingDelta × 20), so
     * there the source tells: a trackpad's is synthesized by the system. wayland fills pixelDelta only
     * for finger and continuous sources. xcb fills it from the X driver's raw valuator when its
     * increment is over 15, a mouse notch reading 120 (Qt: "driver-specific and unreliable"), so there
     * every wheel is notches; a touchpad's come in fractions of one.
     */
    public static function precise(QWheelEvent $event, ?string $platform = null): bool
    {
        $platform ??= QGuiApplication::platformName();
        if ($platform === 'cocoa') {
            return $event->source() === MouseEventSource::MOUSE_EVENT_SYNTHESIZED_BY_SYSTEM;
        }
        if (! str_starts_with($platform, 'wayland')) {
            return false;
        }
        $pixel = $event->pixelDelta();

        return $pixel->x() !== 0 || $pixel->y() !== 0;
    }

    /** Whether the event is eaten: a wheel posted for a watching canvas. */
    private function filtered(QObject $watched, Type|int $type, QEvent $event): bool
    {
        if ($event instanceof QWheelEvent) {
            return $this->wheeled($watched, $event);
        }
        if ($type === Type::ENTER || $type === Type::LEAVE) {
            $this->crossed($watched, $type === Type::ENTER);

            return false;
        }
        if (! $event instanceof QMouseEvent) {
            return false;
        }
        $global = $event->globalPosition();
        [$gx, $gy] = [$global->x(), $global->y()];
        if ($type === Type::MOUSE_BUTTON_PRESS || $type === Type::MOUSE_BUTTON_DBL_CLICK) {
            if ($this->firstDelivery($watched, $gx, $gy)) {
                $this->pressed($watched, $event, $gx, $gy);
            }

            return false;
        }
        $press = $this->holding();
        if (is_null($press) && $type === Type::MOUSE_MOVE && $event->buttons() === 0) {
            $this->hovered($watched, $event);

            return false;
        }
        if (is_null($press) || $watched !== $press[2]) {
            return false;
        }
        if ($type === Type::MOUSE_MOVE) {
            $this->moved($press, $event);
        } elseif ($event->button() === $press[3]) {
            $this->released($press, $event);
        }

        return false;
    }

    private function pressed(QObject $watched, QMouseEvent $event, float $gx, float $gy): void
    {
        // A double click while its button's press is owned is the press Qt delivered just before it.
        if (! is_null($this->holding())) {
            return;
        }
        $button = self::button($event->button());
        if (is_null($button)) {
            return;
        }
        [$canvas, $native] = $this->canvasOf($watched);
        if (is_null($canvas) || ! $canvas->isWatchingPointer()) {
            return;
        }
        $local = $native->mapFromGlobal($event->globalPosition());
        [$x, $y] = [$local->x(), $local->y()];
        $clicks = $this->counting($canvas, $event->button(), $gx, $gy, $event->timestamp());
        $modifiers = self::modifiers($event->modifiers());
        $this->counted = [$canvas, $event->button(), $gx, $gy, $event->timestamp(), $clicks];
        $this->press = [$canvas, $native, $watched, $event->button(), $button, $x, $y, $modifiers];
        $this->session->post(new CanvasPressed($this->window->name(), $canvas->path(), $canvas->uuid(), $x, $y, $button, $clicks, $modifiers));
    }

    /** @param array{TKCanvas, QWidget, QObject, int, MouseButton, float, float, Modifiers} $press */
    private function moved(array $press, QMouseEvent $event): void
    {
        [$canvas, $native, $receiver, $qt, $button, $lx, $ly] = $press;
        $local = $native->mapFromGlobal($event->globalPosition());
        [$x, $y] = [$local->x(), $local->y()];
        if ($x === $lx && $y === $ly) {
            return;
        }
        $modifiers = self::modifiers($event->modifiers());
        $this->press = [$canvas, $native, $receiver, $qt, $button, $x, $y, $modifiers];
        $this->session->post(new CanvasDragged($this->window->name(), $canvas->path(), $canvas->uuid(), $x, $y, $button, $modifiers));
    }

    /** @param array{TKCanvas, QWidget, QObject, int, MouseButton, float, float, Modifiers} $press */
    private function released(array $press, QMouseEvent $event): void
    {
        [$canvas, $native, , , $button] = $press;
        $this->press = null;
        $local = $native->mapFromGlobal($event->globalPosition());
        $this->session->post(new CanvasReleased($this->window->name(), $canvas->path(), $canvas->uuid(), $local->x(), $local->y(), $button, self::modifiers($event->modifiers())));
        // No crossing posts during a press: a release away from the canvas the pointer was over is its exit.
        $inside = $this->inside?->get();
        $global = $event->globalPosition();
        $under = QApplication::widgetAt((int) round($global->x()), (int) round($global->y()));
        if (! is_null($inside) && (is_null($under) || $this->canvasOf($under)[0] !== $inside)) {
            $this->leave($inside);
        }
    }

    /**
     * A move with no button and no press owned: CanvasMoved (latest-wins) over a watching canvas,
     * whose canvas becomes the one the pointer is over (CanvasEntered) if it was not.
     */
    private function hovered(QObject $watched, QMouseEvent $event): void
    {
        [$canvas, $native] = $this->canvasOf($watched);
        if (is_null($canvas) || ! $canvas->isWatchingPointer()) {
            return;
        }
        $this->enter($canvas);
        $local = $native->mapFromGlobal($event->globalPosition());
        $this->session->postLatest(
            "view.moved.{$this->window->name()}.{$canvas->path()}",
            new CanvasMoved($this->window->name(), $canvas->path(), $canvas->uuid(), $local->x(), $local->y(), self::modifiers($event->modifiers())),
        );
    }

    /** ENTER or LEAVE on a canvas's own widget or lent window (a child's crossings are inside the canvas); none during a press. */
    private function crossed(QObject $watched, bool $enter): void
    {
        if (! is_null($this->holding())) {
            return;
        }
        [$canvas, $native] = $this->canvasOf($watched);
        if (is_null($canvas) || ($watched !== $native && ! $this->lends($watched))) {
            return;
        }
        if ($enter && $canvas->isWatchingPointer()) {
            $this->enter($canvas);
        } elseif (! $enter) {
            $this->leave($canvas);
        }
    }

    private function enter(TKCanvas $canvas): void
    {
        $inside = $this->inside?->get();
        if ($inside === $canvas) {
            return;
        }
        $this->leave($inside);
        $this->inside = WeakReference::create($canvas);
        $this->session->post(new CanvasEntered($this->window->name(), $canvas->path(), $canvas->uuid()));
    }

    private function leave(?TKCanvas $canvas): void
    {
        if (is_null($canvas) || $this->inside?->get() !== $canvas) {
            return;
        }
        $this->inside = null;
        if (! $canvas->isRemoved()) {
            // The canvas's last CanvasMoved waits among the latest-wins mail: it goes out before the exit.
            $this->session->flushLatest();
            $this->session->post(new CanvasLeft($this->window->name(), $canvas->path(), $canvas->uuid()));
        }
    }

    /** A wheel's place in a gesture; a scroll end after momentum ends the momentum. */
    public static function phase(QtScrollPhase $phase, QtScrollPhase $last): ScrollPhase
    {
        return match ($phase) {
            QtScrollPhase::SCROLL_BEGIN => ScrollPhase::BEGAN,
            QtScrollPhase::SCROLL_UPDATE => ScrollPhase::CHANGED,
            QtScrollPhase::SCROLL_END => $last === QtScrollPhase::SCROLL_MOMENTUM ? ScrollPhase::MOMENTUM_ENDED : ScrollPhase::ENDED,
            QtScrollPhase::SCROLL_MOMENTUM => ScrollPhase::MOMENTUM,
            QtScrollPhase::NO_SCROLL_PHASE => ScrollPhase::NONE,
        };
    }

    private function wheeled(QObject $watched, QWheelEvent $event): bool
    {
        $global = $event->globalPosition();
        if (! $this->firstDelivery($watched, $global->x(), $global->y())) {
            return false;
        }
        [$canvas] = $this->canvasOf($watched);
        if (is_null($canvas) || ! $canvas->isWatchingPointer()) {
            return false;
        }
        $precise = self::precise($event);
        $delta = $precise ? $event->pixelDelta() : $event->angleDelta();
        $scale = $precise ? 1.0 : 120.0;
        $phase = self::phase($event->phase(), $this->last_phase);
        $this->last_phase = $event->phase();
        $this->session->post(new CanvasWheeled(
            $this->window->name(), $canvas->path(), $canvas->uuid(),
            -$delta->x() / $scale, -$delta->y() / $scale, $precise, self::modifiers($event->modifiers()), $phase,
        ));

        return true;
    }

    /**
     * The press owned, if its canvas still takes pointer mail. A canvas that was removed, stopped
     * watching, or was disabled itself or inside a group drops the press here, silently.
     *
     * @return array{TKCanvas, QWidget, QObject, int, MouseButton, float, float, Modifiers}|null
     */
    private function holding(): ?array
    {
        $press = $this->press;
        if (is_null($press)) {
            return null;
        }
        $canvas = $press[0];
        if ($canvas->isRemoved() || ! $canvas->isWatchingPointer()) {
            $this->press = null;

            return null;
        }
        for ($at = $canvas; ! is_null($at); $at = $at->parent()) {
            if (! $at->isEnabled()) {
                $this->press = null;

                return null;
            }
        }

        return $press;
    }

    /** This press's place in a run of clicks: the last one's count + 1 while it is the same button on the same canvas, in time and in place. */
    private function counting(TKCanvas $canvas, int $button, float $gx, float $gy, int $at): int
    {
        $counted = $this->counted;
        if (is_null($counted) || $counted[0] !== $canvas || $counted[1] !== $button) {
            return 1;
        }
        $hints = QGuiApplication::styleHints();
        $near = hypot($gx - $counted[2], $gy - $counted[3]) <= $hints->mouseDoubleClickDistance();

        return $near && $at - $counted[4] <= $hints->mouseDoubleClickInterval() ? $counted[5] + 1 : 1;
    }
}
