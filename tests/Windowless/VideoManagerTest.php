<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Video\QtVideoDriver;
use Jovian\Toolkits\Qt\Video\QtVideoPlayer;
use Jovian\Toolkits\Qt\Video\QtVideoListener;
use Jovian\Toolkits\Qt\Video\QtVideoManager;
use Jovian\Toolkits\Qt\Video\Multimedia\MultimediaVideoDriver;
use Jovian\Toolkits\Qt\Video\Multimedia\MultimediaVideoPlayer;
use Jovian\Toolkits\Qt\Video\WebEngine\WebEngineVideoDriver;
use Jovian\Toolkits\Qt\Video\WebEngine\WebEngineVideoPlayer;
use Voyager\Config\Repository;
use Voyager\Core\RenderedInstance;

/*
| The video manager: which driver a video's player comes from.
*/

/** A manager on a core whose qt.video.driver is $driver. */
function qtVideoManager(string $driver): QtVideoManager
{
    $app = new RenderedInstance(sys_get_temp_dir());
    $app->registerInstance('config', new Repository(['qt' => ['video' => ['driver' => $driver]]]));
    $manager = new QtVideoManager($app);
    toolkitApp();

    return $manager;
}

function qtSilentListener(): QtVideoListener
{
    return new class implements QtVideoListener {
        public function videoPlaying(): void {}

        public function videoPaused(): void {}

        public function videoEnded(): void {}

        public function videoFailed(?string $reason): void {}

        public function videoMuted(bool $muted): void {}
    };
}

it('makes the driver qt.video.driver names, once, and one manager for the toolkit', function (): void {
    $qt = qtVideoManager('qt');
    $web = qtVideoManager('webview');

    expect($qt->driver())->toBeInstanceOf(MultimediaVideoDriver::class)
        ->and($qt->driver())->toBe($qt->driver('qt'))
        ->and($web->driver())->toBeInstanceOf(WebEngineVideoDriver::class)
        ->and(driver()->video())->toBe(driver()->video());
});

it('takes the default from qt.video.driver', function (): void {
    $manager = qtVideoManager('elsewhere');
    $driver = new class implements QtVideoDriver {
        public function player(QtVideoListener $listener): QtVideoPlayer
        {
            throw new LogicException('not used');
        }

        public function supported(): bool
        {
            return false;
        }
    };
    $manager->extend('elsewhere', fn () => $driver);

    expect($manager->driver())->toBe($driver)
        ->and($manager->driver()->supported())->toBeFalse();
});

it('refuses a driver it does not know', function (): void {
    expect(fn () => qtVideoManager('nothing')->driver())->toThrow(InvalidArgumentException::class, 'Driver [nothing] not supported.');
});

it('gives each built-in driver its player, and says whether it plays', function (): void {
    // A Qt application first, as every widget needs: this suite's, or the driver's own.
    is_null(QCoreApplication::instance()) && driver()->connect();
    $qt = driver()->video()->driver('qt');
    $web = driver()->video()->driver('webview');
    $media = $qt->player(qtSilentListener());
    $page = $web->player(qtSilentListener());

    expect($media)->toBeInstanceOf(MultimediaVideoPlayer::class)
        ->and($media->widget())->toBeInstanceOf(QWidget::class)
        ->and($media->picture())->toBeInstanceOf(QVideoWidget::class)
        ->and($qt->supported())->toBe(new QMediaPlayer()->isAvailable())
        ->and($web)->toBeInstanceOf(WebEngineVideoDriver::class)
        ->and($page)->toBeInstanceOf(WebEngineVideoPlayer::class)
        ->and($page->widget())->toBeInstanceOf(QWebEngineView::class)
        ->and($page->page()->settings()->testAttribute(QWebEngineSettings\WebAttribute::PLAYBACK_REQUIRES_USER_GESTURE))->toBeFalse()
        ->and($web->supported())->toBeTrue();
    $media->release();
    $page->release();
});
