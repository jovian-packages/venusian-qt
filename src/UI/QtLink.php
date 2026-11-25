<?php

namespace Jovian\Toolkits\Qt\UI;

use QLabel;
use Qt\TextFormat;
use Qt\TextInteractionFlag;
use Surface\NutsAndBolts\Color;
use Surface\Toolkits\UI\TKLink;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\Qt\UI\Concerns\QtText;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;
use Jovian\Toolkits\Qt\UI\Concerns\QtSheetMargin;

/**
 * A rich text QLabel holding one anchor, which Qt opens from a click or the keyboard. Text
 * and URL are escaped, so neither is read as markup.
 */
class QtLink extends TKLink
{
    use QtNative;
    use QtSheetMargin;
    use QtText;

    private ?Color $link_color = null;

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

    /**
     * A label draws an anchor in the palette's link colour, not the sheet's: the colour goes
     * on the anchor itself, which Qt's rich text honours. Null leaves the palette's.
     */
    protected function applyTextColor(?Color $color): void
    {
        $this->link_color = $color;
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

        $style = is_null($this->link_color) ? '' : ' style="color: '.$this->link_color->toCss().'"';

        return '<a href="'.$escape($this->url()).'"'.$style.'>'.$text.'</a>';
    }

    private function linkNative(): QLabel
    {
        /** @var QLabel */
        return $this->native();
    }
}
