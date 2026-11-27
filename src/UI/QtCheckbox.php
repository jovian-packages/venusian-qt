<?php

namespace Jovian\Toolkits\Qt\UI;

use QObject;
use QCheckBox;
use WeakReference;
use QSizePolicy\Policy;
use Surface\Toolkits\UI\TKCheckbox;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\QtSheetMargin;

/**
 * A QCheckBox. Its toggled(bool) signal tells topside the new state; Qt also emits it for
 * setChecked() from code, which topside recognises as the state it already holds.
 */
class QtCheckbox extends TKCheckbox implements QtKeepsOwnMargin
{
    use QtNative;
    use QtSheetMargin;

    protected function bootstrapNative(): void
    {
        $box = new QCheckBox(self::plain($this->label()));
        $box->setChecked($this->isChecked());
        $this->adoptNative($box);

        $self = WeakReference::create($this);
        QObject::connect($box, 'toggled(bool)', static function (bool $on) use ($self): void {
            $self->get()?->toggled($on);
        });
    }

    protected function applyLabel(string $label): void
    {
        $this->boxNative()->setText(self::plain($label));
    }

    protected function applyChecked(bool $checked): void
    {
        $this->boxNative()->setChecked($checked);
    }

    /**
     * Qt's own policy for a check box.
     *
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::MINIMUM, Policy::FIXED];
    }

    private function boxNative(): QCheckBox
    {
        /** @var QCheckBox */
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
