<?php

namespace Jovian\Toolkits\Qt\Primitives;

use DateTimeImmutable;
use Jovian\Toolkits\Qt\Windows\QtWindow;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\PrimitiveFactory;
use Surface\Contracts\Windows\Primitives\TKButton;
use Surface\Contracts\Windows\Primitives\TKCheckbox;
use Surface\Contracts\Windows\Primitives\TKColumn;
use Surface\Contracts\Windows\Primitives\TKDatepicker;
use Surface\Contracts\Windows\Primitives\TKDropdown;
use Surface\Contracts\Windows\Primitives\TKFixed;
use Surface\Contracts\Windows\Primitives\TKGrid;
use Surface\Contracts\Windows\Primitives\TKImage;
use Surface\Contracts\Windows\Primitives\TKLabel;
use Surface\Contracts\Windows\Primitives\TKPrimitiveGroup;
use Surface\Contracts\Windows\Primitives\TKProgressBar;
use Surface\Contracts\Windows\Primitives\TKRow;
use Surface\Contracts\Windows\Primitives\TKScrollView;
use Surface\Contracts\Windows\Primitives\TKSeparator;
use Surface\Contracts\Windows\Primitives\TKSlider;
use Surface\Contracts\Windows\Primitives\TKSpinner;
use Surface\Contracts\Windows\Primitives\TKTable;
use Surface\Contracts\Windows\Primitives\TKTextArea;
use Surface\Contracts\Windows\Primitives\TKTextInput;
use Surface\Contracts\Windows\Primitives\TKToggle;
use Surface\Contracts\Windows\Primitives\TKToggleButton;
use Surface\Contracts\Windows\Primitives\TKVideo;
use Surface\Contracts\Windows\WindowException;

/**
 * One per window: builds each Qt concrete in the placement its host hands out.
 * Qt has no switch and no spinner widget. Mints declare the contract types: a concrete
 * return type would make PHP load concretes while it is still linking Surface's hierarchy.
 */
class QtPrimitiveFactory implements PrimitiveFactory
{
    public function __construct(
        protected readonly QtWindow $window,
    ) {}

    public function mintLabel(TKPrimitiveGroup $host, string $name, string $text): TKLabel
    {
        return new QtLabel($name, $this->window, $host, $this->placement($host), $text);
    }

    public function mintButton(TKPrimitiveGroup $host, string $name, string $label): TKButton
    {
        return new QtButton($name, $this->window, $host, $this->placement($host), $label);
    }

    public function mintImage(TKPrimitiveGroup $host, string $name, ?string $file): TKImage
    {
        return new QtImage($name, $this->window, $host, $this->placement($host), $file);
    }

    public function mintSeparator(TKPrimitiveGroup $host, string $name, bool $horizontal): TKSeparator
    {
        return new QtSeparator($name, $this->window, $host, $this->placement($host), $horizontal);
    }

    public function mintSpinner(TKPrimitiveGroup $host, string $name): TKSpinner
    {
        throw $this->unavailable('Spinner');
    }

    public function mintProgressBar(TKPrimitiveGroup $host, string $name, ?float $fraction): TKProgressBar
    {
        return new QtProgressBar($name, $this->window, $host, $this->placement($host), $fraction);
    }

    public function mintTextInput(TKPrimitiveGroup $host, string $name, string $value, ?string $placeholder, bool $secret): TKTextInput
    {
        return new QtTextInput($name, $this->window, $host, $this->placement($host), $value, $placeholder, $secret);
    }

    public function mintTextArea(TKPrimitiveGroup $host, string $name, string $value): TKTextArea
    {
        return new QtTextArea($name, $this->window, $host, $this->placement($host), $value);
    }

    public function mintCheckbox(TKPrimitiveGroup $host, string $name, string $label, bool $checked): TKCheckbox
    {
        return new QtCheckbox($name, $this->window, $host, $this->placement($host), $label, $checked);
    }

    public function mintToggle(TKPrimitiveGroup $host, string $name, bool $on): TKToggle
    {
        throw $this->unavailable('Toggle');
    }

    public function mintToggleButton(TKPrimitiveGroup $host, string $name, string $label, bool $pressed): TKToggleButton
    {
        return new QtToggleButton($name, $this->window, $host, $this->placement($host), $label, $pressed);
    }

    public function mintSlider(TKPrimitiveGroup $host, string $name, float $min, float $max, float $value): TKSlider
    {
        return new QtSlider($name, $this->window, $host, $this->placement($host), $min, $max, $value);
    }

    public function mintDropdown(TKPrimitiveGroup $host, string $name, array $options, int $selected): TKDropdown
    {
        return new QtDropdown($name, $this->window, $host, $this->placement($host), $options, $selected);
    }

    public function mintDatepicker(TKPrimitiveGroup $host, string $name, ?DateTimeImmutable $date): TKDatepicker
    {
        return new QtDatepicker($name, $this->window, $host, $this->placement($host), $date);
    }

    public function mintTable(TKPrimitiveGroup $host, string $name, array $columns, array $rows): TKTable
    {
        return new QtTable($name, $this->window, $host, $this->placement($host), $columns, $rows);
    }

    public function mintVideo(TKPrimitiveGroup $host, string $name, ?string $file): TKVideo
    {
        return new QtVideo($name, $this->window, $host, $this->placement($host), $file);
    }

    public function mintColumn(?TKPrimitiveGroup $host, string $name, int $spacing, int $padding): TKColumn
    {
        return new QtColumn($name, $this->window, $host, $this->placement($host), $spacing, $padding);
    }

    public function mintRow(?TKPrimitiveGroup $host, string $name, int $spacing, int $padding): TKRow
    {
        return new QtRow($name, $this->window, $host, $this->placement($host), $spacing, $padding);
    }

    public function mintGrid(?TKPrimitiveGroup $host, string $name, int $spacing, int $padding): TKGrid
    {
        return new QtGrid($name, $this->window, $host, $this->placement($host), $spacing, $padding);
    }

    public function mintFixed(?TKPrimitiveGroup $host, string $name): TKFixed
    {
        return new QtFixed($name, $this->window, $host, $this->placement($host));
    }

    public function mintScrollView(TKPrimitiveGroup $host, string $name): TKScrollView
    {
        return new QtScrollView($name, $this->window, $host, $this->placement($host));
    }

    /**
     * The slot the host hands out for this mint; the window's content takes the next one.
     * @param TKPrimitiveGroup|null $host
     * @return Placement
     */
    protected function placement(?TKPrimitiveGroup $host): Placement
    {
        return $host?->takePlacement() ?? Placement::next();
    }

    protected function unavailable(string $kind): WindowException
    {
        return new WindowException("TK{$kind} is not available on qt.");
    }
}
