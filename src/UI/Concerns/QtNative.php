<?php

namespace Jovian\Toolkits\Qt\UI\Concerns;

use QPoint;
use QPointF;
use QWidget;
use QEvent;
use QObject;
use QCursor;
use QMouseEvent;
use QEventFilter;
use QTimer;
use QEasingCurve;
use QCoreApplication;
use QPropertyAnimation;
use QEasingCurve\Type as EasingType;
use QGraphicsOpacityEffect;
use QSizePolicy\Policy;
use QEvent\Type as EventType;
use Qt\FocusPolicy;
use Qt\CursorShape;
use Qt\WidgetAttribute;
use RuntimeException;
use WeakReference;
use Surface\NutsAndBolts\Color;
use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\Cursor;
use Surface\Contracts\Toolkits\UI\Corners;
use Jovian\Toolkits\Qt\UI\QtRow;
use Jovian\Toolkits\Qt\UI\QtColumn;
use Jovian\Toolkits\Qt\UI\QtOverlay;
use Surface\Contracts\Toolkits\UI\TKTransition;

/**
 * What every Qt primitive does the same way with its widget.
 *
 * Styling: one style sheet per widget, scoped to its object name (so it does not reach the
 * widgets inside it), rebuilt from the declarations kept here: background, border, corner radius,
 * font, text colour, margin.
 *
 * Opacity and transitions: a QGraphicsOpacityEffect (Qt has no widget opacity of its own),
 * parented to the widget so PHP never frees it; transitions are QPropertyAnimations Qt runs.
 * A move transition animates the position alone, with no effect.
 *
 * Clicks on a clickable group: an event filter on its widget that only watches.
 */
trait QtNative
{
    /** Qt::LeftButton. */
    private const int LEFT_BUTTON = 1;

    /** QWIDGETSIZE_MAX: no maximum. */
    private const int NO_MAXIMUM = 16777215;

    protected ?QWidget $native = null;

    /** A clickable group's click filter on its widget. */
    private ?QEventFilter $click_filter = null;

    /** A left press reached the clickable group's widget, waiting for its release. */
    private bool $pressed = false;

    /** @var array<string, string> style sheet declarations by property */
    private array $declarations = [];

    /** What setBackground() set. */
    private ?Color $sheet_background = null;

    /** What setBorder() set, as a sheet value. */
    private ?string $border_line = null;

    private ?QGraphicsOpacityEffect $opacity_effect = null;

    /** @var list<QPropertyAnimation> running transition animations */
    private array $animations = [];

    /** Where the widget rests while a slide runs, to go back to when it stops. */
    private ?QPoint $rest = null;

    /** Which appearing move a deferred start belongs to; a stop or a newer change moves it on. */
    private int $move_token = 0;

    /**
     * The widget, for the container this primitive is in.
     *
     * @throws RuntimeException Once removed.
     */
    public function native(): QWidget
    {
        return $this->native ?? throw new RuntimeException("'{$this->path()}' is removed.");
    }

    /**
     * The size policy the widget has with no fill: Qt's own for its class.
     *
     * @return array{Policy, Policy} horizontal, vertical
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::PREFERRED, Policy::PREFERRED];
    }

    protected function adoptNative(QWidget $native): void
    {
        $this->native = $native;
        $native->setObjectName($this->styleId());
    }

    /**
     * Out of its parent, then deleted on Qt's next deferred-delete pass (the session's pump runs it).
     */
    protected function releaseNative(): void
    {
        $this->dropClickFilter();
        $this->stopTransition();
        $this->native->setParent(null);
        $this->native->deleteLater();
        $this->opacity_effect = null;
        $this->native = null;
    }

    /**
     * Only a group has this hook. A left press, then a left release inside the widget, clicks
     * the group. Qt hands the widget a press only when nothing under the pointer accepted it:
     * a button, a line edit or a label with links accepts its own, so it never clicks the
     * group; a plain label or a picture ignores it, so it reaches the group, in the group's
     * coordinates. The filter only watches: every event goes on as before.
     */
    protected function applyClickable(bool $on): void
    {
        if (! $on) {
            $this->dropClickFilter();

            return;
        }
        if (is_null($this->click_filter)) {
            $self = WeakReference::create($this);
            $this->click_filter = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
                $self->get()?->clickEvent($event);

                return false;
            });
            $this->native->installEventFilter($this->click_filter);
        }
    }

    private function clickEvent(QEvent $event): void
    {
        if (! $event instanceof QMouseEvent || $event->button() !== self::LEFT_BUTTON || is_null($this->native)) {
            return;
        }

        $type = $event->type();
        if ($type === EventType::MOUSE_BUTTON_PRESS) {
            $this->pressed = true;

            return;
        }
        if ($type !== EventType::MOUSE_BUTTON_RELEASE) {
            return;
        }

        $was = $this->pressed;
        $this->pressed = false;
        $at = $event->position();
        $size = $this->native->size();
        if ($was && $at->x() >= 0 && $at->y() >= 0 && $at->x() < $size->width() && $at->y() < $size->height()) {
            $this->groupClicked($at->x(), $at->y());
        }
    }

    private function dropClickFilter(): void
    {
        if (! is_null($this->click_filter)) {
            $this->native?->removeEventFilter($this->click_filter);
            $this->click_filter = null;
        }
        $this->pressed = false;
    }

    protected function applyVisible(bool $visible, ?TKTransition $transition, float $seconds): void
    {
        $this->stopTransition();

        if (is_null($transition) || $seconds <= 0.0) {
            $this->native->setVisible($visible);

            return;
        }

        $this->playTransition($visible, $transition, $seconds);
    }

    /**
     * The widget's own flag. Qt disables everything inside a disabled widget itself.
     */
    protected function applyEnabled(bool $enabled): void
    {
        $this->native->setEnabled($enabled);
    }

    /**
     * At full opacity with nothing animating, the effect goes: Qt draws an effect's widget
     * through an offscreen pass.
     */
    protected function applyOpacity(float $opacity): void
    {
        if ($opacity >= 1.0 && $this->animations === []) {
            $this->dropOpacityEffect();

            return;
        }

        $this->opacityEffect()->setOpacity($opacity);
    }

    /**
     * setFocus() ignores the focus policy, so a widget that takes no focus is refused here.
     */
    protected function nativeFocus(): bool
    {
        if ($this->native->focusPolicy() === FocusPolicy::NO_FOCUS) {
            return false;
        }

        $this->native->setFocus();

        return true;
    }

    /**
     * Whether this widget is its window's focus widget. Qt's own hasFocus() is false whenever
     * the window is inactive, though the window keeps this widget as its focus.
     */
    public function hasFocus(): bool
    {
        return ! is_null($this->native) && $this->native->window()->focusWidget() === $this->native;
    }

    /**
     * @return array{int, int}
     */
    public function size(): array
    {
        if (is_null($this->native)) {
            return [0, 0];
        }
        $size = $this->native->size();

        return [$size->width(), $size->height()];
    }

    protected function applyBackground(?Color $color): void
    {
        $this->sheet_background = $color;
        $this->restyleBox();
    }

    /**
     * A style sheet border. With no colour, the palette's mid tone, Qt's own for a frame
     * line, read by the sheet so it follows a palette change.
     */
    protected function applyBorder(int $width, ?Color $color): void
    {
        $this->border_line = $width > 0 ? "{$width}px solid ".($color?->toCss() ?? 'palette(mid)') : null;
        $this->restyleBox();
    }

    /**
     * Qt's own pointer shape for the cursor; null gives the widget back the one it inherits.
     */
    protected function applyCursor(?Cursor $cursor): void
    {
        if (is_null($cursor)) {
            $this->native->unsetCursor();

            return;
        }

        $this->native->setCursor(new QCursor(match ($cursor) {
            Cursor::ARROW => CursorShape::ARROW_CURSOR,
            Cursor::POINTER => CursorShape::POINTING_HAND_CURSOR,
            Cursor::TEXT => CursorShape::I_BEAM_CURSOR,
            Cursor::CROSSHAIR => CursorShape::CROSS_CURSOR,
            Cursor::MOVE => CursorShape::SIZE_ALL_CURSOR,
            Cursor::NOT_ALLOWED => CursorShape::FORBIDDEN_CURSOR,
            Cursor::GRAB => CursorShape::OPEN_HAND_CURSOR,
            Cursor::GRABBING => CursorShape::CLOSED_HAND_CURSOR,
            Cursor::RESIZE_HORIZONTAL => CursorShape::SIZE_HOR_CURSOR,
            Cursor::RESIZE_VERTICAL => CursorShape::SIZE_VER_CURSOR,
            Cursor::RESIZE_NWSE => CursorShape::SIZE_F_DIAG_CURSOR,
            Cursor::RESIZE_NESW => CursorShape::SIZE_B_DIAG_CURSOR,
        }));
    }

    protected function applyTooltip(?string $tooltip): void
    {
        $this->native->setToolTip($tooltip ?? '');
    }

    /**
     * The background and border declarations: what was set, else the widget's own
     * (ownBackground(), ownBorder()). A plain QWidget paints either only with STYLED_BACKGROUND,
     * on while one of them is set.
     */
    protected function restyleBox(): void
    {
        $this->native->setAttribute(WidgetAttribute::STYLED_BACKGROUND, ! is_null($this->sheet_background) || ! is_null($this->border_line));
        $this->declareAll([
            'background-color' => $this->sheet_background?->toCss() ?? $this->ownBackground(),
            'border' => $this->border_line ?? $this->ownBorder(),
        ]);
    }

    /**
     * The background the widget has in the sheet when none is set. None by default.
     */
    protected function ownBackground(): ?string
    {
        return null;
    }

    /**
     * The border the widget has in the sheet when none is set. None by default.
     */
    protected function ownBorder(): ?string
    {
        return null;
    }

    protected function sheetBackground(): ?Color
    {
        return $this->sheet_background;
    }

    /**
     * The border setBorder() asked for, as a sheet value; null with none.
     */
    protected function borderLine(): ?string
    {
        return $this->border_line;
    }

    /**
     * One radius is the same on every corner.
     */
    protected function applyCornerRadius(float $radius): void
    {
        $this->applyCorners(Corners::of($radius));
    }

    /**
     * Rounds the background (and a border), each corner its own: the four border-*-radius
     * properties, never the one border-radius. What is inside is not clipped: Qt Widgets
     * clip a child to its rectangle only.
     */
    protected function applyCorners(Corners $corners): void
    {
        $radius = static fn (float $r): ?string => $r > 0.0 ? "{$r}px" : null;

        $this->declareAll([
            'border-top-left-radius' => $radius($corners->topLeft),
            'border-top-right-radius' => $radius($corners->topRight),
            'border-bottom-right-radius' => $radius($corners->bottomRight),
            'border-bottom-left-radius' => $radius($corners->bottomLeft),
        ]);
    }

    /**
     * The size policy along each axis; the container sets the child's stretch in its layout
     * (childLayoutChanged), which runs first.
     */
    protected function applyFill(bool $horizontal, bool $vertical): void
    {
        $this->applySizePolicy();
        $this->holdCeiling();
    }

    /**
     * The container set the alignment in its layout first. Setting the size policy again
     * refreshes the size Qt cached before it: without it, a widget aligned back to FILL keeps
     * stretching across the layout (observed).
     */
    protected function applyAlign(Align $horizontal, Align $vertical): void
    {
        $this->applySizePolicy();
    }

    protected function applyMinSize(int $width, int $height): void
    {
        $this->native->setMinimumSize($width, $height);
    }

    /**
     * Qt's maximum width and height; 0 is none (QWIDGETSIZE_MAX).
     */
    protected function applyMaxSize(int $width, int $height): void
    {
        $this->native->setMaximumWidth($width > 0 ? $width : self::NO_MAXIMUM);
        $this->native->setMaximumHeight($height > 0 ? $height : self::NO_MAXIMUM);
        $this->holdCeiling();
    }

    /**
     * A layout gives an aligned widget no more than its size hint (QWidgetItem::setGeometry),
     * so a container that fills up to a maximum and is centred makes its own layout's
     * preferred size that maximum (holdCeiling() in a column, row or overlay). Nothing here.
     */
    protected function holdCeiling(): void {}

    /**
     * In an overlay that it does not size (it fills both ways), Qt ignores its size hint, so
     * it takes the overlay's size and never gives the overlay one.
     */
    protected function applySizePolicy(): void
    {
        [$horizontal, $vertical] = $this->fills();
        [$naturalH, $naturalV] = $this->naturalSizePolicy();

        if ($this->parent() instanceof QtOverlay && ! $this->parent()->sizedBy($this)) {
            $this->native->setSizePolicy(Policy::IGNORED, Policy::IGNORED);

            return;
        }

        $policyH = $horizontal ? Policy::EXPANDING : $naturalH;
        $policyV = $vertical ? Policy::EXPANDING : $naturalV;

        // In a homogeneous row (column), Qt ignores its width (height) hint: the layout shares
        // the room by the equal stretch factors alone.
        $parent = $this->parent();
        if ($parent instanceof QtRow && $parent->isHomogeneous()) {
            $policyH = Policy::IGNORED;
        } elseif ($parent instanceof QtColumn && $parent->isHomogeneous()) {
            $policyV = Policy::IGNORED;
        }

        $this->native->setSizePolicy($policyH, $policyV);
    }

    /**
     * Set the size policy again from what this primitive and its container hold: for a
     * container whose rules for its children changed (a column or row turned homogeneous).
     *
     * @internal
     */
    public function refreshSizePolicy(): void
    {
        if (! is_null($this->native)) {
            $this->applySizePolicy();
        }
    }

    /**
     * Set one style sheet declaration; null removes it.
     */
    protected function declare(string $property, ?string $value): void
    {
        $this->declareAll([$property => $value]);
    }

    /**
     * Set several declarations at once, one sheet; a null value removes its property.
     *
     * @param array<string, ?string> $values by property
     */
    protected function declareAll(array $values): void
    {
        foreach ($values as $property => $value) {
            if (is_null($value)) {
                unset($this->declarations[$property]);
            } else {
                $this->declarations[$property] = $value;
            }
        }

        $body = '';
        foreach ($this->declarations as $name => $set) {
            $body .= "{$name}: {$set}; ";
        }

        $sheet = ($body === '' ? '' : "#{$this->styleId()} { {$body}}").$this->subControlSheet();
        $this->native->setStyleSheet($sheet);
    }

    /**
     * Rules for the widget's sub-controls (a slider's groove and handle), written after its
     * own declarations each time the sheet is set. None by default.
     */
    protected function subControlSheet(): string
    {
        return '';
    }

    protected function styleId(): string
    {
        return 'tk_'.str_replace('-', '', $this->uuid());
    }

    /**
     * A fade of the opacity effect, and with a slide a move of the widget, both run by Qt.
     * Appearing: shown first, laid out at once (Qt queues a shown child's layout), then
     * animated from away to where the layout put it. Disappearing: animated away, then hidden
     * and put back where it rests.
     */
    private function playTransition(bool $visible, TKTransition $transition, float $seconds): void
    {
        [$dx, $dy] = $transition->offset();
        $duration = max(1, (int) round($seconds * 1000));

        if ($visible) {
            $this->native->setVisible(true);
            QCoreApplication::sendPostedEvents($this->native, EventType::META_CALL->value);
        }

        if ($transition->moves() && ! $visible) {
            $this->playMove(false, $transition, $duration);

            return;
        }

        if ($transition->moves()) {
            // The container hears of the change only after this returns, and the layout pass
            // it asks for puts the widget back where it rests, so a move started here was
            // painted in place before its first frame (measured: three paints at rest over
            // 47 ms). It is drawn at opacity 0 until the next turn of the loop, when every
            // pending layout is applied, and the move then starts from the edge.
            $this->opacityEffect()->setOpacity(0.0);
            $token = ++$this->move_token;
            $self = WeakReference::create($this);
            QTimer::singleShot(0, static function () use ($self, $token, $transition, $duration): void {
                $primitive = $self->get();
                if (is_null($primitive) || is_null($primitive->native) || $primitive->move_token !== $token) {
                    return;
                }
                QCoreApplication::sendPostedEvents(null, EventType::LAYOUT_REQUEST->value);
                $primitive->playMove(true, $transition, $duration);
            });

            return;
        }

        $effect = $this->opacityEffect();
        $rest = $this->native->pos();
        $away = new QPoint($rest->x() + $dx, $rest->y() + $dy);
        $this->rest = $rest;

        $fade = new QPropertyAnimation($effect, 'opacity', $this->native);
        $fade->setDuration($duration);
        $fade->setStartValue($visible ? 0.0 : $this->opacity());
        $fade->setEndValue($visible ? $this->opacity() : 0.0);
        $this->animations[] = $fade;

        if ($dx !== 0 || $dy !== 0) {
            $slide = new QPropertyAnimation($this->native, 'pos', $this->native);
            $slide->setDuration($duration);
            $slide->setStartValue($visible ? $away : $rest);
            $slide->setEndValue($visible ? $rest : $away);
            $this->animations[] = $slide;
        }

        $self = WeakReference::create($this);
        QObject::connect($fade, 'finished()', static function () use ($self, $visible): void {
            $primitive = $self->get();
            if (is_null($primitive) || is_null($primitive->native)) {
                return;
            }
            if (! $visible) {
                $primitive->native->setVisible(false);
            }
            $primitive->stopTransition();
        });

        foreach ($this->animations as $animation) {
            $animation->start();
        }
    }

    /**
     * A move: the widget travels its own width or height from the edge to where it rests
     * along topside's curve for it (a BEZIER_SPLINE QEasingCurve of that one cubic segment,
     * easeOutQuint in, easeInQuint out), or back out to it and is then hidden; no fade. Measured once the
     * layout has placed it (playTransition() shows it and sends its queued layout first).
     */
    private function playMove(bool $visible, TKTransition $transition, int $duration): void
    {
        [$ex, $ey] = $transition->edge();
        $rest = $this->native->pos();
        $size = $this->native->size();
        $away = new QPoint($rest->x() + $ex * $size->width(), $rest->y() + $ey * $size->height());
        $this->rest = $rest;

        $move = new QPropertyAnimation($this->native, 'pos', $this->native);
        $move->setDuration($duration);
        $move->setStartValue($visible ? $away : $rest);
        $move->setEndValue($visible ? $rest : $away);
        [$x1, $y1, $x2, $y2] = $transition->curve($visible);
        $curve = new QEasingCurve(EasingType::BEZIER_SPLINE);
        $curve->addCubicBezierSegment(new QPointF($x1, $y1), new QPointF($x2, $y2), new QPointF(1.0, 1.0));
        $move->setEasingCurve($curve);
        $this->animations[] = $move;

        $self = WeakReference::create($this);
        if ($visible) {
            // Drawn again once the animation has put it at the edge: a layout pass can still
            // land between this call and the first frame and put it back where it rests.
            QObject::connect($move, 'valueChanged(QVariant)', static function () use ($self): void {
                $primitive = $self->get();
                if (! is_null($primitive) && ! is_null($primitive->native) && $primitive->animations !== []) {
                    $primitive->applyOpacity($primitive->opacity());
                }
            });
        }
        QObject::connect($move, 'finished()', static function () use ($self, $visible): void {
            $primitive = $self->get();
            if (is_null($primitive) || is_null($primitive->native)) {
                return;
            }
            if (! $visible) {
                $primitive->native->setVisible(false);
            }
            $primitive->stopTransition();
        });

        $move->start();
    }

    /**
     * Back to rest: running animations stop (stop() emits no finished()), the widget goes back
     * where it rests, the effect back to the primitive's opacity.
     */
    private function stopTransition(): void
    {
        $this->move_token++;
        foreach ($this->animations as $animation) {
            $animation->stop();
        }
        $this->animations = [];

        if (is_null($this->native)) {
            return;
        }

        if (! is_null($this->rest)) {
            $this->native->move($this->rest->x(), $this->rest->y());
            $this->rest = null;
        }

        $this->applyOpacity($this->opacity());
    }

    private function opacityEffect(): QGraphicsOpacityEffect
    {
        if (is_null($this->opacity_effect)) {
            $this->opacity_effect = new QGraphicsOpacityEffect($this->native);
            $this->opacity_effect->setOpacity($this->opacity());
            $this->native->setGraphicsEffect($this->opacity_effect);
        }

        return $this->opacity_effect;
    }

    /**
     * setGraphicsEffect(null) deletes the effect.
     */
    private function dropOpacityEffect(): void
    {
        if (! is_null($this->opacity_effect)) {
            $this->native->setGraphicsEffect(null);
            $this->opacity_effect = null;
        }
    }
}
