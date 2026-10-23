<?php

namespace Jovian\Toolkits\Qt\Services;

use QClipboard\Mode;
use QGuiApplication;
use Surface\Contracts\Windows\Services\Clipboard;

final class QtClipboard implements Clipboard
{
    public function setText(string $text): void
    {
        // The driver's session made the application first, so Qt hands back its clipboard.
        QGuiApplication::clipboard()->setText($text, Mode::CLIPBOARD);
    }
}
