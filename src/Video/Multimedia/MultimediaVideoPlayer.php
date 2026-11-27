<?php

namespace Jovian\Toolkits\Qt\Video\Multimedia;

use QUrl;
use QIcon;
use QLabel;
use QSlider;
use QObject;
use QWidget;
use QHBoxLayout;
use QVBoxLayout;
use QToolButton;
use QAudioOutput;
use QMediaPlayer;
use QVideoWidget;
use WeakReference;
use QIcon\ThemeIcon;
use Qt\Orientation;
use QMetaObject\Connection;
use QMediaPlayer\MediaStatus;
use QMediaPlayer\PlaybackState;
use Jovian\Toolkits\Qt\Video\QtVideoPlayer;
use Jovian\Toolkits\Qt\Video\QtVideoListener;

/**
 * A host widget holding a QVideoWidget fed by a QMediaPlayer (with its QAudioOutput) above a
 * control bar, shown while the view has controls: play or pause, a position slider, the time
 * and the sound, each with Qt's own theme icon. QVideoWidget has no transport of its own, where
 * AVPlayerView and GtkVideo show theirs. The bar's sound button tells the listener the muting
 * changed; the slider follows the player except while it is held.
 *
 * A stopped QMediaPlayer shows no frame, so the player pauses once the media loads (its first
 * frame) and at the end (its last frame); play() after the end starts again from the start.
 * Playing is the playing state with media it can play (buffering or buffered): macOS's
 * backend enters the playing state on play() while the media is still loading. Each state or
 * status change recomputes it and reports a change; the stopped state is never a pause. The end
 * reports ended, an error failed with Qt's message. Each start and each stop is reported once.
 */
final class MultimediaVideoPlayer implements QtVideoPlayer
{
    private QWidget $host;

    private QMediaPlayer $player;

    private QAudioOutput $audio;

    private QVideoWidget $picture;

    private QWidget $bar;

    private QToolButton $toggle;

    private QSlider $scrub;

    private QLabel $time;

    private QToolButton $sound;

    /** @var list<Connection> */
    private array $connections = [];

    /** @var WeakReference<QtVideoListener> */
    private WeakReference $listener;

    /** Playing was reported, and nothing has stopped it since. */
    private bool $playing = false;

    /** Paused on the last frame after the end: play() starts again from the start. */
    private bool $at_end = false;

    /** The slider is held: the player's position does not move it. */
    private bool $scrubbing = false;

    private bool $released = false;

    public function __construct(QtVideoListener $listener)
    {
        $this->listener = WeakReference::create($listener);
        $this->host = new QWidget();
        $this->picture = new QVideoWidget();
        $this->buildBar();

        $column = new QVBoxLayout($this->host);
        $column->setContentsMargins(0, 0, 0, 0);
        $column->setSpacing(4);
        $column->addWidget($this->picture, 1);
        $column->addWidget($this->bar);
        $this->bar->setVisible(false);

        $this->player = new QMediaPlayer($this->picture);
        $this->audio = new QAudioOutput($this->player);
        $this->player->setAudioOutput($this->audio);
        $this->player->setVideoOutput($this->picture);

        // The player's slots can outlive the widget while the backend winds down: they reach the
        // player through a weak reference, so a released one is freed.
        $self = WeakReference::create($this);
        $live = static fn (): ?self => ($player = $self->get()) === null || $player->released ? null : $player;
        $this->connections[] = QObject::connect($this->player, 'playbackStateChanged(QMediaPlayer::PlaybackState)', static function () use ($live): void {
            $live()?->report();
        });
        $this->connections[] = QObject::connect($this->player, 'mediaStatusChanged(QMediaPlayer::MediaStatus)', static function (int $status) use ($live): void {
            $live()?->statusChanged($status);
        });
        $this->connections[] = QObject::connect($this->player, 'positionChanged(qint64)', static function (int $ms) use ($live): void {
            $live()?->showPosition($ms);
        });
        $this->connections[] = QObject::connect($this->player, 'durationChanged(qint64)', static function (int $ms) use ($live): void {
            $player = $live();
            if (! is_null($player)) {
                $player->scrub->setRange(0, max(0, $ms));
                $player->showPosition($player->player->position());
            }
        });
        $this->connections[] = QObject::connect($this->player, 'errorOccurred(QMediaPlayer::Error,QString)', static function (int $error, string $message) use ($live): void {
            $player = $live();
            if (! is_null($player)) {
                $player->playing = false;
                $player->listener->get()?->videoFailed($message !== '' ? $message : "Qt Multimedia error {$error}.");
            }
        });
    }

    public function widget(): QWidget
    {
        return $this->host;
    }

    /** The video widget the player draws into. */
    public function picture(): QVideoWidget
    {
        return $this->picture;
    }

    /** The control bar under the picture. */
    public function controls(): QWidget
    {
        return $this->bar;
    }

    /** The player itself. */
    public function mediaPlayer(): QMediaPlayer
    {
        return $this->player;
    }

    /** The output the sound goes to. */
    public function audioOutput(): QAudioOutput
    {
        return $this->audio;
    }

    public function load(?string $url, bool $muted, bool $loop, bool $controls): void
    {
        $this->setMuted($muted);
        $this->setLoop($loop);
        $this->setControls($controls);

        // An empty URL unloads the media.
        $this->playing = false;
        $this->at_end = false;
        $this->player->setSource(new QUrl($url ?? ''));
        $this->scrub->setRange(0, 0);
        $this->showPosition(0);
    }

    public function play(): void
    {
        if ($this->at_end) {
            $this->player->setPosition(0);
        }
        $this->at_end = false;
        $this->player->play();
    }

    public function pause(): void
    {
        $this->player->pause();
    }

    public function seek(float $seconds): void
    {
        $this->at_end = false;
        $this->player->setPosition((int) round($seconds * 1000));
    }

    public function setMuted(bool $muted): void
    {
        $this->audio->setMuted($muted);
        $this->sound->setIcon(QIcon::fromTheme($muted ? ThemeIcon::AUDIO_VOLUME_MUTED : ThemeIcon::AUDIO_VOLUME_HIGH));
        $this->sound->setAccessibleName($muted ? 'Unmute' : 'Mute');
        $this->sound->setToolTip($muted ? 'Unmute' : 'Mute');
    }

    public function setLoop(bool $loop): void
    {
        $this->player->setLoops($loop ? QMediaPlayer::INFINITE_LOOPS : 1);
    }

    public function setControls(bool $controls): void
    {
        $this->bar->setVisible($controls);
    }

    public function position(): float
    {
        return $this->player->position() / 1000;
    }

    public function duration(): ?float
    {
        $duration = $this->player->duration();

        return $duration > 0 ? $duration / 1000 : null;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        foreach ($this->connections as $connection) {
            QObject::disconnect($connection);
        }
        $this->connections = [];
        $this->player->stop();
    }

    /** The control bar: play or pause, the position, the time, the sound. */
    private function buildBar(): void
    {
        $this->bar = new QWidget();
        $row = new QHBoxLayout($this->bar);
        $row->setContentsMargins(0, 0, 0, 0);
        $row->setSpacing(8);
        $this->toggle = self::barButton(ThemeIcon::MEDIA_PLAYBACK_START, 'Play');
        $this->scrub = new QSlider(Orientation::HORIZONTAL);
        $this->scrub->setRange(0, 0);
        $this->scrub->setAccessibleName('Position');
        $this->time = new QLabel('0:00 / 0:00');
        $this->sound = self::barButton(ThemeIcon::AUDIO_VOLUME_HIGH, 'Mute');
        $row->addWidget($this->toggle);
        $row->addWidget($this->scrub, 1);
        $row->addWidget($this->time);
        $row->addWidget($this->sound);

        $self = WeakReference::create($this);
        $live = static fn (): ?self => ($player = $self->get()) === null || $player->released ? null : $player;
        $this->connections[] = QObject::connect($this->toggle, 'clicked()', static function () use ($live): void {
            $player = $live();
            is_null($player) || ($player->playing ? $player->pause() : $player->play());
        });
        $this->connections[] = QObject::connect($this->sound, 'clicked()', static function () use ($live): void {
            $player = $live();
            if (! is_null($player)) {
                $muted = ! $player->audio->isMuted();
                $player->setMuted($muted);
                $player->listener->get()?->videoMuted($muted);
            }
        });
        $this->connections[] = QObject::connect($this->scrub, 'sliderPressed()', static function () use ($live): void {
            $player = $live();
            is_null($player) || $player->scrubbing = true;
        });
        $this->connections[] = QObject::connect($this->scrub, 'sliderMoved(int)', static function (int $ms) use ($live): void {
            $live()?->seek($ms / 1000);
        });
        $this->connections[] = QObject::connect($this->scrub, 'sliderReleased()', static function () use ($live): void {
            $player = $live();
            is_null($player) || $player->scrubbing = false;
        });
    }

    private static function barButton(ThemeIcon $icon, string $name): QToolButton
    {
        $button = new QToolButton();
        $button->setAutoRaise(true);
        $button->setIcon(QIcon::fromTheme($icon));
        $button->setAccessibleName($name);
        $button->setToolTip($name);

        return $button;
    }

    private function statusChanged(int $status): void
    {
        // A stopped player shows no frame: paused, it shows the first one once loaded and the
        // last one at the end.
        if ($status === MediaStatus::LOADED->value && $this->player->playbackState() === PlaybackState::STOPPED) {
            $this->player->pause();
        }

        $this->report();
    }

    /**
     * Read whether the media plays from the player, and report a change. The stopped state is
     * never a pause: Qt enters it at the end (the state turns stopped while the status still
     * reads buffered, and END_OF_MEDIA follows), on an error, and on an unload, each reported
     * on its own.
     */
    private function report(): void
    {
        if ($this->player->mediaStatus() === MediaStatus::END_OF_MEDIA && ! $this->at_end) {
            $this->at_end = true;
            $this->playing = false;
            $this->listener->get()?->videoEnded();
            $this->player->setPosition(max(0, $this->player->duration() - 1));
            $this->player->pause();
        }

        $state = $this->player->playbackState();
        $playing = $state === PlaybackState::PLAYING
            && in_array($this->player->mediaStatus(), [MediaStatus::BUFFERING, MediaStatus::BUFFERED], true);

        if ($playing && ! $this->playing) {
            $this->playing = true;
            $this->listener->get()?->videoPlaying();
        } elseif (! $playing && $this->playing && $state !== PlaybackState::STOPPED) {
            $this->playing = false;
            $this->listener->get()?->videoPaused();
        }
        $this->toggle->setIcon(QIcon::fromTheme($this->playing ? ThemeIcon::MEDIA_PLAYBACK_PAUSE : ThemeIcon::MEDIA_PLAYBACK_START));
        $this->toggle->setAccessibleName($this->playing ? 'Pause' : 'Play');
        $this->toggle->setToolTip($this->playing ? 'Pause' : 'Play');
    }

    /** The slider and the time at $ms, unless the slider is held. */
    private function showPosition(int $ms): void
    {
        $this->scrubbing || $this->scrub->setValue($ms);
        $this->time->setText(self::clock($ms).' / '.self::clock($this->player->duration()));
    }

    /** m:ss, or h:mm:ss from an hour. */
    private static function clock(int $ms): string
    {
        $s = intdiv(max(0, $ms), 1000);

        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}
