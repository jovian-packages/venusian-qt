<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\UI\QtVideo;
use Jovian\Toolkits\Qt\Video\Multimedia\MultimediaVideoPlayer;
use Jovian\Toolkits\Qt\Video\WebEngine\WebEngineVideoPlayer;
use Surface\Contracts\Toolkits\Mail\Views\VideoEnded;
use Surface\Contracts\Toolkits\Mail\Views\VideoFailed;
use Surface\Contracts\Toolkits\Mail\Views\VideoPlaying;

/*
| A video on each driver: 'qt' (a QMediaPlayer drawing into a QVideoWidget, with a bar of Qt's
| own icons while it has controls) and 'webview' (a QWebEngineView showing the media as its
| document). Every case runs on both. The fixtures are a 1.2 s 160 x 120 H.264 clip and a 1 s
| AAC tone, played from disk and from PHP's built-in server on 127.0.0.1 with byte ranges
| (fixtures/serve.php). Under the offscreen platform Qt's media backend never finishes loading,
| so these run on the platform the app runs on.
*/

dataset('video drivers', ['qt', 'webview']);

afterEach(function (): void {
    closeAll();
    toolkitApp()->make('config')->set('qt.video.driver', 'qt');
});

/** A window with one video filling its content column, its player from $driver. */
function qtVideo(string $driver, bool $controls = false): QtVideo
{
    if (QGuiApplication::platformName() === 'offscreen') {
        test()->markTestSkipped("Qt's media backend loads nothing under the 'offscreen' platform.");
    }

    toolkitApp()->make('config')->set('qt.video.driver', $driver);
    $window = openWindow('video', 400, 300);
    /** @var QtVideo $video */
    $video = $window->column('root')->video('player');
    $video->setControls($controls)->fill(true, true);
    pumpFor(0.05);
    takeMail();

    return $video;
}

function qtFixture(string $name): string
{
    return dirname(__DIR__).'/fixtures/'.$name;
}

/** The fixture's address on a server started once for the process. */
function qtServed(string $name): string
{
    static $port = null;
    if (is_null($port)) {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $dir = dirname(__DIR__).'/fixtures';
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$dir}/serve.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir);
        register_shutdown_function(static fn () => proc_terminate($server));
        // Until it answers: a refused connection while it starts is expected, not a warning.
        set_error_handler(static fn (): bool => true);
        $until = microtime(true) + 5.0;
        while (! stream_socket_client("tcp://127.0.0.1:{$port}", $code, $text, 0.1) && microtime(true) < $until) {
            usleep(20_000);
        }
        restore_error_handler();
    }

    return "http://127.0.0.1:{$port}/{$name}";
}

/** @param list<object> $mail @return list<class-string> the video mail, in order */
function qtVideoMail(array $mail): array
{
    return array_values(array_map(fn (object $m): string => $m::class, array_filter($mail, fn (object $m): bool => str_starts_with($m::class, 'Surface\Contracts\Toolkits\Mail\Views\Video'))));
}

/**
 * What the player holds now, read from the native player: muted, loop, controls, paused, and
 * the position in seconds.
 */
function qtPlayerReads(QtVideo $video, string $what): mixed
{
    $player = $video->player();
    if ($player instanceof MultimediaVideoPlayer) {
        $media = $player->mediaPlayer();

        return match ($what) {
            'muted' => $player->audioOutput()->isMuted(),
            'loop' => $media->loops() === QMediaPlayer::INFINITE_LOOPS,
            'controls' => $player->controls()->isVisible(),
            'paused' => $media->playbackState() !== QMediaPlayer\PlaybackState::PLAYING,
            'position' => $media->position() / 1000,
        };
    }

    $expression = ['muted' => 'muted', 'loop' => 'loop', 'controls' => 'controls', 'paused' => 'paused', 'position' => 'currentTime'][$what];
    $result = null;
    $done = false;
    $player->page()->runJavaScript("window.__venusianVideo ? window.__venusianVideo.v.{$expression} : null", 0, function (mixed $value) use (&$result, &$done): void {
        [$result, $done] = [$value, true];
    });
    pumpUntil(function () use (&$done): bool {
        return $done;
    }, 5.0);

    return $result;
}

function qtLoaded(QtVideo $video): void
{
    expect(pumpUntil(fn (): bool => ! is_null($video->duration()), 10.0))->toBeTrue();
}

it('is the widget of its driver\'s player', function (string $driver): void {
    $video = qtVideo($driver);
    $player = $video->player();

    expect($player)->toBeInstanceOf(['qt' => MultimediaVideoPlayer::class, 'webview' => WebEngineVideoPlayer::class][$driver])
        ->and($video->native())->toBe($player->widget())
        ->and($video->supported())->toBeTrue()
        ->and($video->position())->toBe(0.0)
        ->and($video->duration())->toBeNull();
})->with('video drivers');

it('loads a file paused at its start, and reads its duration from the player', function (string $driver): void {
    $video = qtVideo($driver);
    $video->setSource(qtFixture('clip.mp4'));
    qtLoaded($video);
    pumpFor(0.5);

    expect($video->duration())->toEqualWithDelta(1.2, 0.1)
        ->and($video->position())->toBe(0.0)
        ->and($video->isPlaying())->toBeFalse()
        ->and(qtVideoMail(takeMail()))->toBe([])
        ->and(qtPlayerReads($video, 'paused'))->toBeTrue();
})->with('video drivers');

it('plays to the end: VideoPlaying, then VideoEnded', function (string $driver, string $source, float $length): void {
    $video = qtVideo($driver);
    $video->setMuted(true)->setSource(str_starts_with($source, 'http:') ? qtServed(substr($source, 5)) : qtFixture($source));
    qtLoaded($video);
    $video->play();
    $mail = mailUntil('VideoEnded', 10.0);

    expect(qtVideoMail($mail))->toBe([VideoPlaying::class, VideoEnded::class])
        ->and($video->duration())->toEqualWithDelta($length, 0.1)
        ->and($video->isPlaying())->toBeFalse();
})->with('video drivers')->with([
    'a file' => ['clip.mp4', 1.2],
    'an audio file' => ['tone.m4a', 1.0],
    'a clip over HTTP' => ['http:clip.mp4', 1.2],
    'audio over HTTP' => ['http:tone.m4a', 1.0],
]);

it('posts VideoPaused on pause, and seeks to the time asked for', function (string $driver): void {
    $video = qtVideo($driver);
    $video->setSource(qtFixture('tone.m4a'));
    qtLoaded($video);
    $video->setMuted(true)->play();
    mailUntil('VideoPlaying', 5.0);
    $video->pause();
    $paused = mailUntil('VideoPaused', 5.0);
    $video->seek(0.5);
    pumpUntil(fn (): bool => abs($video->position() - 0.5) < 0.01, 5.0);

    expect(countMail($paused, 'VideoPaused', 'video'))->toBe(1)
        ->and($video->isPlaying())->toBeFalse()
        ->and($video->position())->toEqualWithDelta(0.5, 0.01)
        ->and(qtPlayerReads($video, 'position'))->toEqualWithDelta(0.5, 0.01)
        ->and($video->isMuted())->toBeTrue();
})->with('video drivers');

it('hands muting, looping and controls to the player, set before the source and after it', function (string $driver): void {
    $video = qtVideo($driver);
    $video->setMuted(true)->setLoop(true)->setControls(true);
    $video->setSource(qtFixture('clip.mp4'));
    qtLoaded($video);
    $before = [qtPlayerReads($video, 'muted'), qtPlayerReads($video, 'loop'), qtPlayerReads($video, 'controls')];
    $video->setMuted(false)->setLoop(false)->setControls(false);
    pumpFor(0.1);
    $after = [qtPlayerReads($video, 'muted'), qtPlayerReads($video, 'loop'), qtPlayerReads($video, 'controls')];

    expect($before)->toBe([true, true, true])
        ->and($after)->toBe([false, false, false]);
})->with('video drivers');

it('loops with no VideoEnded', function (string $driver): void {
    $video = qtVideo($driver);
    $video->setMuted(true)->setLoop(true)->setSource(qtFixture('clip.mp4'));
    qtLoaded($video);
    $video->play();
    $mail = mailUntil('VideoEnded', 3.0);

    expect(qtVideoMail($mail))->toBe([VideoPlaying::class])
        ->and($video->isPlaying())->toBeTrue();
})->with('video drivers');

it('posts VideoFailed with a reason', function (string $driver, Closure $source): void {
    $video = qtVideo($driver);
    $video->setSource($source());
    $failed = array_values(array_filter(mailUntil('VideoFailed', 10.0), fn (object $m): bool => $m instanceof VideoFailed));
    pumpFor(0.5);
    $more = array_filter(takeMail(), fn (object $m): bool => $m instanceof VideoFailed);

    expect($failed)->toHaveCount(1)
        ->and($more)->toBe([])
        ->and($failed[0]->reason)->toBeString()->not->toBe('');
})->with('video drivers')->with([
    'a file that is not there' => fn () => '/nonexistent/qt-video-test.mp4',
    'a file that is not media' => fn () => qtFixture('serve.php'),
    'a server that refuses' => fn () => 'http://127.0.0.1:9/clip.mp4',
    'a page that is not there' => fn () => qtServed('nothing.mp4'),
]);

it('posts nothing for a source it moved on from', function (string $driver): void {
    $video = qtVideo($driver);
    $video->setMuted(true)->setSource(qtFixture('clip.mp4'));
    qtLoaded($video);
    $video->play();
    mailUntil('VideoPlaying', 5.0);
    $video->setSource(qtFixture('tone.m4a'));
    pumpFor(2.0);

    expect(qtVideoMail(takeMail()))->toBe([])
        ->and($video->duration())->toEqualWithDelta(1.0, 0.1);
})->with('video drivers');

it('posts nothing from a removed video', function (string $driver): void {
    $video = qtVideo($driver);
    $video->setMuted(true)->setSource(qtFixture('clip.mp4'));
    $video->play();
    $video->remove();
    pumpFor(1.5);

    expect(qtVideoMail(takeMail()))->toBe([]);
})->with('video drivers');

it('shows the control bar only while it has controls, and its buttons play and mute', function (): void {
    $video = qtVideo('qt');
    /** @var MultimediaVideoPlayer $player */
    $player = $video->player();
    $bar = $player->controls();
    $hidden = $bar->isVisible();
    $video->setControls(true);
    pumpFor(0.05);
    $shown = $bar->isVisible();
    $video->setSource(qtFixture('tone.m4a'));
    qtLoaded($video);

    [$toggle, $sound] = (fn (): array => [$this->toggle, $this->sound])->call($player);
    $sound->click();
    $toggle->click();
    $mail = mailUntil('VideoPlaying', 5.0);

    expect([$hidden, $shown])->toBe([false, true])
        ->and($video->isMuted())->toBeTrue()
        ->and(countMail($mail, 'VideoPlaying', 'video'))->toBe(1);
});

it('takes the muting its player\'s own controls make', function (): void {
    $video = qtVideo('webview', true);
    $video->setSource(qtFixture('clip.mp4'));
    qtLoaded($video);
    /** @var WebEngineVideoPlayer $player */
    $player = $video->player();
    $player->page()->runJavaScript('window.__venusianVideo.v.muted = true;');
    pumpUntil(fn (): bool => $video->isMuted(), 5.0);

    expect($video->isMuted())->toBeTrue()
        ->and(qtPlayerReads($video, 'muted'))->toBeTrue();
});

it('pauses when it stops being shown, as a container above it hides', function (string $driver): void {
    if (QGuiApplication::platformName() === 'offscreen') {
        test()->markTestSkipped("Qt's media backend loads nothing under the 'offscreen' platform.");
    }

    toolkitApp()->make('config')->set('qt.video.driver', $driver);
    $window = openWindow('video', 400, 300);
    $drawer = $window->column('root')->column('drawer')->fill();
    /** @var QtVideo $video */
    $video = $drawer->video('player')->fill();
    $video->setMuted(true)->setLoop(true)->setSource(qtFixture('clip.mp4'));
    qtLoaded($video);
    $video->play();
    mailUntil('VideoPlaying', 5.0);
    takeMail();
    $drawer->hide();
    $mail = mailUntil('VideoPaused', 3.0);

    expect(qtVideoMail($mail))->toBe([Surface\Contracts\Toolkits\Mail\Views\VideoPaused::class])
        ->and($video->isPlaying())->toBeFalse()
        ->and(qtPlayerReads($video, 'paused'))->toBeTrue();
})->with('video drivers');
