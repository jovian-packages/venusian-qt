<?php

namespace Jovian\Toolkits\Qt\Windows;

use Jovian\Toolkits\Qt\Bridge\QtSession;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use QApplication;
use QCoreApplication;
use QEvent;
use QEvent\Type;
use QEventFilter;
use QMouseEvent;
use QObject;
use QPointF;
use QWidget;
use Surface\Contracts\Windows\Mail\View\GroupClicked;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use WeakReference;

/**
 * A toolkit window's clicks on clickable groups as mail. An application filter (Qt delivers a press
 * to the widget under the pointer, then passes it up while ignored) sees each primary press once, at
 * its first delivery, and its release once; a press and its release on the same group
 * (TKPrimitiveGroup::clickTarget) post GroupClicked. The filter never eats an event.
 */
final class QtGroupClicks
{
    private ?QEventFilter $filter;

    /** The uuid of the group the primary press landed on, until its release. */
    private ?string $pressed = null;

    public function __construct(
        private readonly QtWindow $window,
        private readonly QtSession $session,
    ) {
        $self = WeakReference::create($this);
        // Held weakly, as the window's own filters: a strong capture is a cycle through C++.
        $this->filter = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            $type = $event->type();
            if (($type === Type::MOUSE_BUTTON_PRESS || $type === Type::MOUSE_BUTTON_RELEASE) && $event instanceof QMouseEvent && $event->button() === 1) {
                $global = $event->globalPosition();
                $type === Type::MOUSE_BUTTON_PRESS
                    ? $self->get()?->pressed($watched, $global->x(), $global->y())
                    : $self->get()?->releaseAt($global->x(), $global->y());
            }

            return false;
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
        $this->pressed = null;
        if (! is_null($this->filter)) {
            QCoreApplication::instance()?->removeEventFilter($this->filter);
            $this->filter = null;
        }
    }

    /** A primary press at a global point: counted at its first delivery only, to the widget under it. */
    private function pressed(QObject $watched, float $gx, float $gy): void
    {
        $hit = QApplication::widgetAt((int) round($gx), (int) round($gy));
        if ($watched instanceof QWidget && $hit === $watched) {
            $this->pressAt($gx, $gy);
        }
    }

    /** A primary press at a global point. */
    public function pressAt(float $gx, float $gy): void
    {
        $this->pressed = $this->targetAt($gx, $gy)?->uuid();
    }

    /** Its release at a global point: GroupClicked when it is on the pressed group. */
    public function releaseAt(float $gx, float $gy): void
    {
        $pressed = $this->pressed;
        $this->pressed = null;
        $group = is_null($pressed) ? null : $this->targetAt($gx, $gy);
        if (is_null($group) || $group->uuid() !== $pressed || ! $group instanceof QtNative) {
            return;
        }
        $local = $group->native()->mapFromGlobal(new QPointF($gx, $gy));
        $this->session->post(new GroupClicked($this->window->name(), $group->path(), $pressed, $local->x(), $local->y()));
    }

    private function targetAt(float $gx, float $gy): ?TKPrimitiveGroup
    {
        $owners = [];
        foreach ($this->window->registry()->all() as $primitive) {
            if ($primitive instanceof QtNative) {
                $owners[spl_object_id($primitive->native())] = $primitive;
            }
        }
        for ($widget = QApplication::widgetAt((int) round($gx), (int) round($gy)); ! is_null($widget); $widget = $widget->parentWidget()) {
            $primitive = $owners[spl_object_id($widget)] ?? null;
            if ($primitive instanceof TKPrimitive) {
                return TKPrimitiveGroup::clickTarget($primitive);
            }
        }

        return null;
    }
}
