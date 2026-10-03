<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QLineEdit;
use QLineEdit\EchoMode;
use QObject;
use QSizePolicy\Policy;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTextInput;

class QtTextInput extends TKTextInput implements QtNative
{
    use QtPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $value, ?string $placeholder, bool $secret)
    {
        parent::__construct($name, $window, $parent, $placement, $value, $placeholder, $secret);
        $edit = $this->adoptNative(new QLineEdit($value));
        $edit->setPlaceholderText($placeholder ?? '');
        if ($secret) {
            $edit->setEchoMode(EchoMode::PASSWORD);
        }

        QObject::connect($edit, 'textChanged(QString)', function (string $text): void {
            if (! $this->handling()) {
                return;
            }
            $this->nativeValueChanged($text);
            $this->session()->post(new TextChanged($this->window->name(), $this->path(), $this->uuid, $text));
        });
        QObject::connect($edit, 'returnPressed()', function (): void {
            if ($this->handling()) {
                $this->session()->post(new TextSubmitted($this->window->name(), $this->path(), $this->uuid, $this->value));
            }
        });
    }

    public function native(): QLineEdit
    {
        return $this->native;
    }

    protected function naturalPolicy(): array
    {
        return [Policy::EXPANDING, Policy::FIXED];
    }

    protected function applyValue(string $value): void
    {
        $this->applying(fn () => $this->native()->setText($value));
    }

    protected function applyPlaceholder(?string $placeholder): void
    {
        $this->native()->setPlaceholderText($placeholder ?? '');
    }
}
