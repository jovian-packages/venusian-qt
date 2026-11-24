<?php

namespace Jovian\Toolkits\Qt\UI\Concerns;

use QPoint;
use QWidget;
use QObject;
use QCoreApplication;
use QPropertyAnimation;
use QGraphicsOpacityEffect;
use QSizePolicy\Policy;
use QEvent\Type as EventType;
use Qt\FocusPolicy;
use Qt\WidgetAttribute;
use RuntimeException;
use WeakReference;
use Surface\NutsAndBolts\Color;
use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\TKTransition;

/**
 * What every Qt primitive does the same way with its widget.
 *
 * Styling: one style sheet per widget, scoped to its object name (so it does not reach the
 * widgets inside it), rebuilt from the declarations kept here: background, corner radius,
 * font, text colour.
 *
 * Opacity and transitions: a QGraphicsOpacityEffect (Qt has no widget opacity of its own),
 * parented to the widget so PHP never frees it; transitions are QPropertyAnimations Qt runs.
 */
trait QtNative
{
    protected ?QWidget $native = null;

    /** @var array<string, string> style sheet declarations by property */
    private array $declarations = [];

    private ?QGraphicsOpacityEffect $opacity_effect = null;

    /** @var list<QPropertyAnimation> running transition animations */
    private array $animations = [];

    /** Where the widget rests while a slide runs, to go back to when it stops. */
    private ?QPoint $rest = null;

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
        $this->stopTransition();
        $this->native->setParent(null);
        $this->native->deleteLater();
        $this->opacity_effect = null;
        $this->native = null;
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

    /**
     * A plain QWidget paints a style sheet background only with STYLED_BACKGROUND.
     */
    protected function applyBackground(?Color $color): void
    {
        $this->native->setAttribute(WidgetAttribute::STYLED_BACKGROUND, ! is_null($color));
        $this->declare('background-color', $color?->toCss());
    }

    /**
     * Rounds the background; what is inside is not clipped.
     */
    protected function applyCornerRadius(float $radius): void
    {
        $this->declare('border-radius', $radius > 0.0 ? "{$radius}px" : null);
    }

    /**
     * The size policy along each axis; the container sets the child's stretch in its layout
     * (childLayoutChanged), which runs first.
     */
    protected function applyFill(bool $horizontal, bool $vertical): void
    {
        $this->applySizePolicy();
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

    protected function applySizePolicy(): void
    {
        [$horizontal, $vertical] = $this->fills();
        [$naturalH, $naturalV] = $this->naturalSizePolicy();

        $this->native->setSizePolicy($horizontal ? Policy::EXPANDING : $naturalH, $vertical ? Policy::EXPANDING : $naturalV);
    }

    /**
     * Set one style sheet declaration; null removes it.
     */
    protected function declare(string $property, ?string $value): void
    {
        if (is_null($value)) {
            unset($this->declarations[$property]);
        } else {
            $this->declarations[$property] = $value;
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
        $effect = $this->opacityEffect();

        if ($visible) {
            $this->native->setVisible(true);
            QCoreApplication::sendPostedEvents($this->native, EventType::META_CALL->value);
        }

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
     * Back to rest: running animations stop (stop() emits no finished()), the widget goes back
     * where it rests, the effect back to the primitive's opacity.
     */
    private function stopTransition(): void
    {
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
