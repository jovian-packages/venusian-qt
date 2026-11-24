<?php

namespace Jovian\Toolkits\Qt\UI;

use QObject;
use QLineEdit;
use WeakReference;
use QLineEdit\EchoMode;
use QSizePolicy\Policy;
use Surface\Toolkits\UI\TKTextInput;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\Qt\UI\Concerns\QtText;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QLineEdit. textChanged(QString) is typing, returnPressed() a submit.
 */
class QtTextInput extends TKTextInput
{
    use QtNative;
    use QtText;

    protected function bootstrapNative(): void
    {
        $edit = new QLineEdit($this->value());
        $edit->setPlaceholderText($this->placeholder() ?? '');
        if ($this->isSecret()) {
            $edit->setEchoMode(EchoMode::PASSWORD);
        }
        $this->adoptNative($edit);

        $self = WeakReference::create($this);
        QObject::connect($edit, 'textChanged(QString)', static function (string $text) use ($self): void {
            $self->get()?->typed($text);
        });
        QObject::connect($edit, 'returnPressed()', static function () use ($self): void {
            $input = $self->get();
            if (! is_null($input) && ! $input->isRemoved()) {
                $input->submitted($input->editNative()->text());
            }
        });
    }

    protected function applyValue(string $value): void
    {
        $this->editNative()->setText($value);
    }

    protected function applyPlaceholder(?string $placeholder): void
    {
        $this->editNative()->setPlaceholderText($placeholder ?? '');
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->editNative()->setAlignment(self::alignmentFlags($alignment));
    }

    /**
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::EXPANDING, Policy::FIXED];
    }

    private function editNative(): QLineEdit
    {
        /** @var QLineEdit */
        return $this->native();
    }
}
