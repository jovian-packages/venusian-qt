<?php

namespace Jovian\Toolkits\Qt\Services;

use InvalidArgumentException;
use QDesktopServices;
use QUrl;
use Surface\Contracts\Windows\Services\Desktop;
use Surface\Windows\Services\WebAddress;

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

    public function browse(string $url): void
    {
        QDesktopServices::openUrl(new QUrl(WebAddress::checked($url)));
    }
}
