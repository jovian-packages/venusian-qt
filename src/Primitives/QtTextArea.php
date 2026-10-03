<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QObject;
use QPlainTextEdit;
use QSizePolicy\Policy;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTextArea;

class QtTextArea extends TKTextArea implements QtNative
{
    use QtPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $value)
    {
        parent::__construct($name, $window, $parent, $placement, $value);
        $edit = $this->adoptNative(new QPlainTextEdit());
        $edit->setPlainText($value);

        QObject::connect($edit, 'textChanged()', function (): void {
            if (! $this->handling()) {
                return;
            }
            $text = $this->native()->toPlainText();
            $this->nativeValueChanged($text);
            $this->session()->post(new TextChanged($this->window->name(), $this->path(), $this->uuid, $text));
        });
    }

    public function native(): QPlainTextEdit
    {
        return $this->native;
    }

    protected function naturalPolicy(): array
    {
        return [Policy::EXPANDING, Policy::EXPANDING];
    }

    protected function applyValue(string $value): void
    {
        $this->applying(fn () => $this->native()->setPlainText($value));
    }
}
