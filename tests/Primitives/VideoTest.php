<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Primitives\QtVideo;
use Surface\Contracts\Windows\Mail\View\VideoEnded;
use Surface\Contracts\Windows\Mail\View\VideoFailed;
use Surface\Contracts\Windows\Mail\View\VideoPaused;
use Surface\Contracts\Windows\Mail\View\VideoPlaying;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

// Leave Qt quiet for the files after this one: an open window keeps posting layout and paint work.
afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.1);
    takeMail(session());
});

/**
 * Pump and collect mail until one of class $until arrives or $seconds pass.
 * @param class-string $until
 * @return list<object>
 */
function mailUntil(string $until, float $seconds): array
{
    $mail = [];
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        pumpFor(0.05);
        $mail = [...$mail, ...takeMail(session())];
        if (array_filter($mail, fn (object $m): bool => $m instanceof $until) !== []) {
            break;
        }
    }

    return $mail;
}

it('plays a clip to the end and reports a missing file', function (): void {
    $window = driver()->open('main', 200, 200);
    $video = $window->column('m')->video('v', __DIR__.'/../fixtures/clip.mp4');
    $video->setMuted(true);
    $window->present();
    $video->play();
    $mail = mailUntil(VideoEnded::class, 8.0);
    $classes = array_map(fn (object $m): string => $m::class, $mail);

    expect($video)->toBeInstanceOf(QtVideo::class)
        ->and($video->native())->toBeInstanceOf(QVideoWidget::class)
        ->and($classes)->toContain(VideoPlaying::class, VideoEnded::class)
        ->and(array_search(VideoPlaying::class, $classes, true))->toBeLessThan(array_search(VideoEnded::class, $classes, true))
        ->and($mail)->toContainEqual(new VideoEnded('main', 'm.v', $video->uuid()))
        ->and($video->duration())->toBeGreaterThan(0.9)
        ->and($video->isPlaying())->toBeFalse()
        ->and($video->isMuted())->toBeTrue();

    $video->setFile('/nope/clip.mp4');
    $video->play();
    $after = mailUntil(VideoFailed::class, 5.0);
    $failed = array_values(array_filter($after, fn (object $m): bool => $m instanceof VideoFailed));
    // A file that fails never plays.
    expect(array_filter($after, fn (object $m): bool => $m instanceof VideoPlaying))->toBe([])
        ->and($failed)->not->toBe([])
        ->and($failed[0]->path())->toBe('m.v')
        ->and($failed[0]->reason)->not->toBe('')
        ->and($video->isPlaying())->toBeFalse();
});

it('pauses, seeks and loops on request', function (): void {
    $window = driver()->open('main', 200, 200);
    $video = $window->column('m')->video('v', __DIR__.'/../fixtures/clip.mp4');
    $video->setMuted(true)->setLoop(true);
    $window->present();
    $video->play();
    mailUntil(VideoPlaying::class, 5.0);

    expect($video->isPlaying())->toBeTrue()
        ->and($video->isLooping())->toBeTrue()
        ->and($video->player()->playbackState())->toBe(QMediaPlayer\PlaybackState::PLAYING);

    $video->pause();
    $paused = mailUntil(VideoPaused::class, 2.0);
    expect($paused)->toContainEqual(new VideoPaused('main', 'm.v', $video->uuid()))
        ->and($video->isPlaying())->toBeFalse();

    $video->seek(0.5);
    $deadline = microtime(true) + 2.0;
    while ($video->position() < 0.5 && microtime(true) < $deadline) {
        pumpFor(0.05);
    }
    expect($video->position())->toBeGreaterThanOrEqual(0.5)
        ->and($video->position())->toBeLessThan(1.0);

    // Looping: playing past the end keeps playing and never reports the end.
    $video->play();
    $mail = mailUntil(VideoEnded::class, 2.5);
    expect(array_filter($mail, fn (object $m): bool => $m instanceof VideoEnded))->toBe([])
        ->and($video->isPlaying())->toBeTrue();
});

it('refuses video when Qt has no media backend', function (): void {
    $script = __DIR__.'/../fixtures/video-without-backend.php';
    exec('QT_MEDIA_BACKEND=none '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>/dev/null', $output, $code);

    // The refusal, then the column's children: none, nothing was adopted.
    expect($code)->toBe(0)
        ->and($output)->toBe(['TKVideo needs a Qt Multimedia backend plugin (Debian: libqt6multimedia6; Homebrew: qt).', '']);
})->skip(PHP_OS_FAMILY === 'Darwin', 'macOS Qt falls back to its one media backend whatever QT_MEDIA_BACKEND names');
