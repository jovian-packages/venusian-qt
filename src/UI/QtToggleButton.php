<?php

namespace Jovian\Toolkits\Qt\UI;

use QObject;
use QPushButton;
use WeakReference;
use QSizePolicy\Policy;
use Surface\Toolkits\UI\TKToggleButton;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A checkable QPushButton. toggled(bool) comes for setChecked() from code too, which topside
 * recognises as the state it already holds.
 */
class QtToggleButton extends TKToggleButton
{
    use QtNative;

    protected function bootstrapNative(): void
    {
        $button = new QPushButton(self::plain($this->label()));
        $button->setCheckable(true);
        $button->setChecked($this->isPressed());
        $this->adoptNative($button);

        $self = WeakReference::create($this);
        QObject::connect($button, 'toggled(bool)', static function (bool $on) use ($self): void {
            $self->get()?->toggled($on);
        });
    }

    protected function applyLabel(string $label): void
    {
        $this->buttonNative()->setText(self::plain($label));
    }

    protected function applyPressed(bool $pressed): void
    {
        $this->buttonNative()->setChecked($pressed);
    }

    /**
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::MINIMUM, Policy::FIXED];
    }

    /**
     * QAbstractButton reads & as a mnemonic marker; doubled, it shows as itself.
     */
    private static function plain(string $label): string
    {
        return str_replace('&', '&&', $label);
    }

    private function buttonNative(): QPushButton
    {
        /** @var QPushButton */
        return $this->native();
    }
}
