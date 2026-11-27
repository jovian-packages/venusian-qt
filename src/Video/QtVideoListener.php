<?php

namespace Jovian\Toolkits\Qt\Video;

/**
 * What a player reports about its media.
 */
interface QtVideoListener
{
    /** The media started playing. */
    public function videoPlaying(): void;

    /** The media stopped short of its end. */
    public function videoPaused(): void;

    /** The media reached its end. */
    public function videoEnded(): void;

    /** The media cannot be played; $reason is the player's own text. */
    public function videoFailed(?string $reason): void;

    /** The player's own transport muted or unmuted the sound. */
    public function videoMuted(bool $muted): void;
}
