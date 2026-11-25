<?php

namespace Jovian\Toolkits\Qt;

use RuntimeException;
use ReflectionException;
use Voyager\Contracts\Core\FrameworkCore;
use Surface\Toolkits\Drivers\ToolkitAdapterDriver;
use Jovian\Toolkits\Qt\Menus\QtMenuBar;
use Surface\Contracts\Toolkits\UI\TKRow;
use Surface\Contracts\Toolkits\UI\TKFixed;
use Surface\Contracts\Toolkits\UI\TKLabel;
use Surface\Contracts\Toolkits\UI\TKButton;
use Surface\Contracts\Toolkits\UI\TKColumn;
use Surface\Contracts\Toolkits\UI\TKToggle;
use Surface\Contracts\Toolkits\UI\TKCheckbox;
use Surface\Contracts\Toolkits\UI\TKDropdown;
use Surface\Contracts\Toolkits\UI\TKTextInput;
use Surface\Contracts\Toolkits\UI\TKLink;
use Surface\Contracts\Toolkits\UI\TKComboBox;
use Surface\Contracts\Toolkits\UI\TKDatepicker;
use Jovian\Toolkits\Qt\UI\QtTextInput;
use Jovian\Toolkits\Qt\UI\QtLink;
use Jovian\Toolkits\Qt\UI\QtComboBox;
use Jovian\Toolkits\Qt\UI\QtDatepicker;
use Jovian\Toolkits\Qt\UI\QtRow;
use Jovian\Toolkits\Qt\UI\QtFixed;
use Jovian\Toolkits\Qt\UI\QtLabel;
use Jovian\Toolkits\Qt\UI\QtButton;
use Jovian\Toolkits\Qt\UI\QtColumn;
use Jovian\Toolkits\Qt\UI\QtToggle;
use Jovian\Toolkits\Qt\UI\QtCheckbox;
use Jovian\Toolkits\Qt\UI\QtDropdown;
use Surface\Contracts\Toolkits\UI\TKTable;
use Jovian\Toolkits\Qt\UI\QtTable;
use Surface\Contracts\Toolkits\UI\TKListView;
use Jovian\Toolkits\Qt\UI\QtListView;
use Surface\Contracts\Toolkits\UI\TKCollectionView;
use Jovian\Toolkits\Qt\UI\QtCollectionView;
use Surface\Contracts\Toolkits\UI\TKTree;
use Jovian\Toolkits\Qt\UI\QtTree;
use Surface\Contracts\Toolkits\UI\TKTextArea;
use Jovian\Toolkits\Qt\UI\QtTextArea;
use Surface\Contracts\Toolkits\UI\TKScrollView;
use Jovian\Toolkits\Qt\UI\QtScrollView;
use Surface\Contracts\Toolkits\UI\TKTabView;
use Jovian\Toolkits\Qt\UI\QtTabView;
use Surface\Contracts\Toolkits\UI\TKSplitView;
use Jovian\Toolkits\Qt\UI\QtSplitView;
use Surface\Contracts\Toolkits\UI\TKGrid;
use Jovian\Toolkits\Qt\UI\QtGrid;
use Surface\Contracts\Toolkits\UI\TKOverlay;
use Jovian\Toolkits\Qt\UI\QtOverlay;
use Surface\Contracts\Toolkits\UI\TKSeparator;
use Jovian\Toolkits\Qt\UI\QtSeparator;
use Surface\Contracts\Toolkits\UI\TKProgressBar;
use Jovian\Toolkits\Qt\UI\QtProgressBar;
use Surface\Contracts\Toolkits\UI\TKSpinner;
use Jovian\Toolkits\Qt\UI\QtSpinner;
use Surface\Contracts\Toolkits\UI\TKSlider;
use Jovian\Toolkits\Qt\UI\QtSlider;
use Surface\Contracts\Toolkits\UI\TKToggleButton;
use Jovian\Toolkits\Qt\UI\QtToggleButton;
use Surface\Contracts\Toolkits\UI\TKSegmentedControl;
use Jovian\Toolkits\Qt\UI\QtSegmentedControl;
use Surface\Contracts\Toolkits\UI\TKImage;
use Jovian\Toolkits\Qt\UI\QtImage;

class QtAdapterDriver extends ToolkitAdapterDriver
{
    protected string $window = QtWindow::class;

    protected ?string $menu_bar = QtMenuBar::class;

    protected array $primitives = [
        TKColumn::class => QtColumn::class,
        TKRow::class => QtRow::class,
        TKFixed::class => QtFixed::class,
        TKLabel::class => QtLabel::class,
        TKButton::class => QtButton::class,
        TKCheckbox::class => QtCheckbox::class,
        TKToggle::class => QtToggle::class,
        TKDropdown::class => QtDropdown::class,
        TKTextInput::class => QtTextInput::class,
        TKLink::class => QtLink::class,
        TKComboBox::class => QtComboBox::class,
        TKDatepicker::class => QtDatepicker::class,
        TKSeparator::class => QtSeparator::class,
        TKProgressBar::class => QtProgressBar::class,
        TKSpinner::class => QtSpinner::class,
        TKSlider::class => QtSlider::class,
        TKToggleButton::class => QtToggleButton::class,
        TKSegmentedControl::class => QtSegmentedControl::class,
        TKImage::class => QtImage::class,
        TKScrollView::class => QtScrollView::class,
        TKTabView::class => QtTabView::class,
        TKSplitView::class => QtSplitView::class,
        TKGrid::class => QtGrid::class,
        TKOverlay::class => QtOverlay::class,
        TKTable::class => QtTable::class,
        TKListView::class => QtListView::class,
        TKCollectionView::class => QtCollectionView::class,
        TKTree::class => QtTree::class,
        TKTextArea::class => QtTextArea::class,
    ];

    public function __construct(
        FrameworkCore $app
    ) {
        if (!extension_loaded('qt')) {
            throw new RuntimeException("ext-qt missing. Install it with 'pie install php-io-extensions/qt'");
        }

        parent::__construct($app);
    }

    /**
     * @throws ReflectionException
     */
    protected function instanceSession(): void
    {
        $this->session ??= new QtSession(
            $this->app,
            (string) config('qt.application_name', 'Venusian'),
            $this->applicationId(),
        );
    }

    protected function connected(): void
    {
        // @todo - bootstrap additional Qt services
    }

    /**
     * @throws ReflectionException
     */
    protected function applicationId(): string
    {
        $id = config('qt.application_id');

        if (! is_string($id) || $id === '') {
            throw new RuntimeException("config/qt.php has no application_id; add 'id' => env('APP_ID', 'com.venusian.app') under name.");
        }

        return $id;
    }
}