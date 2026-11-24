<?php

namespace Jovian\Toolkits\Qt\UI;

use QObject;
use QPushButton;
use WeakReference;
use QSizePolicy\Policy;
use Surface\NutsAndBolts\Color;
use Surface\Toolkits\UI\TKButton;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QPushButton, with Qt's own focus policy for it. clicked() posts ButtonClicked.
 */
class QtButton extends TKButton
{
    use QtNative {
        applyBackground as private sheetBackground;
    }

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
        /** @var QPushButton $button */
        $button = $this->native();
        $button->setText(self::plain($label));
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

    /**
     * A QPushButton's native bezel covers a style sheet background unless the sheet sets a border.
     */
    protected function applyBackground(?Color $color): void
    {
        $this->sheetBackground($color);
        $this->declare('border', is_null($color) ? null : "1px solid {$color->toCss()}");
        $this->declare('padding', is_null($color) ? null : '4px 12px');
    }

    /**
     * QAbstractButton reads & as a mnemonic marker; doubled, it shows as itself.
     */
    private static function plain(string $label): string
    {
        return str_replace('&', '&&', $label);
    }
}
