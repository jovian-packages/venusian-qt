<?php

namespace Jovian\Toolkits\Qt\UI;

use QLabel;
use Qt\TextFormat;
use Qt\TextInteractionFlag;
use Surface\Toolkits\UI\TKLink;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\Qt\UI\Concerns\QtText;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A rich text QLabel holding one anchor, which Qt opens from a click or the keyboard. Text
 * and URL are escaped, so neither is read as markup.
 */
class QtLink extends TKLink
{
    use QtNative;
    use QtText;

    protected function bootstrapNative(): void
    {
        $label = new QLabel($this->anchor());
        $label->setTextFormat(TextFormat::RICH_TEXT);
        $label->setOpenExternalLinks(true);
        $label->setTextInteractionFlags(TextInteractionFlag::LINKS_ACCESSIBLE_BY_MOUSE->value | TextInteractionFlag::LINKS_ACCESSIBLE_BY_KEYBOARD->value);
        $this->adoptNative($label);
        $this->applyAlignment($this->alignment);
    }

    protected function applyText(string $text): void
    {
        $this->linkNative()->setText($this->anchor());
    }

    protected function applyUrl(string $url): void
    {
        $this->linkNative()->setText($this->anchor());
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->linkNative()->setAlignment(self::alignmentFlags($alignment));
    }

    /**
     * Rich text collapses white space: line breaks become <br>, repeated spaces &nbsp;.
     */
    private function anchor(): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML401 | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace_callback('/ {2,}/', static fn (array $run): string => ' '.str_repeat('&nbsp;', strlen($run[0]) - 1), $escape($this->text()));
        $text = str_replace(["\r\n", "\r", "\n"], '<br>', $text);

        return '<a href="'.$escape($this->url()).'">'.$text.'</a>';
    }

    private function linkNative(): QLabel
    {
        /** @var QLabel */
        return $this->native();
    }
}
