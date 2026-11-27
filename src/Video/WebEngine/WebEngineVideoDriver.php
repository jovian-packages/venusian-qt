<?php

namespace Jovian\Toolkits\Qt\Video\WebEngine;

use RuntimeException;
use Jovian\Toolkits\Qt\Video\QtVideoDriver;
use Jovian\Toolkits\Qt\Video\QtVideoPlayer;
use Jovian\Toolkits\Qt\Video\QtVideoListener;

/**
 * The 'webview' video driver: each player is a QWebEngineView (Chromium) showing the media as
 * its document.
 */
class WebEngineVideoDriver implements QtVideoDriver
{
    /** The Qt WebEngine classes a player is made of. */
    private const array NEEDS = ['QWebEngineView', 'QWebEngineConsolePage', 'QWebEngineSettings', 'QWebEngineScript', 'QWebEngineScriptCollection'];

    /**
     * @throws RuntimeException When the loaded ext-qt lacks one of the Qt WebEngine classes.
     */
    public function __construct()
    {
        $missing = array_values(array_filter(self::NEEDS, static fn (string $class): bool => ! class_exists($class)));
        if ($missing !== []) {
            throw new RuntimeException("The 'webview' video driver needs ext-qt 0.11.2 or later built with Qt WebEngine, which has ".implode(', ', $missing).". Install it with 'pie install php-io-extensions/qt'");
        }
    }

    public function player(QtVideoListener $listener): QtVideoPlayer
    {
        return new WebEngineVideoPlayer($listener);
    }

    /** Chromium always has a media player. */
    public function supported(): bool
    {
        return true;
    }
}
