<?php

namespace Jovian\Toolkits\Qt\UI;

use QObject;
use QSlider;
use WeakReference;
use Qt\Orientation;
use QSizePolicy\Policy;
use Surface\Toolkits\UI\TKSlider;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QSlider, which counts in whole steps: the range is spread over STEPS of them.
 */
class QtSlider extends TKSlider
{
    use QtNative;

    private const int STEPS = 10000;

    protected function bootstrapNative(): void
    {
        $slider = new QSlider(Orientation::HORIZONTAL);
        $slider->setRange(0, self::STEPS);
        // Arrow keys move a hundredth of the range, page keys a tenth.
        $slider->setSingleStep(intdiv(self::STEPS, 100));
        $slider->setPageStep(intdiv(self::STEPS, 10));
        $slider->setMinimumSize(120, 0);
        $slider->setValue($this->step($this->value()));
        $this->adoptNative($slider);

        $self = WeakReference::create($this);
        QObject::connect($slider, 'valueChanged(int)', static function (int $step) use ($self): void {
            $primitive = $self->get();
            $primitive?->slid($primitive->min() + ($primitive->max() - $primitive->min()) * $step / self::STEPS);
        });
    }

    protected function applyValue(float $value): void
    {
        $this->sliderNative()->setValue($this->step($value));
    }

    /**
     * The steps stay; the value sits on another of them.
     */
    protected function applyRange(float $min, float $max, float $value): void
    {
        $this->applyValue($value);
    }

    /**
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::PREFERRED, Policy::FIXED];
    }

    private function step(float $value): int
    {
        return (int) round(($value - $this->min()) / ($this->max() - $this->min()) * self::STEPS);
    }

    private function sliderNative(): QSlider
    {
        /** @var QSlider */
        return $this->native();
    }
}
