<?php

namespace Jovian\Toolkits\Qt\Video\Multimedia;

use QMediaPlayer;
use Jovian\Toolkits\Qt\Video\QtVideoDriver;
use Jovian\Toolkits\Qt\Video\QtVideoPlayer;
use Jovian\Toolkits\Qt\Video\QtVideoListener;

/**
 * The 'qt' video driver: each player is a QMediaPlayer drawing into a QVideoWidget (Qt
 * Multimedia). It plays where Qt loaded a media backend plugin.
 */
class MultimediaVideoDriver implements QtVideoDriver
{
    private ?bool $available = null;

    public function player(QtVideoListener $listener): QtVideoPlayer
    {
        return new MultimediaVideoPlayer($listener);
    }

    public function supported(): bool
    {
        return $this->available ??= new QMediaPlayer()->isAvailable();
    }
}
