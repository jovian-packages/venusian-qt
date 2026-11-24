<?php

namespace Jovian\Toolkits\Qt\UI;

use QObject;
use QComboBox;
use WeakReference;
use QSizePolicy\Policy;
use Surface\Toolkits\UI\TKDropdown;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QComboBox. Its currentIndexChanged(int) tells topside the chosen index. Qt also emits it
 * while its items are replaced (clear() reports -1, the first addItems() 0); those come
 * while $rebuilding and are not the user's, so they are not passed on.
 */
class QtDropdown extends TKDropdown
{
    use QtNative;

    private bool $rebuilding = false;

    protected function bootstrapNative(): void
    {
        $combo = new QComboBox();
        $this->adoptNative($combo);

        $self = WeakReference::create($this);
        QObject::connect($combo, 'currentIndexChanged(int)', static function (int $index) use ($self): void {
            $dropdown = $self->get();
            if (! is_null($dropdown) && ! $dropdown->rebuilding) {
                $dropdown->chosen($index);
            }
        });

        $this->applyOptions($this->options(), $this->selected());
    }

    protected function applyOptions(array $options, int $selected): void
    {
        $combo = $this->comboNative();

        $this->rebuilding = true;
        try {
            $combo->clear();
            $combo->addItems($options);
            if ($selected >= 0) {
                $combo->setCurrentIndex($selected);
            }
        } finally {
            $this->rebuilding = false;
        }
    }

    protected function applySelected(int $index): void
    {
        $this->comboNative()->setCurrentIndex($index);
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

    private function comboNative(): QComboBox
    {
        /** @var QComboBox */
        return $this->native();
    }
}
