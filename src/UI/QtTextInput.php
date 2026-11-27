<?php

namespace Jovian\Toolkits\Qt\UI;

use QIcon;
use QAction;
use QObject;
use QLineEdit;
use WeakReference;
use QLineEdit\EchoMode;
use QIcon\ThemeIcon;
use Qt\WidgetAttribute;
use QLineEdit\ActionPosition;
use QSizePolicy\Policy;
use Surface\Toolkits\UI\TKTextInput;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\Qt\UI\Concerns\QtText;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\QtSheetMargin;

/**
 * A QLineEdit. textChanged(QString) is typing, returnPressed() a submit. A search field has
 * Qt's clear button and the theme's search glyph.
 */
class QtTextInput extends TKTextInput implements QtKeepsOwnMargin
{
    use QtNative;
    use QtSheetMargin;
    use QtText;

    /** QLineEdit's own maximum length. */
    private const int NO_LIMIT = 32767;

    /** The search glyph's action, owned by the line edit; null when the theme has no glyph. */
    private ?QAction $search_action = null;

    protected function bootstrapNative(): void
    {
        $edit = new QLineEdit($this->value());
        $edit->setPlaceholderText($this->placeholder() ?? '');
        if ($this->isSecret()) {
            $edit->setEchoMode(EchoMode::PASSWORD);
        }
        if ($this->isSearch()) {
            $this->searchField($edit);
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

    /**
     * Qt cuts a value longer than the maximum length: the value is what the field holds.
     */
    protected function applyValue(string $value): void
    {
        $this->editNative()->setText($value);
        $this->cutTo($this->editNative()->text());
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
     * No frame (Qt's own frameless line edit), no macOS focus ring, and no field fill: the
     * frameless field still paints its base colour, so the sheet makes it transparent.
     */
    protected function applyBorderless(bool $borderless): void
    {
        $this->editNative()->setFrame(! $borderless);
        $this->editNative()->setAttribute(WidgetAttribute::MAC_SHOW_FOCUS_RECT, ! $borderless);
        $this->restyleBox();
    }

    protected function applyReadOnly(bool $readOnly): void
    {
        $this->editNative()->setReadOnly($readOnly);
    }

    /**
     * No limit is Qt's default, 32767. Qt cuts a longer text, and says so through textChanged:
     * that is no typing, and the value is what the field holds.
     */
    protected function applyMaxLength(?int $length): void
    {
        $this->write(fn () => $this->editNative()->setMaxLength($length ?? self::NO_LIMIT));
        $this->cutTo($this->editNative()->text());
    }

    protected function ownBackground(): ?string
    {
        return $this->isBorderless() ? 'transparent' : null;
    }

    /**
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::EXPANDING, Policy::FIXED];
    }

    /**
     * Qt's clear button, and the platform's search glyph at the start when its theme has one.
     */
    private function searchField(QLineEdit $edit): void
    {
        $edit->setClearButtonEnabled(true);

        $glyph = QIcon::fromTheme(ThemeIcon::SYSTEM_SEARCH);
        if (! $glyph->isNull()) {
            $this->search_action = $edit->addAction($glyph, ActionPosition::LEADING_POSITION);
        }
    }

    private function editNative(): QLineEdit
    {
        /** @var QLineEdit */
        return $this->native();
    }
}
