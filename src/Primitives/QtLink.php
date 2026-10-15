<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QLabel;
use Qt\AlignmentFlag;
use Qt\TextFormat;
use Qt\TextInteractionFlag;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Styling\TextAlignment;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKLink;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A link over a rich-text QLabel holding one anchor: Qt opens the URL when it is clicked or
 * activated from the keyboard (openExternalLinks). Text and URL are escaped, so each shows and
 * opens as written.
 */
class QtLink extends TKLink implements QtNative
{
    use QtPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $text, string $url)
    {
        parent::__construct($name, $window, $parent, $placement, $text, $url);
        $label = $this->adoptNative(new QLabel(self::anchor($text, $url)));
        $label->setTextFormat(TextFormat::RICH_TEXT);
        $label->setOpenExternalLinks(true);
        $label->setTextInteractionFlags(TextInteractionFlag::LINKS_ACCESSIBLE_BY_MOUSE->value | TextInteractionFlag::LINKS_ACCESSIBLE_BY_KEYBOARD->value);
        $this->applyAlignment($this->alignment);
    }

    /**
     * One anchor: the URL and the text, HTML-escaped (quotes too). Rich text collapses whitespace,
     * so the text's line breaks become <br> and each space after the first in a run a &nbsp;.
     */
    public static function anchor(string $text, string $url): string
    {
        $escape = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML401 | ENT_SUBSTITUTE, 'UTF-8');
        $shown = preg_replace_callback('/ {2,}/', fn (array $run): string => ' '.str_repeat('&nbsp;', strlen($run[0]) - 1), $escape($text));
        $shown = str_replace(["\r\n", "\r", "\n"], '<br>', $shown);

        return '<a href="'.$escape($url).'">'.$shown.'</a>';
    }

    public function native(): QLabel
    {
        return $this->native;
    }

    protected function applyText(string $text): void
    {
        $this->native()->setText(self::anchor($text, $this->url));
    }

    protected function applyUrl(string $url): void
    {
        $this->native()->setText(self::anchor($this->text, $url));
    }

    /**
     * Horizontal only: QLabel's vertical centring (its default) stays.
     * @param TextAlignment $alignment
     * @return void
     */
    protected function applyAlignment(TextAlignment $alignment): void
    {
        $horizontal = match ($alignment) {
            TextAlignment::LEFT => AlignmentFlag::LEFT,
            TextAlignment::CENTER => AlignmentFlag::H_CENTER,
            TextAlignment::RIGHT => AlignmentFlag::RIGHT,
        };
        $this->native()->setAlignment($horizontal->value | AlignmentFlag::V_CENTER->value);
    }
}
