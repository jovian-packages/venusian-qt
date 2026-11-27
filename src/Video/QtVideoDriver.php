<?php

namespace Jovian\Toolkits\Qt\Video;

/**
 * Makes the players of one kind. A driver checks what it needs when it is made, and throws
 * with the fix when something is missing.
 */
interface QtVideoDriver
{
    /**
     * A player for one video view, nothing loaded, reporting to $listener.
     */
    public function player(QtVideoListener $listener): QtVideoPlayer;

    /**
     * Whether this driver's players can play media here.
     */
    public function supported(): bool;
}
