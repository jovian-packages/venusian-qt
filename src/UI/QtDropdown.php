<?php

namespace Jovian\Toolkits\Qt\UI;

use QMenu;
use QAction;
use QWidget;
use QObject;
use QComboBox;
use QHBoxLayout;
use QToolButton;
use WeakReference;
use Qt\FocusPolicy;
use Qt\ToolButtonStyle;
use QSizePolicy\Policy;
use QComboBox\SizeAdjustPolicy;
use QToolButton\ToolButtonPopupMode;
use Surface\Toolkits\UI\TKDropdown;
use Jovian\Toolkits\Qt\UI\Support\QtIcon;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QComboBox, or, icon-only, a QToolButton that opens a QMenu of the options at once: Qt's own
 * icon button with a list, as a toolbar shows one. A QComboBox has no icon-only face; sized to
 * its icon (AdjustToMinimumContentsLengthWithIcon) it cuts the icon (measured: 768 of 1024 pixels
 * under the macOS style, none under Fusion). Both sit in a holder widget, one shown; the holder is
 * the primitive's native, and focus goes to the one shown.
 *
 * The combo box's currentIndexChanged(int) tells topside the chosen index. Qt also emits it
 * while its items are replaced (clear() reports -1, the first addItems() 0); those come while
 * $rebuilding and are not the user's, so they are not passed on. A menu action's triggered(bool)
 * tells it the action's index. Each option's icon is its combo box item's icon and its action's.
 */
class QtDropdown extends TKDropdown
{
    use QtNative {
        releaseNative as private releaseQtNative;
    }

    private bool $rebuilding = false;

    private ?QComboBox $combo = null;

    private ?QToolButton $button = null;

    private ?QMenu $menu = null;

    /** @var list<QAction> the menu's actions, one for each option */
    private array $actions = [];

    protected function bootstrapNative(): void
    {
        $holder = new QWidget();
        $layout = new QHBoxLayout($holder);
        $layout->setContentsMargins(0, 0, 0, 0);
        $layout->setSpacing(0);

        $this->combo = new QComboBox();
        // Qt's default sizes a combo box when first shown and never after: new options and icons
        // would be cut. A pop-up on AppKit and a drop-down on GTK follow their contents.
        $this->combo->setSizeAdjustPolicy(SizeAdjustPolicy::ADJUST_TO_CONTENTS);
        $this->button = new QToolButton();
        $this->button->setAutoRaise(true);
        $this->button->setToolButtonStyle(ToolButtonStyle::TOOL_BUTTON_ICON_ONLY);
        $this->button->setPopupMode(ToolButtonPopupMode::INSTANT_POPUP);
        $this->menu = new QMenu();
        $this->button->setMenu($this->menu);
        $this->button->setVisible(false);
        $layout->addWidget($this->combo);
        $layout->addWidget($this->button);
        $this->adoptNative($holder);

        $self = WeakReference::create($this);
        QObject::connect($this->combo, 'currentIndexChanged(int)', static function (int $index) use ($self): void {
            $dropdown = $self->get();
            if (! is_null($dropdown) && ! $dropdown->rebuilding) {
                $dropdown->chosen($index);
            }
        });

        $this->applyOptions($this->options(), $this->selected());
    }

    protected function applyOptions(array $options, int $selected): void
    {
        $this->rebuilding = true;
        try {
            $this->combo->clear();
            $this->combo->addItems($options);
            if ($selected >= 0) {
                $this->combo->setCurrentIndex($selected);
            }
        } finally {
            $this->rebuilding = false;
        }

        $this->menu->clear();
        $this->actions = [];
        $self = WeakReference::create($this);
        foreach ($options as $index => $option) {
            $action = $this->menu->addAction($option);
            QObject::connect($action, 'triggered(bool)', static function () use ($self, $index): void {
                $self->get()?->picked($index);
            });
            $this->actions[] = $action;
        }
        $this->showFace();
    }

    protected function applySelected(int $index): void
    {
        $this->combo->setCurrentIndex($index);
        $this->showFace();
    }

    protected function applyIcons(array $icons): void
    {
        foreach (array_keys($this->options()) as $index) {
            $icon = $this->iconOf($index);
            $shown = is_null($icon) ? QtIcon::of(null, null) : QtIcon::of($icon['file'], $icon['symbol']);
            $this->combo->setItemIcon($index, $shown);
            $this->actions[$index]->setIcon($shown);
        }
        $this->showFace();
    }

    protected function applyIconOnly(bool $on): void
    {
        $focused = $this->hasFocus();
        $this->combo->setVisible(! $on);
        $this->button->setVisible($on);
        if ($focused) {
            $this->nativeFocus();
        }
    }

    /** A menu action was chosen: the combo box takes it, so both faces agree, and tells topside. */
    private function picked(int $index): void
    {
        $this->combo->setCurrentIndex($index);
        $this->showFace();
    }

    /** The tool button shows the selected option's icon. */
    private function showFace(): void
    {
        $icon = $this->selected() < 0 ? null : $this->iconOf($this->selected());
        $this->button->setIcon(is_null($icon) ? QtIcon::of(null, null) : QtIcon::of($icon['file'], $icon['symbol']));
    }

    /** The face shown. */
    private function face(): QWidget
    {
        return $this->isIconOnly() ? $this->button : $this->combo;
    }

    protected function nativeFocus(): bool
    {
        $face = $this->face();
        if ($face->focusPolicy() === FocusPolicy::NO_FOCUS) {
            return false;
        }
        $face->setFocus();

        return true;
    }

    public function hasFocus(): bool
    {
        return ! is_null($this->native) && ! is_null($this->combo) && $this->native->window()->focusWidget() === $this->face();
    }

    /**
     * Qt's own policy for a combo box.
     *
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::PREFERRED, Policy::FIXED];
    }

    protected function releaseNative(): void
    {
        $this->combo = null;
        $this->button = null;
        $this->menu = null;
        $this->actions = [];
        $this->releaseQtNative();
    }
}
