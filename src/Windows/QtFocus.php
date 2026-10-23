<?php

namespace Jovian\Toolkits\Qt\Windows;

use Closure;
use QApplication;
use QCoreApplication;
use QEvent;
use QEvent\Type;
use QEventFilter;
use QObject;
use QWidget;
use Surface\Contracts\Windows\Mail\View\FocusGained;
use Surface\Contracts\Windows\Mail\View\FocusLost;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use WeakReference;

/**
 * A toolkit window's keyboard focus as primitives, from the FocusIn events an application filter
 * sees for widgets of this window. The holder is the window's own: it stays while the window is
 * inactive (Qt sends a FocusIn to the same widget again when the window activates, which posts
 * nothing), so a window that loses activation posts no FocusLost. A move made from code, inside
 * quietly(), sets the holder to its target at once, so the FocusIn Qt sends for it (now, or when
 * the window activates) posts nothing. The first focus Qt gives a window it shows is the
 * toolkit's, after present(), and posts nothing either.
 */
final class QtFocus
{
    /** @var WeakReference<QtWindow> the window holds this */
    private readonly WeakReference $window;

    /** @var WeakReference<TKPrimitive>|null weak, so a removed primitive is freed */
    private ?WeakReference $last = null;

    private bool $quiet = false;

    /** Set by present() while the window holds no focus: the next FocusIn is Qt's own. */
    private bool $awaiting = false;

    private ?QEventFilter $filter;

    public function __construct(QtWindow $window)
    {
        $this->window = WeakReference::create($window);
        $self = WeakReference::create($this);
        $this->filter = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            if ($event->type() === Type::FOCUS_IN && $watched instanceof QWidget) {
                $self->get()?->focusedIn($watched);
            }

            return false;
        });
        QCoreApplication::instance()?->installEventFilter($this->filter);
    }

    /** The primitive holding the window's keyboard focus; null for none, or a widget Surface did not make. */
    public function holder(): ?TKPrimitive
    {
        $holder = $this->last?->get();

        return is_null($holder) || $holder->isRemoved() ? null : $holder;
    }

    /**
     * Move focus from code to $target: no mail, and $target is the holder the user's next move
     * leaves. A null $target is wherever Qt moved focus during $move (a hidden or disabled focus
     * widget passes it on to the next one).
     * @param Closure(): void $move
     */
    public function quietly(?TKPrimitive $target, Closure $move): void
    {
        $this->quiet = true;
        try {
            $move();
        } finally {
            $this->quiet = false;
            $this->awaiting = false;
            $window = $this->window->get();
            $focus = QApplication::focusWidget();
            $target ??= $window instanceof QtWindow && $window->isOpen() && $focus instanceof QWidget && $focus->window() === $window->native()
                ? $this->primitiveOf($window, $focus)
                : null;
            $this->last = is_null($target) ? null : WeakReference::create($target);
        }
    }

    /**
     * Whether the holder is $primitive or inside it. Read from the holder as recorded, removed or
     * not: remove() marks the primitive removed before its native goes.
     */
    public function within(TKPrimitive $primitive): bool
    {
        for ($at = $this->last?->get(); ! is_null($at); $at = $at->parent()) {
            if ($at === $primitive) {
                return true;
            }
        }

        return false;
    }

    /**
     * present() of a window that holds no focus and is about to be shown or activated: Qt's first
     * focus for it posts nothing. An active, shown window gets no such focus, so nothing is armed.
     */
    public function awaitFirstFocus(QWidget $window): void
    {
        if (is_null($this->holder()) && (! $window->isVisible() || ! $window->isActiveWindow())) {
            $this->awaiting = true;
        }
    }

    /** The window closed: stop watching the application. */
    public function detach(): void
    {
        if (! is_null($this->filter)) {
            QCoreApplication::instance()?->removeEventFilter($this->filter);
            $this->filter = null;
        }
        $this->last = null;
    }

    private function focusedIn(QWidget $widget): void
    {
        $window = $this->window->get();
        if ($this->quiet || ! $window instanceof QtWindow || ! $window->isOpen() || $widget->window() !== $window->native()) {
            return;
        }
        $now = $this->primitiveOf($window, $widget);
        if ($this->awaiting) {
            $this->awaiting = false;
            $this->last = is_null($now) ? null : WeakReference::create($now);

            return;
        }
        $last = $this->holder();
        if ($now === $last) {
            return;
        }
        $this->last = is_null($now) ? null : WeakReference::create($now);
        if (! is_null($last)) {
            $window->session()->post(new FocusLost($window->name(), $last->path(), $last->uuid()));
        }
        if (! is_null($now)) {
            $window->session()->post(new FocusGained($window->name(), $now->path(), $now->uuid()));
        }
    }

    /** The primitive whose native is $widget or its nearest ancestor (a combo's inner line edit is the combo's). */
    private function primitiveOf(QtWindow $window, QWidget $widget): ?TKPrimitive
    {
        $owners = [];
        foreach ($window->registry()->all() as $primitive) {
            if (method_exists($primitive, 'native') && $primitive->native() instanceof QWidget) {
                $owners[spl_object_id($primitive->native())] = $primitive;
            }
        }
        for ($at = $widget; $at instanceof QWidget; $at = $at->parentWidget()) {
            if (isset($owners[spl_object_id($at)])) {
                return $owners[spl_object_id($at)];
            }
        }

        return null;
    }
}
