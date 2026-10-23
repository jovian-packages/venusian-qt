<?php

namespace Jovian\Toolkits\Qt\Services;

use InvalidArgumentException;
use QDesktopServices;
use QUrl;
use Surface\Contracts\Windows\Services\Desktop;

final class QtDesktop implements Desktop
{
    public function open(string $path): void
    {
        $full = realpath($path);
        if ($full === false) {
            throw new InvalidArgumentException("Nothing at {$path} to open.");
        }
        QDesktopServices::openUrl(QUrl::fromLocalFile($full));
    }
}
