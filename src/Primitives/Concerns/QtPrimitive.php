<?php

namespace Jovian\Toolkits\Qt\Primitives\Concerns;

use Closure;
use Jovian\Toolkits\Qt\Bridge\QtSession;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtContainer;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Windows\QtWindow;
use QEvent\Type as EventType;
use QEventFilter;
use QObject;
use QSizePolicy\Policy;
use Qt\AlignmentFlag;
use Qt\WidgetAttribute;
use QtException;
use QWidget;
use Surface\Contracts\Windows\Mail\View\ViewResized;
use Surface\Contracts\Windows\Primitives\Align;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\WindowException;
use Surface\NutsAndBolts\Color;

/**
 * The hooks every Qt primitive shares. Styling is one style sheet per widget, scoped to
 * the widget's object name so it never cascades into children; size watching is an event
 * filter for Resize that posts through the session's latest-mail slot.
 */
trait QtPrimitive
{
    protected QWidget $native;

    /**
     * CSS property => value, written as the native's style sheet.
     * @var array<string, string>
     */
    protected array $style = [];

    /**
     * Sees the native's Resize events while its size is watched.
     */
    protected ?QEventFilter $size_filter = null;

    /**
     * True while a code-driven setter writes to the native, so the echo signal posts no mail.
     */
    protected bool $applying = false;

    public function native(): QWidget
    {
        return $this->native;
    }

    public function fills(): array
    {
        return [$this->fill_horizontal, $this->fill_vertical];
    }

    public function qtAlignment(): int
    {
        $horizontal = match ($this->align_horizontal) {
            Align::START => AlignmentFlag::LEFT->value,
            Align::CENTER => AlignmentFlag::H_CENTER->value,
            Align::END => AlignmentFlag::RIGHT->value,
            Align::FILL => 0,
        };
        $vertical = match ($this->align_vertical) {
            Align::START => AlignmentFlag::TOP->value,
            Align::CENTER => AlignmentFlag::V_CENTER->value,
            Align::END => AlignmentFlag::BOTTOM->value,
            Align::FILL => 0,
        };

        return $horizontal | $vertical;
    }

    /**
     * Take the concrete's widget: named for its style sheet's selector.
     * @template W of QWidget
     * @param W $native
     * @return W
     */
    protected function adoptNative(QWidget $native): QWidget
    {
        $this->native = $native;
        $native->setObjectName($this->styleId());

        return $native;
    }

    /**
     * The size policy the kind's widget has when Qt makes it: fill(false) on an axis returns to it.
     * @return array{Policy, Policy}
     */
    protected function naturalPolicy(): array
    {
        return [Policy::PREFERRED, Policy::PREFERRED];
    }

    protected function applyVisible(bool $visible): void
    {
        $this->native->setVisible($visible);
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->native->setEnabled($enabled);
    }

    protected function applyBackground(?Color $color): void
    {
        // A plain QWidget paints a style-sheet background only with WA_StyledBackground.
        $this->native->setAttribute(WidgetAttribute::STYLED_BACKGROUND, ! is_null($color));
        $this->setStyle('background-color', $color?->toCss());
    }

    /**
     * The policy lets the widget grow on a filled axis; the container's stretch factors
     * decide who takes the spare space (placeChild).
     */
    protected function applyFill(bool $horizontal, bool $vertical): void
    {
        [$natural_horizontal, $natural_vertical] = $this->naturalPolicy();
        $this->native->setSizePolicy(
            $horizontal ? Policy::EXPANDING : $natural_horizontal,
            $vertical ? Policy::EXPANDING : $natural_vertical,
        );
        $this->placeInParent();
    }

    protected function applyAlign(Align $horizontal, Align $vertical): void
    {
        $this->placeInParent();
    }

    /**
     * Have the container re-apply this primitive's alignment and fill.
     * @return void
     */
    protected function placeInParent(): void
    {
        if ($this->parent instanceof QtContainer) {
            $this->parent->placeChild($this);
        } elseif (is_null($this->parent)) {
            $this->qtWindow()->placeContent($this);
        }
    }

    /**
     * A push button's native bezel covers a style-sheet background (Qt Style Sheets reference,
     * QPushButton): a border makes Qt draw the button from the style sheet, so the colour shows.
     * @param Color|null $color
     * @return void
     */
    protected function applyButtonBackground(?Color $color): void
    {
        $this->setStyle('border', is_null($color) ? null : "1px solid {$color->toCss()}");
        $this->setStyle('padding', is_null($color) ? null : '4px 12px');
        $this->setStyle('background-color', $color?->toCss());
    }

    /**
     * Whether a native signal is the user's: not an echo of a code-driven write, not from a
     * removed primitive whose widget is still waiting for its deferred delete.
     * @return bool
     */
    protected function handling(): bool
    {
        return ! $this->applying && ! $this->removed;
    }

    /**
     * Button text as Qt shows it literally: QAbstractButton reads "&" as a mnemonic marker.
     * @param string $text
     * @return string
     */
    protected static function literal(string $text): string
    {
        return str_replace('&', '&&', $text);
    }

    protected function applyMinSize(int $width, int $height): void
    {
        $this->native->setMinimumSize($width, $height);
    }

    protected function applyWatchSize(bool $on): void
    {
        $key = "view.resized.{$this->window->name()}.{$this->path()}";

        if ($on) {
            $this->size_filter = new QEventFilter(function (QObject $watched, EventType|int $type) use ($key): bool {
                [$width, $height] = $this->native->size();
                $this->session()->postLatest($key, new ViewResized($this->window->name(), $this->path(), $this->uuid, $width, $height));

                return false;
            }, [EventType::RESIZE]);
            $this->native->installEventFilter($this->size_filter);

            return;
        }

        if (! is_null($this->size_filter)) {
            $filter = $this->size_filter;
            $this->whileNativeAlive(fn () => $this->native->removeEventFilter($filter));
            $this->size_filter = null;
        }
        $this->session()->forgetLatest($key);
    }

    /**
     * Out of its parent's layout (a widget leaving its parent leaves the parent's QLayout) and deleted on the next pump.
     * @return void
     */
    protected function destroyNative(): void
    {
        $this->whileNativeAlive(function (): void {
            $this->native->setParent(null);
            $this->native->deleteLater();
        });
    }

    /**
     * Text-bearing kinds: size, weight and family as style-sheet font properties.
     * @param FontSpec $font
     * @return void
     */
    protected function applyFont(FontSpec $font): void
    {
        $this->setStyle('font-size', "{$font->size}pt");
        $this->setStyle('font-weight', (string) $font->weight->toCssWeight());
        $this->setStyle('font-family', is_null($font->family) ? null : '"'.addcslashes($font->family, '"\\').'"');
    }

    /**
     * Text-bearing kinds: the text colour; null returns to the style's own.
     * @param Color|null $color
     * @return void
     */
    protected function applyTextColor(?Color $color): void
    {
        $this->setStyle('color', $color?->toCss());
    }

    protected function nativeSize(): array
    {
        return $this->native->size();
    }

    /**
     * Set or clear one style property and rewrite the native's style sheet.
     * @param string $property
     * @param string|null $value null removes the property
     * @return void
     */
    protected function setStyle(string $property, ?string $value): void
    {
        if (is_null($value)) {
            unset($this->style[$property]);
        } else {
            $this->style[$property] = $value;
        }

        $declarations = implode(' ', array_map(
            fn (string $name, string $value): string => "{$name}: {$value};",
            array_keys($this->style),
            $this->style,
        ));
        $this->native->setStyleSheet($declarations === '' ? '' : "#{$this->styleId()} { {$declarations} }");
    }

    /**
     * The object name the style sheet selects on: unique per primitive.
     * @return string
     */
    protected function styleId(): string
    {
        return 'tk_'.str_replace('-', '', $this->uuid);
    }

    /**
     * Run a write unless Qt already deleted the native. Only the close path that starts from
     * the window's destroyed() signal meets dead natives: Qt deleted the tree with the window.
     * @param Closure(): void $write
     * @return void
     */
    protected function whileNativeAlive(Closure $write): void
    {
        try {
            $write();
        } catch (QtException) {
            // Deleted with its window; nothing left to write.
        }
    }

    /**
     * Run a code-driven write with echo signals marked, so their handlers post nothing.
     * @param Closure(): void $write
     * @return void
     */
    protected function applying(Closure $write): void
    {
        $this->applying = true;
        try {
            $write();
        } finally {
            $this->applying = false;
        }
    }

    protected function qtWindow(): QtWindow
    {
        return $this->window instanceof QtWindow
            ? $this->window
            : throw new WindowException(static::class.' lives in a QtWindow, got '.get_class($this->window).'.');
    }

    protected function session(): QtSession
    {
        return $this->qtWindow()->session();
    }

    /**
     * The native widget of a child this driver minted.
     * @param TKPrimitive $child
     * @return QWidget
     * @throws WindowException When the child is not a Qt primitive.
     */
    protected static function nativeOf(TKPrimitive $child): QWidget
    {
        return $child instanceof QtNative
            ? $child->native()
            : throw new WindowException("'{$child->path()}' is not a Qt primitive.");
    }
}
