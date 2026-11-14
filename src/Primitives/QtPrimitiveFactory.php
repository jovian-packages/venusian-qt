<?php

namespace Jovian\Toolkits\Qt\Primitives;

use DateTimeImmutable;
use Jovian\Toolkits\Qt\QtWindow;
use Surface\Contracts\Toolkits\Menus\ContextMenu;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\Primitives\PrimitiveFactory;
use Surface\Contracts\Toolkits\Primitives\TKButton;
use Surface\Contracts\Toolkits\Primitives\TKCanvas;
use Surface\Contracts\Toolkits\Primitives\TKCheckbox;
use Surface\Contracts\Toolkits\Primitives\TKColumn;
use Surface\Contracts\Toolkits\Primitives\TKComboBox;
use Surface\Contracts\Toolkits\Primitives\TKDatepicker;
use Surface\Contracts\Toolkits\Primitives\TKDropdown;
use Surface\Contracts\Toolkits\Primitives\TKFixed;
use Surface\Contracts\Toolkits\Primitives\TKGrid;
use Surface\Contracts\Toolkits\Primitives\TKImage;
use Surface\Contracts\Toolkits\Primitives\TKLabel;
use Surface\Contracts\Toolkits\Primitives\TKLink;
use Surface\Contracts\Toolkits\Primitives\TKMenuButton;
use Surface\Contracts\Toolkits\Primitives\TKPrimitiveGroup;
use Surface\Contracts\Toolkits\Primitives\TKProgressBar;
use Surface\Contracts\Toolkits\Primitives\TKRow;
use Surface\Contracts\Toolkits\Primitives\TKScrollBar;
use Surface\Contracts\Toolkits\Primitives\TKScrollView;
use Surface\Contracts\Toolkits\Primitives\TKSeparator;
use Surface\Contracts\Toolkits\Primitives\TKSlider;
use Surface\Contracts\Toolkits\Primitives\TKSpinner;
use Surface\Contracts\Toolkits\Primitives\TKSplitView;
use Surface\Contracts\Toolkits\Primitives\TKTabView;
use Surface\Contracts\Toolkits\Primitives\TKTable;
use Surface\Contracts\Toolkits\Primitives\TKSegmentedControl;
use Surface\Contracts\Toolkits\Primitives\TKCollectionView;
use Surface\Contracts\Toolkits\Primitives\TKListView;
use Surface\Contracts\Toolkits\Primitives\TKTextArea;
use Surface\Contracts\Toolkits\Primitives\TKTextInput;
use Surface\Contracts\Toolkits\Primitives\TKToggle;
use Surface\Contracts\Toolkits\Primitives\TKToggleButton;
use Surface\Contracts\Toolkits\Primitives\TKTree;
use Surface\Contracts\Toolkits\Primitives\TKVideo;
use Surface\Contracts\Toolkits\Primitives\TKWebView;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;

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

    public function mintLink(TKPrimitiveGroup $host, string $name, string $text, string $url): TKLink
    {
        return new QtLink($name, $this->window, $host, $this->placement($host), $text, $url);
    }

    public function mintButton(TKPrimitiveGroup $host, string $name, string $label): TKButton
    {
        return new QtButton($name, $this->window, $host, $this->placement($host), $label);
    }

    public function mintImage(TKPrimitiveGroup $host, string $name, ?string $file): TKImage
    {
        return new QtImage($name, $this->window, $host, $this->placement($host), $file);
    }

    public function mintCanvas(TKPrimitiveGroup $host, string $name): TKCanvas
    {
        return new QtCanvas($name, $this->window, $host, $this->placement($host));
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

    public function mintScrollBar(TKPrimitiveGroup $host, string $name, bool $vertical): TKScrollBar
    {
        return new QtScrollBar($name, $this->window, $host, $this->placement($host), $vertical);
    }

    public function mintComboBox(TKPrimitiveGroup $host, string $name, string $value, array $options): TKComboBox
    {
        return new QtComboBox($name, $this->window, $host, $this->placement($host), $value, $options);
    }

    public function mintMenuButton(TKPrimitiveGroup $host, string $name, string $label, ContextMenu $menu): TKMenuButton
    {
        return new QtMenuButton($name, $this->window, $host, $this->placement($host), $label, $menu);
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

    public function mintSegmentedControl(TKPrimitiveGroup $host, string $name, array $segments, int $selected): TKSegmentedControl
    {
        return new QtSegmentedControl($name, $this->window, $host, $this->placement($host), $segments, $selected);
    }

    public function mintCollectionView(TKPrimitiveGroup $host, string $name, array $items): TKCollectionView
    {
        return new QtCollectionView($name, $this->window, $host, $this->placement($host), $items);
    }

    public function mintListView(TKPrimitiveGroup $host, string $name, array $items): TKListView
    {
        return new QtListView($name, $this->window, $host, $this->placement($host), $items);
    }

    public function mintTree(TKPrimitiveGroup $host, string $name, array $columns, array $nodes): TKTree
    {
        return new QtTree($name, $this->window, $host, $this->placement($host), $columns, $nodes);
    }

    public function mintVideo(TKPrimitiveGroup $host, string $name, ?string $file): TKVideo
    {
        return new QtVideo($name, $this->window, $host, $this->placement($host), $file);
    }

    /**
     * @throws ToolkitRuntimeException When the loaded ext-qt has no QWebEngineView (a build from before its binding).
     */
    public function mintWebView(TKPrimitiveGroup $host, string $name): TKWebView
    {
        if (! class_exists('QWebEngineView')) {
            throw new ToolkitRuntimeException('TKWebView needs QWebEngineView, which this ext-qt build does not have.');
        }

        return new QtWebView($name, $this->window, $host, $this->placement($host));
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

    public function mintSplit(TKPrimitiveGroup $host, string $name, bool $horizontal): TKSplitView
    {
        return new QtSplitView($name, $this->window, $host, $this->placement($host), $horizontal);
    }

    public function mintTabs(TKPrimitiveGroup $host, string $name): TKTabView
    {
        return new QtTabView($name, $this->window, $host, $this->placement($host));
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

    protected function unavailable(string $kind): ToolkitRuntimeException
    {
        return new ToolkitRuntimeException("TK{$kind} is not available on qt.");
    }
}
