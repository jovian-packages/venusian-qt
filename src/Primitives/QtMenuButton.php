<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPixmap;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use Jovian\Toolkits\Qt\Windows\QtContextMenu;
use QIcon;
use QPixmap;
use QSize;
use QSizePolicy\Policy;
use QToolButton;
use QToolButton\ToolButtonPopupMode;
use Qt\ToolButtonStyle;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Windows\Menus\ContextMenu as ContextMenuContract;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Menus\ContextMenu;
use Surface\Windows\Primitives\TKMenuButton;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A QToolButton that pops its menu at once: the QMenu QtContextMenu builds, whose actions post
 * MenuActivated. The button does not take the menu, so the primitive holds it. Text beside the
 * icon with an image, text only without; auto-raise for borderless.
 */
class QtMenuButton extends TKMenuButton implements QtNative
{
    use QtPixmap;
    use QtPrimitive;

    protected ?QtContextMenu $items = null;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $label, ContextMenuContract $menu)
    {
        parent::__construct($name, $window, $parent, $placement, $label, $menu);
        $button = $this->adoptNative(new QToolButton());
        $button->setPopupMode(ToolButtonPopupMode::INSTANT_POPUP);
        $button->setToolButtonStyle(ToolButtonStyle::TOOL_BUTTON_TEXT_ONLY);
        $this->applyLabel($label);
        $this->applyMenu($menu);
    }

    public function native(): QToolButton
    {
        return $this->native;
    }

    /** The menu the button pops; null for a menu that is not Surface's. */
    public function menuItems(): ?QtContextMenu
    {
        return $this->items;
    }

    protected function naturalPolicy(): array
    {
        return [Policy::MINIMUM, Policy::FIXED];
    }

    protected function applyLabel(string $label): void
    {
        $this->native()->setText(self::literal($label));
    }

    protected function applyImage(?Framebuffer $image, float $scale): void
    {
        if (is_null($image)) {
            $this->native()->setIcon(new QIcon(new QPixmap()));
            $this->native()->setToolButtonStyle(ToolButtonStyle::TOOL_BUTTON_TEXT_ONLY);

            return;
        }
        $this->native()->setIcon(new QIcon(self::pixmap($image, $scale)));
        $this->native()->setIconSize(new QSize((int) round($image->viewportWidth() / $scale), (int) round($image->viewportHeight() / $scale)));
        $this->native()->setToolButtonStyle(ToolButtonStyle::TOOL_BUTTON_TEXT_BESIDE_ICON);
    }

    protected function applyMenu(ContextMenuContract $menu): void
    {
        $this->items = $menu instanceof ContextMenu ? new QtContextMenu($menu, $this->window->name(), $this->session(), $this) : null;
        $this->native()->setMenu($this->items?->menu());
    }

    protected function applyBordered(bool $on): void
    {
        $this->native()->setAutoRaise(! $on);
    }
}
