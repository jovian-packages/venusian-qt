<?php

namespace Jovian\Toolkits\Qt\UI;

use QIcon;
use QObject;
use QPushButton;
use WeakReference;
use QSizePolicy\Policy;
use Surface\Contracts\Toolkits\UI\Insets;
use Surface\NutsAndBolts\Color;
use Surface\Toolkits\UI\TKButton;
use Surface\Contracts\Toolkits\UI\ButtonRole;
use Surface\Contracts\Toolkits\UI\ButtonStyle;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\Qt\UI\Support\QtIcon;
use Jovian\Toolkits\Qt\UI\Concerns\QtText;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QPushButton, with Qt's own focus policy for it. clicked() posts ButtonClicked.
 *
 * Borderless and link styles: a flat button whose sheet draws no border and no fill; a link's
 * label is in the palette's link colour. A DEFAULT role is Qt's default button (setDefault,
 * setAutoDefault). Qt has no cancel button outside a dialog, so CANCEL shows nothing.
 */
class QtButton extends TKButton
{
    use QtNative {
        restyleBox as private restyleSheetBox;
    }
    use QtText;

    private ?Color $text_color = null;

    /** The padding set: null for the one this adapter gives a framed button. */
    private ?Insets $padding = null;

    /** The icon last given to Qt: null for none. */
    private ?QIcon $shown_icon = null;

    protected function bootstrapNative(): void
    {
        $button = new QPushButton(self::plain($this->label()));
        $this->adoptNative($button);

        $self = WeakReference::create($this);
        QObject::connect($button, 'clicked()', static function () use ($self): void {
            $self->get()?->clicked();
        });
    }

    protected function applyLabel(string $label): void
    {
        $this->buttonNative()->setText(self::plain($label));
    }

    /**
     * Qt's own policy for a push button.
     *
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::MINIMUM, Policy::FIXED];
    }

    protected function applyStyle(ButtonStyle $style): void
    {
        // Qt has no help button: HELP is a framed button, its label shown.
        $this->buttonNative()->setFlat(! $style->framed());
        $this->restyleBox();
    }

    protected function applyTextColor(?Color $color): void
    {
        $this->text_color = $color;
        $this->restyleBox();
    }

    /**
     * QPushButton takes text-align in its style sheet.
     */
    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->declare('text-align', match ($alignment) {
            TextAlignment::LEFT => 'left',
            TextAlignment::CENTER => 'center',
            TextAlignment::RIGHT => 'right',
        });
    }

    /**
     * A style sheet padding per side, in px. None gives back the adapter's own.
     */
    protected function applyPadding(Insets $padding): void
    {
        $this->padding = $padding->isZero() ? null : $padding;
        $this->restyleBox();
    }

    /**
     * Qt puts a push button's icon before its label. A file Qt cannot read shows none.
     */
    protected function applyIcon(?string $file, ?string $symbol): void
    {
        $icon = QtIcon::of($file, $symbol);

        $this->buttonNative()->setIcon($icon);
        $this->shown_icon = $icon->isNull() ? null : $icon;
    }

    protected function applyRole(?ButtonRole $role): void
    {
        $default = $role === ButtonRole::DEFAULT;
        $this->buttonNative()->setAutoDefault($default);
        $this->buttonNative()->setDefault($default);
    }

    /**
     * Besides the background and border: the padding a sheet-drawn frame needs, and the
     * label's colour.
     */
    protected function restyleBox(): void
    {
        $this->restyleSheetBox();

        $framed = $this->style()->framed() && (! is_null($this->sheetBackground()) || ! is_null($this->borderLine()));
        $this->declareAll([
            'padding' => is_null($this->padding)
                ? ($framed ? '4px 12px' : null)
                : "{$this->padding->top}px {$this->padding->right}px {$this->padding->bottom}px {$this->padding->left}px",
            'color' => $this->text_color?->toCss() ?? ($this->style() === ButtonStyle::LINK ? 'palette(link)' : null),
        ]);
    }

    /**
     * A QPushButton's native bezel covers a style sheet background unless the sheet sets a
     * border: a background brings a border of its colour. Borderless and link styles draw none.
     */
    protected function ownBorder(): ?string
    {
        if (! $this->style()->framed()) {
            return 'none';
        }

        return is_null($this->sheetBackground()) ? null : "1px solid {$this->sheetBackground()->toCss()}";
    }

    private function buttonNative(): QPushButton
    {
        /** @var QPushButton */
        return $this->native();
    }

    /**
     * QAbstractButton reads & as a mnemonic marker; doubled, it shows as itself.
     */
    private static function plain(string $label): string
    {
        return str_replace('&', '&&', $label);
    }
}
