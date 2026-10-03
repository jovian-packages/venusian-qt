<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QObject;
use QSizePolicy\Policy;
use QSlider;
use Qt\Orientation;
use Surface\Contracts\Windows\Mail\View\ValueChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKSlider;

/**
 * QSlider is integer-valued: the float range [min, max] maps onto 0..STEPS.
 */
class QtSlider extends TKSlider implements QtNative
{
    use QtPrimitive;

    public const int STEPS = 10000;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, float $min, float $max, float $value)
    {
        parent::__construct($name, $window, $parent, $placement, $min, $max, $value);
        // ext-qt keeps Qt's default orientation (vertical); Surface's slider is horizontal.
        $slider = $this->adoptNative(new QSlider(Orientation::HORIZONTAL));
        $slider->setRange(0, self::STEPS);
        // Arrow keys move a hundredth of the range, page keys a tenth.
        $slider->setSingleStep(intdiv(self::STEPS, 100));
        $slider->setPageStep(intdiv(self::STEPS, 10));
        $slider->setValue($this->step($this->value));

        QObject::connect($slider, 'valueChanged(int)', function (int $step): void {
            if (! $this->handling()) {
                return;
            }
            $value = $this->min + ($this->max - $this->min) * $step / self::STEPS;
            $this->nativeValueChanged($value);
            $this->session()->post(new ValueChanged($this->window->name(), $this->path(), $this->uuid, $value));
        });
    }

    public function native(): QSlider
    {
        return $this->native;
    }

    protected function naturalPolicy(): array
    {
        return [Policy::EXPANDING, Policy::FIXED];
    }

    protected function applyValue(float $value): void
    {
        $this->applying(fn () => $this->native()->setValue($this->step($value)));
    }

    /**
     * The same value sits at a different step in the new range. TKSlider follows with
     * applyValue() when the value had to be clamped into it.
     */
    protected function applyRange(float $min, float $max): void
    {
        $this->applyValue($this->value);
    }

    protected function step(float $value): int
    {
        $fraction = ($value - $this->min) / ($this->max - $this->min);

        return (int) round(max(0.0, min(1.0, $fraction)) * self::STEPS);
    }
}
