<?php

namespace Jovian\Toolkits\Qt\Windows;

use Jovian\Toolkits\Qt\Bridge\QtSession;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use QApplication;
use QContextMenuEvent;
use QContextMenuEvent\Reason;
use QCoreApplication;
use QEvent;
use QEvent\Type;
use QEventFilter;
use QGuiApplication;
use QMainWindow;
use QMouseEvent;
use QObject;
use QPointF;
use QTimer;
use QWidget;
use Surface\Contracts\Windows\Mail\View\RightClicked;
use Surface\Contracts\Windows\Mail\WindowRightClicked;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use Surface\Windows\Menus\ContextMenu;
use WeakReference;

/**
 * A toolkit window's right clicks as mail. An application filter (context-menu events go to the
 * widget under the pointer, not the window) sees Qt's context-menu request, which Qt raises for the
 * right button and for Ctrl-click on macOS; the menu key's (Keyboard reason) is not a click. On Linux
 * a primary press held still past Qt's press-and-hold interval is one too: a touchscreen registered
 * as a pointer (the Pi's) reaches Qt as a left press, and Qt raises no context menu for a long one.
 * An event Qt passes up to parents counts once: only its delivery to the widget under the pointer.
 * The QWindow a canvas lends to Metal or Vulkan gets its events outside the widget tree; they count
 * as its canvas's. Qt 6.9+ raises the context-menu request there too; older Qt raises none, so there
 * the right press is the request.
 * After the mail, the context menu of the primitive or its nearest group that has one opens at the
 * click, and the filter eats the context-menu event, so the widget's own menu (a text input's) stays shut.
 */
final class QtRightClicks
{
    private ?QEventFilter $filter;

    private readonly QTimer $hold;

    /** @var array{QWidget, float, float}|null the press held: where it landed, its global point */
    private ?array $held = null;

    private bool $hold_on_press = PHP_OS_FAMILY === 'Linux';

    private bool $lent_press_opens;

    /** The context menu opened last. */
    private ?QtContextMenu $menu = null;

    public function __construct(
        private readonly QtWindow $window,
        private readonly QMainWindow $native,
        private readonly QWidget $content,
        private readonly QtSession $session,
    ) {
        $this->lent_press_opens = version_compare(qVersion(), '6.9.0', '<');
        $self = WeakReference::create($this);

        $this->hold = new QTimer();
        $this->hold->setSingleShot(true);
        $this->hold->setInterval(QGuiApplication::styleHints()->mousePressAndHoldInterval());
        QObject::connect($this->hold, 'timeout()', static function () use ($self): void {
            $self->get()?->held();
        });

        // Held weakly, as the window's own filters: a strong capture is a cycle through C++.
        $this->filter = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            $type = $event->type();
            if ($type !== Type::CONTEXT_MENU && $type !== Type::MOUSE_BUTTON_PRESS && $type !== Type::MOUSE_MOVE && $type !== Type::MOUSE_BUTTON_RELEASE) {
                return false;
            }

            return $self->get()?->filtered($watched, $type, $event) ?? false;
        });
        QCoreApplication::instance()?->installEventFilter($this->filter);
    }

    /** Whether a held primary press is a right click: on Linux by default. */
    public function holdOnPress(bool $on): void
    {
        $this->hold_on_press = $on;
        $this->hold->stop();
        $this->held = null;
    }

    public function watching(): bool
    {
        return ! is_null($this->filter);
    }

    /** The window closed: stop watching the application, and close its context menu (a parentless popup Qt leaves open on Linux). */
    public function detach(): void
    {
        $this->menu?->menu()->hide();
        $this->hold->stop();
        $this->held = null;
        if (! is_null($this->filter)) {
            QCoreApplication::instance()?->removeEventFilter($this->filter);
            $this->filter = null;
        }
    }

    /** The context menu opened last, open or closed since. */
    public function openMenu(): ?QtContextMenu
    {
        return $this->menu;
    }

    /**
     * Whether a right press on a lent QWindow is its context-menu request: Qt 6.9+ raises a
     * QContextMenuEvent for an ignored right press on a QWindow as for a widget, older Qt raises none.
     */
    public function lentPressOpensMenu(bool $on): void
    {
        $this->lent_press_opens = $on;
    }

    /** Whether the event is eaten: a context-menu request this opened a menu for. */
    private function filtered(QObject $watched, Type|int $type, QEvent $event): bool
    {
        // The QWindow a canvas lends to Metal or Vulkan gets its events outside the widget tree, and
        // passes none on: they count as its canvas's, each at its first delivery. A top level's own
        // QWindow, which real input reaches before the widget, is no canvas's and counts for nothing.
        $lent = $this->window->canvasPointer()->lends($watched);
        if ($lent) {
            [, $watched] = $this->window->canvasPointer()->canvasOf($watched);
        }
        if (! $watched instanceof QWidget) {
            return false;
        }

        if ($event instanceof QContextMenuEvent) {
            $global = $event->globalPos();
            [$gx, $gy] = [$global->x(), $global->y()];

            return $event->reason() !== Reason::KEYBOARD && ! ($lent && $this->lent_press_opens)
                && ($lent || $this->underPointer($watched, $gx, $gy)) && $this->at($watched, (float) $gx, (float) $gy);
        }

        if (! $event instanceof QMouseEvent) {
            return false;
        }
        $global = $event->globalPosition();
        [$gx, $gy] = [$global->x(), $global->y()];
        if ($lent && $this->lent_press_opens && $type === Type::MOUSE_BUTTON_PRESS && $event->button() === 2) {
            $this->at($watched, $gx, $gy);

            return false;
        }
        if ($type === Type::MOUSE_BUTTON_PRESS && $event->button() === 1 && $this->hold_on_press && ($lent || $this->underPointer($watched, (int) round($gx), (int) round($gy)))) {
            $this->held = [$watched, $gx, $gy];
            $this->hold->start();
        } elseif (! is_null($this->held) && ($type === Type::MOUSE_BUTTON_RELEASE
            || ($type === Type::MOUSE_MOVE && hypot($gx - $this->held[1], $gy - $this->held[2]) > QGuiApplication::styleHints()->startDragDistance()))) {
            $this->hold->stop();
            $this->held = null;
        }

        return false;
    }

    private function held(): void
    {
        if (! is_null($this->held)) {
            [$widget, $gx, $gy] = $this->held;
            $this->held = null;
            $this->at($widget, $gx, $gy);
        }
    }

    /** Whether $widget is the one under the global point: the first delivery of an event Qt then passes to parents. */
    private function underPointer(QWidget $widget, int $gx, int $gy): bool
    {
        return QApplication::widgetAt($gx, $gy) === $widget;
    }

    /**
     * A right click on $hit at the global point: mail for the innermost primitive holding it, in its
     * own coordinates; else for the window's bare content; nothing outside this window's content (the
     * menu bar, another window) or when the primitive or one of its groups is disabled. Then the
     * context menu of the primitive or its nearest group opens there. Whether a menu opened.
     */
    private function at(QWidget $hit, float $gx, float $gy): bool
    {
        $owners = [];
        foreach ($this->window->registry()->all() as $primitive) {
            if ($primitive instanceof QtNative) {
                $owners[spl_object_id($primitive->native())] = $primitive;
            }
        }

        for ($widget = $hit; ! is_null($widget); $widget = $widget->parentWidget()) {
            $primitive = $owners[spl_object_id($widget)] ?? null;
            if ($primitive instanceof TKPrimitive) {
                $menu = null;
                $owner = null;
                for ($at = $primitive; ! is_null($at); $at = $at->parent()) {
                    if (! $at->isEnabled()) {
                        return false;
                    }
                    if (is_null($menu) && ! is_null($at->contextMenu())) {
                        [$menu, $owner] = [$at->contextMenu(), $at];
                    }
                }
                $local = $widget->mapFromGlobal(new QPointF($gx, $gy));
                [$x, $y] = [$local->x(), $local->y()];
                $this->session->post(new RightClicked($this->window->name(), $primitive->path(), $primitive->uuid(), $x, $y));
                if (! $menu instanceof ContextMenu) {
                    return false;
                }
                $this->menu?->menu()->hide();
                // The owner keeps the user's toggle and radio choices: the next open shows them.
                $this->menu = new QtContextMenu($menu, $this->window->name(), $this->session, $owner);
                $this->menu->popupAt($gx, $gy);
                // The menu takes the pointer: a press the canvas owns ends here, with its release.
                $this->window->canvasPointer()->interrupt();

                return true;
            }
            if ($widget === $this->content) {
                $local = $this->content->mapFromGlobal(new QPointF($gx, $gy));
                [$x, $y] = [$local->x(), $local->y()];
                $this->session->post(new WindowRightClicked($this->window->name(), $x, $y));

                return false;
            }
            if ($widget === $this->native) {
                return false;
            }
        }

        return false;
    }
}
