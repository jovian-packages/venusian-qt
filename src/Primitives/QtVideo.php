<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QAudioOutput;
use QHBoxLayout;
use QLabel;
use QMediaPlayer;
use QMediaPlayer\MediaStatus;
use QMediaPlayer\PlaybackState;
use QObject;
use QPushButton;
use QSlider;
use Qt\Orientation;
use QUrl;
use QVBoxLayout;
use QVideoWidget;
use QWidget;
use Surface\Contracts\Windows\Mail\View\VideoEnded;
use Surface\Contracts\Windows\Mail\View\VideoFailed;
use Surface\Contracts\Windows\Mail\View\VideoPaused;
use Surface\Contracts\Windows\Mail\View\VideoPlaying;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKVideo;
use WeakReference;

/**
 * A host widget holding a QVideoWidget fed by a QMediaPlayer (with a QAudioOutput, both
 * children of the video widget) above a control bar: play/pause, a position slider, the time
 * and mute. QVideoWidget has no controls of its own, where AVPlayerView and GtkVideo show
 * theirs; the bar's buttons go through play(), pause() and setMuted(), so they post as code
 * would, and the slider follows positionChanged/durationChanged except while it is dragged.
 * Disabled, the bar hides, as AppKit's view shows no controls then. A stopped QMediaPlayer shows
 * no frame, so the player pauses once the file loads (the first frame) and at the end (the last
 * frame); play() after the end starts again from the start, as on AppKit.
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

    protected QVideoWidget $video;

    protected QWidget $bar;

    protected QPushButton $toggle;

    protected QSlider $scrub;

    protected QLabel $time;

    protected QPushButton $mute;

    /** Paused on the last frame after the end: play() starts again from the start. */
    protected bool $at_end = false;

    /** The slider is held: the player's position does not move it. */
    protected bool $scrubbing = false;

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

        $host = $this->adoptNative(new QWidget());
        $video = $this->video = new QVideoWidget();
        $player->setParent($video);
        $this->buildBar();
        $column = new QVBoxLayout($host);
        $column->setContentsMargins(0, 0, 0, 0);
        $column->setSpacing(4);
        $column->addWidget($video, 1);
        $column->addWidget($this->bar);
        $this->player = $player;
        $this->audio = new QAudioOutput($player);
        $player->setAudioOutput($this->audio);
        $player->setVideoOutput($this->video);

        // The player's slots can outlive the widget while the media backend winds down: they reach
        // the primitive through a weak reference, so a removed video is freed.
        $self = WeakReference::create($this);
        QObject::connect($player, 'playbackStateChanged(QMediaPlayer::PlaybackState)', static function () use ($self): void {
            $video = $self->get();
            if (! is_null($video) && ! $video->removed) {
                $video->reportPlaying();
            }
        });
        QObject::connect($player, 'mediaStatusChanged(QMediaPlayer::MediaStatus)', static function (int $status) use ($self): void {
            $video = $self->get();
            if (is_null($video) || $video->removed) {
                return;
            }
            // A stopped player shows no frame: paused, it shows the first one once loaded and the
            // last one at the end, as AVPlayerView and GtkVideo do.
            if ($status === MediaStatus::LOADED->value && $video->player->playbackState() === PlaybackState::STOPPED) {
                $video->player->pause();
            }
            if ($status === MediaStatus::END_OF_MEDIA->value) {
                $video->at_end = true;
                $video->player->setPosition(max(0, $video->player->duration() - 1));
                $video->player->pause();
            }
            $video->reportPlaying();
            if ($status === MediaStatus::END_OF_MEDIA->value) {
                $video->session()->post(new VideoEnded($video->window->name(), $video->path(), $video->uuid));
            }
        });
        QObject::connect($player, 'positionChanged(qint64)', static function (int $ms) use ($self): void {
            $video = $self->get();
            is_null($video) || $video->removed || $video->showPosition($ms);
        });
        QObject::connect($player, 'durationChanged(qint64)', static function (int $ms) use ($self): void {
            $video = $self->get();
            if (! is_null($video) && ! $video->removed) {
                $video->scrub->setRange(0, max(0, $ms));
                $video->showPosition($video->player->position());
            }
        });
        QObject::connect($player, 'errorOccurred(QMediaPlayer::Error,QString)', static function (int $error, string $message) use ($self): void {
            $video = $self->get();
            if (is_null($video) || $video->removed) {
                return;
            }
            $video->reportPlaying();
            $video->session()->post(new VideoFailed($video->window->name(), $video->path(), $video->uuid, $message !== '' ? $message : "Qt Multimedia error {$error}."));
        });

        $this->applyFile($file);
    }

    /** The host: the video above the control bar. */
    public function native(): QWidget
    {
        return $this->native;
    }

    public function videoWidget(): QVideoWidget
    {
        return $this->video;
    }

    /** The control bar under the video: play/pause, position, time, mute. */
    public function controls(): QWidget
    {
        return $this->bar;
    }

    protected function buildBar(): void
    {
        $this->bar = new QWidget();
        $row = new QHBoxLayout($this->bar);
        $row->setContentsMargins(0, 0, 0, 0);
        $row->setSpacing(8);
        $this->toggle = new QPushButton('▶');
        $this->toggle->setAccessibleName('Play');
        $this->scrub = new QSlider(Orientation::HORIZONTAL);
        $this->scrub->setRange(0, 0);
        $this->time = new QLabel('0:00 / 0:00');
        $this->mute = new QPushButton('Mute');
        $this->mute->setCheckable(true);
        $row->addWidget($this->toggle);
        $row->addWidget($this->scrub, 1);
        $row->addWidget($this->time);
        $row->addWidget($this->mute);

        $self = WeakReference::create($this);
        $live = static fn (): ?self => ($v = $self->get()) === null || $v->removed ? null : $v;
        QObject::connect($this->toggle, 'clicked()', static function () use ($live): void {
            $v = $live();
            is_null($v) || ($v->isPlaying() ? $v->pause() : $v->play());
        });
        QObject::connect($this->mute, 'clicked()', static function () use ($live): void {
            $v = $live();
            is_null($v) || $v->setMuted(! $v->isMuted());
        });
        QObject::connect($this->scrub, 'sliderPressed()', static function () use ($live): void {
            $v = $live();
            is_null($v) || $v->scrubbing = true;
        });
        QObject::connect($this->scrub, 'sliderMoved(int)', static function (int $ms) use ($live): void {
            $v = $live();
            is_null($v) || $v->seek($ms / 1000);
        });
        QObject::connect($this->scrub, 'sliderReleased()', static function () use ($live): void {
            $v = $live();
            is_null($v) || $v->scrubbing = false;
        });
    }

    /** The slider and the time at $ms, unless the slider is held. */
    protected function showPosition(int $ms): void
    {
        $this->scrubbing || $this->scrub->setValue($ms);
        $this->time->setText(self::clock($ms).' / '.self::clock($this->player->duration()));
    }

    /** m:ss, or h:mm:ss from an hour. */
    protected static function clock(int $ms): string
    {
        $s = intdiv(max(0, $ms), 1000);

        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
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
        $this->toggle->setText($playing ? '❚❚' : '▶');
        $this->toggle->setAccessibleName($playing ? 'Pause' : 'Play');
        $this->session()->post($playing
            ? new VideoPlaying($this->window->name(), $this->path(), $this->uuid)
            : new VideoPaused($this->window->name(), $this->path(), $this->uuid));
    }

    protected function applyFile(?string $file): void
    {
        // An empty URL unloads the media.
        $this->at_end = false;
        $this->player->setSource(QUrl::fromLocalFile($file ?? ''));
        $this->scrub->setRange(0, 0);
        $this->showPosition(0);
    }

    protected function applyPlay(): void
    {
        $this->at_end && $this->player->setPosition(0);
        $this->at_end = false;
        $this->player->play();
    }

    protected function applyPause(): void
    {
        $this->player->pause();
    }

    protected function applySeek(float $seconds): void
    {
        $this->at_end = false;
        $this->player->setPosition((int) round($seconds * 1000));
    }

    protected function applyMuted(bool $muted): void
    {
        $this->audio->setMuted($muted);
        $this->mute->setChecked($muted);
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->whileHoldingFocus(fn () => $this->native->setEnabled($enabled));
        $this->bar->setVisible($enabled);
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
