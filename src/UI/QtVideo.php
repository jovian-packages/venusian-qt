<?php

namespace Jovian\Toolkits\Qt\UI;

use QEvent;
use QObject;
use QWidget;
use QEventFilter;
use WeakReference;
use QEvent\Type as EventType;
use QVideoWidget;
use Jovian\Toolkits\Qt\QtWindow;
use Surface\Toolkits\UI\TKVideo;
use Jovian\Toolkits\Qt\Video\QtVideoDriver;
use Jovian\Toolkits\Qt\Video\QtVideoPlayer;
use Jovian\Toolkits\Qt\Video\QtVideoListener;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A video view. The player is made by the video manager's driver (qt.video.driver): this view
 * places the player's widget and hands it every call. What the player reports comes back
 * through QtVideoListener and is posted as Topside's video mail; a muting the player's own
 * transport makes is this view's muting too.
 *
 * A player plays on whether or not anything shows it, so the view pauses it when its widget is
 * hidden: Qt sends the hide event to every widget inside a container it hides (a drawer sliding
 * away). It does not start again when it is shown again.
 *
 * supported() is the driver's answer: the 'qt' driver plays where Qt loaded a media backend
 * plugin, the 'webview' driver always.
 */
class QtVideo extends TKVideo implements QtVideoListener
{
    use QtNative {
        releaseNative as private releaseWidget;
    }

    private ?QtVideoPlayer $player = null;

    /** Watches the player's widget for Qt's hide event. */
    private ?QEventFilter $hide_filter = null;

    public function supported(): bool
    {
        return $this->driver()->supported();
    }

    protected function bootstrapNative(): void
    {
        $this->player = $this->driver()->player($this);
        $this->adoptNative($this->player->widget());

        $self = WeakReference::create($this);
        $this->hide_filter = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            if ($event->type() === EventType::HIDE) {
                $self->get()?->hidden();
            }

            return false;
        });
        $this->native->installEventFilter($this->hide_filter);
    }

    /** The widget was hidden, itself or with a container above it: the media stops with it. */
    private function hidden(): void
    {
        if (! $this->isRemoved() && $this->isPlaying()) {
            $this->pause();
        }
    }

    protected function releaseNative(): void
    {
        $this->native->removeEventFilter($this->hide_filter);
        $this->hide_filter = null;
        $this->player->release();
        $this->player = null;
        $this->releaseWidget();
    }

    protected function applySource(?string $url): void
    {
        $this->player->load($url, $this->muted, $this->looping, $this->controls);
    }

    protected function applyPlay(): void
    {
        $this->player->play();
    }

    protected function applyPause(): void
    {
        $this->player->pause();
    }

    protected function applySeek(float $seconds): void
    {
        $this->player->seek($seconds);
    }

    protected function applyMuted(bool $muted): void
    {
        $this->player->setMuted($muted);
    }

    protected function applyLoop(bool $loop): void
    {
        $this->player->setLoop($loop);
    }

    protected function applyControls(bool $controls): void
    {
        $this->player->setControls($controls);
    }

    protected function readPosition(): float
    {
        return $this->player->position();
    }

    protected function readDuration(): ?float
    {
        return $this->player->duration();
    }

    /** The player the driver made for this view. */
    public function player(): QtVideoPlayer
    {
        return $this->player;
    }

    public function videoPlaying(): void
    {
        $this->playing();
    }

    public function videoPaused(): void
    {
        $this->paused();
    }

    public function videoEnded(): void
    {
        $this->ended();
    }

    public function videoFailed(?string $reason): void
    {
        $this->failed($reason);
    }

    public function videoMuted(bool $muted): void
    {
        if (! $this->isRemoved()) {
            $this->setMuted($muted);
        }
    }

    private function driver(): QtVideoDriver
    {
        /** @var QtWindow $window */
        $window = $this->window();

        return $window->adapter()->video()->driver();
    }
}
