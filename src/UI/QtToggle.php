<?php

namespace Jovian\Toolkits\Qt\UI;

use QObject;
use QSlider;
use WeakReference;
use Qt\Orientation;
use QSizePolicy\Policy;
use Surface\Toolkits\UI\TKToggle;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * Qt has no switch: this is a horizontal QSlider with two positions, styled as one. The
 * groove is a pill, tinted while on; the handle is a round knob at the left (off) or right
 * (on). A click on the groove moves it a page, which with two positions is the other end;
 * dragging and the arrow keys work too.
 *
 * valueChanged(int) tells topside the new state; Qt also emits it for setValue() from code,
 * which topside recognises as the state it already holds.
 */
class QtToggle extends TKToggle
{
    use QtNative;

    private const int WIDTH = 40;

    private const int HEIGHT = 22;

    protected function bootstrapNative(): void
    {
        $slider = new QSlider(Orientation::HORIZONTAL);
        $slider->setRange(0, 1);
        $slider->setPageStep(1);
        $slider->setSingleStep(1);
        $slider->setValue($this->isOn() ? 1 : 0);
        $slider->setFixedSize(self::WIDTH, self::HEIGHT);
        $this->adoptNative($slider);
        $this->declare('min-height', self::HEIGHT.'px');

        $self = WeakReference::create($this);
        QObject::connect($slider, 'valueChanged(int)', static function (int $value) use ($self): void {
            $self->get()?->toggled($value === 1);
        });
    }

    protected function applyOn(bool $on): void
    {
        $this->sliderNative()->setValue($on ? 1 : 0);
    }

    /**
     * A switch keeps its size.
     *
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::FIXED, Policy::FIXED];
    }

    /**
     * The pill and the knob. sub-page is the groove left of the handle: all of it when on.
     */
    protected function subControlSheet(): string
    {
        $id = "#{$this->styleId()}";
        $radius = intdiv(self::HEIGHT, 2);
        $knob = self::HEIGHT - 4;

        return " {$id}::groove:horizontal { height: ".self::HEIGHT."px; border-radius: {$radius}px; background: rgba(128, 128, 128, 0.45); }"
            ." {$id}::sub-page:horizontal { border-radius: {$radius}px; background: palette(highlight); }"
            ." {$id}::add-page:horizontal { border-radius: {$radius}px; background: rgba(128, 128, 128, 0.45); }"
            ." {$id}::handle:horizontal { width: {$knob}px; height: {$knob}px; margin: 2px; border-radius: ".intdiv($knob, 2).'px; background: white; }'
            ." {$id}::sub-page:horizontal:disabled, {$id}::add-page:horizontal:disabled { background: rgba(128, 128, 128, 0.25); }";
    }

    private function sliderNative(): QSlider
    {
        /** @var QSlider */
        return $this->native();
    }
}
