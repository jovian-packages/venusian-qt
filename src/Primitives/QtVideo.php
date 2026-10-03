<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QAudioOutput;
use QMediaPlayer;
use QMediaPlayer\MediaStatus;
use QMediaPlayer\PlaybackState;
use QObject;
use QUrl;
use QVideoWidget;
use Surface\Contracts\Windows\Mail\View\VideoEnded;
use Surface\Contracts\Windows\Mail\View\VideoFailed;
use Surface\Contracts\Windows\Mail\View\VideoPaused;
use Surface\Contracts\Windows\Mail\View\VideoPlaying;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKVideo;

/**
 * A QVideoWidget fed by a QMediaPlayer (with a QAudioOutput), both children of the widget.
 * Playing means the player is in its playing state with media it can play (buffering or
 * buffered): macOS's backend enters the playing state on play() while the file is still
 * loading, and stays there for a file that fails to load. Every state or status change and
 * every error recomputes it: VideoPlaying when it turns true, VideoPaused when it turns false
 * (paused, stopped, at the end, or failed), then VideoEnded at the end of the media and
 * VideoFailed on an error, so isPlaying() is already false when either arrives.
 */
class QtVideo extends TKVideo implements QtNative
{
    use QtPrimitive {
        destroyNative as destroyWidget;
    }

    protected QMediaPlayer $player;

    protected QAudioOutput $audio;

    /**
     * @throws WindowException When Qt loaded no media backend: nothing could play.
     */
    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, ?string $file)
    {
        parent::__construct($name, $window, $parent, $placement, $file);

        $player = new QMediaPlayer();
        if (! $player->isAvailable()) {
            throw new WindowException('TKVideo needs a Qt Multimedia backend plugin (Debian: libqt6multimedia6; Homebrew: qt).');
        }

        $video = $this->adoptNative(new QVideoWidget());
        $player->setParent($video);
        $this->player = $player;
        $this->audio = new QAudioOutput($player);
        $player->setAudioOutput($this->audio);
        $player->setVideoOutput($video);

        QObject::connect($player, 'playbackStateChanged(QMediaPlayer::PlaybackState)', function (): void {
            if (! $this->removed) {
                $this->reportPlaying();
            }
        });
        QObject::connect($player, 'mediaStatusChanged(QMediaPlayer::MediaStatus)', function (int $status): void {
            if ($this->removed) {
                return;
            }
            $this->reportPlaying();
            if ($status === MediaStatus::END_OF_MEDIA->value) {
                $this->session()->post(new VideoEnded($this->window->name(), $this->path(), $this->uuid));
            }
        });
        QObject::connect($player, 'errorOccurred(QMediaPlayer::Error,QString)', function (int $error, string $message): void {
            if ($this->removed) {
                return;
            }
            $this->reportPlaying();
            $this->session()->post(new VideoFailed($this->window->name(), $this->path(), $this->uuid, $message !== '' ? $message : "Qt Multimedia error {$error}."));
        });

        $this->applyFile($file);
    }

    public function native(): QVideoWidget
    {
        return $this->native;
    }

    /**
     * The media player behind the widget.
     * @return QMediaPlayer
     */
    public function player(): QMediaPlayer
    {
        return $this->player;
    }

    /**
     * Read whether the media plays from the player, and post a change.
     * @return void
     */
    protected function reportPlaying(): void
    {
        $playing = $this->player->playbackState() === PlaybackState::PLAYING
            && in_array($this->player->mediaStatus(), [MediaStatus::BUFFERING, MediaStatus::BUFFERED], true);
        if ($playing === $this->playing) {
            return;
        }
        $this->nativeStateChanged($playing);
        $this->session()->post($playing
            ? new VideoPlaying($this->window->name(), $this->path(), $this->uuid)
            : new VideoPaused($this->window->name(), $this->path(), $this->uuid));
    }

    protected function applyFile(?string $file): void
    {
        // An empty URL unloads the media.
        $this->player->setSource(QUrl::fromLocalFile($file ?? ''));
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
        $this->player->setPosition((int) round($seconds * 1000));
    }

    protected function applyMuted(bool $muted): void
    {
        $this->audio->setMuted($muted);
    }

    protected function applyLoop(bool $loop): void
    {
        $this->player->setLoops($loop ? QMediaPlayer::INFINITE_LOOPS : 1);
    }

    protected function nativePosition(): float
    {
        return $this->player->position() / 1000;
    }

    protected function nativeDuration(): ?float
    {
        $duration = $this->player->duration();

        return $duration > 0 ? $duration / 1000 : null;
    }

    /**
     * Silence the player before its widget goes: it is deleted with the widget on the next pump.
     */
    protected function destroyNative(): void
    {
        $this->whileNativeAlive(fn () => $this->player->stop());
        $this->destroyWidget();
    }
}
