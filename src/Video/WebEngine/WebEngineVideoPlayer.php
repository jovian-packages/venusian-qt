<?php

namespace Jovian\Toolkits\Qt\Video\WebEngine;

use QUrl;
use QObject;
use QWidget;
use WeakReference;
use QWebEngineView;
use QWebEngineScript;
use QWebEngineLoadingInfo;
use QWebEngineConsolePage;
use QMetaObject\Connection;
use QWebEngineScript\InjectionPoint;
use QWebEngineScript\ScriptWorldId;
use QWebEngineSettings\WebAttribute;
use QWebEngineLoadingInfo\LoadStatus;
use Jovian\Toolkits\Qt\Video\QtVideoPlayer;
use Jovian\Toolkits\Qt\Video\QtVideoListener;

/**
 * A QWebEngineView showing the media as its document: Chromium makes a media file (video or
 * audio) a page that holds one <video controls autoplay> element. Every source loads as the
 * page, a file on any volume and a stream alike.
 *
 * The page's own settings turn PlaybackRequiresUserGesture off: with Chromium's default policy
 * a script's play() with sound is refused. So that Chromium's autoplay does not start the media
 * then, a script at document creation takes the element as Chromium adds it: autoplay off,
 * paused, the muting, looping and controls of the load, the picture whole on black. A second
 * script at document ready reports a page with no element (an error page, a file that is not
 * media).
 *
 * The element's events come back as console messages (QWebEngineConsolePage), each a JSON line
 * behind a fixed prefix: playing, pause, ended and error go to the listener, a change of muting
 * too, and every report carries the position and the duration, which position() and duration()
 * read. A pause with the position at the duration is the end, not a pause. Each load has its
 * own number; a report from an earlier load, or a message without the prefix, is dropped. A
 * load reports one failure at most: an error page also has no element. Calls made before the
 * script has the element wait for it, then run through runJavaScript in the page's own world.
 *
 * The scripts are taken off the page one by one (remove()), never with clear(): Qt WebEngine's
 * clearAllScripts() drops the page's entry while the observer that reads it stays, and the
 * page's next frame then dereferences the missing entry (user_resource_controller_host.cpp,
 * RenderFrameCreated: a crash, measured on 6.11.2; the same code is on Qt's dev branch).
 *
 * Chromium ends every media document's navigation with LOAD_STOPPED net::ERR_ABORTED (-3), and
 * a new load aborts the one before: neither is a failure. Chromium also pauses video-only media
 * whose page is hidden, which is reported as a pause.
 */
final class WebEngineVideoPlayer implements QtVideoPlayer
{
    /** What a console line from this player's scripts starts with. */
    private const string PREFIX = 'venusian-video ';

    /** net::ERR_ABORTED. */
    private const int ABORTED = -3;

    private QWebEngineView $view;

    private QWebEngineConsolePage $page;

    private ?Connection $loading = null;

    /** @var list<QWebEngineScript> the scripts this player put on the page */
    private array $scripts = [];

    /** @var WeakReference<QtVideoListener> */
    private WeakReference $listener;

    /** The current load's number; reports carry it. */
    private int $load = 0;

    /** The current load has reported a failure. */
    private bool $failed = false;

    /** The script has the element: calls reach it. */
    private bool $ready = false;

    /** @var list<string> calls waiting for the element */
    private array $waiting = [];

    private float $at = 0.0;

    private ?float $length = null;

    private bool $released = false;

    public function __construct(QtVideoListener $listener)
    {
        $this->listener = WeakReference::create($listener);
        $this->view = new QWebEngineView();

        $self = WeakReference::create($this);
        $this->page = new QWebEngineConsolePage(static function ($level, string $message) use ($self): void {
            $self->get()?->heard($message);
        }, $this->view);
        $this->page->settings()->setAttribute(WebAttribute::PLAYBACK_REQUIRES_USER_GESTURE, false);
        $this->view->setPage($this->page);

        $this->loading = QObject::connect($this->page, 'loadingChanged(QWebEngineLoadingInfo)', static function (QWebEngineLoadingInfo $info) use ($self): void {
            $self->get()?->loadingChanged($info);
        });
    }

    public function widget(): QWidget
    {
        return $this->view;
    }

    /** The page the media shows in. */
    public function page(): QWebEngineConsolePage
    {
        return $this->page;
    }

    public function load(?string $url, bool $muted, bool $loop, bool $controls): void
    {
        $this->load++;
        $this->failed = false;
        $this->ready = false;
        $this->waiting = [];
        $this->at = 0.0;
        $this->length = null;

        $this->removeScripts();

        if (is_null($url)) {
            $this->view->setHtml('', new QUrl(''));

            return;
        }

        $this->scripts = [
            self::script('venusian-video-take', $this->takeScript($muted, $loop, $controls), InjectionPoint::DOCUMENT_CREATION),
            self::script('venusian-video-check', $this->checkScript(), InjectionPoint::DOCUMENT_READY),
        ];
        foreach ($this->scripts as $script) {
            $this->page->scripts()->insert($script);
        }
        $this->view->load(new QUrl($url));
    }

    public function play(): void
    {
        // A play() that a pause() interrupts rejects with AbortError: that is not a failure.
        $this->tell('p.v.play().catch(function(e){if(e.name!=="AbortError"){p.say("error",e.message||String(e));}});');
    }

    public function pause(): void
    {
        $this->tell('p.v.pause();');
    }

    public function seek(float $seconds): void
    {
        $this->at = $seconds;
        $this->tell('p.v.currentTime='.json_encode($seconds).';');
    }

    public function setMuted(bool $muted): void
    {
        $this->tell('p.v.muted='.json_encode($muted).';');
    }

    public function setLoop(bool $loop): void
    {
        $this->tell('p.v.loop='.json_encode($loop).';');
    }

    public function setControls(bool $controls): void
    {
        $this->tell('p.v.controls='.json_encode($controls).';');
    }

    public function position(): float
    {
        return $this->at;
    }

    public function duration(): ?float
    {
        return $this->length;
    }

    /**
     * Messages are ignored first, so the page reports nothing more; then the scripts, the load
     * and the page.
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        $this->waiting = [];
        if (! is_null($this->loading)) {
            QObject::disconnect($this->loading);
            $this->loading = null;
        }
        $this->removeScripts();
        $this->view->stop();
        $this->view->setHtml('', new QUrl(''));
    }

    /**
     * One console line. A report reads {"load": n, "event": ..., "position": s, "duration":
     * s|null, "muted": bool, "error": ...}; one from an earlier load is dropped.
     */
    private function heard(string $message): void
    {
        if ($this->released || ! str_starts_with($message, self::PREFIX)) {
            return;
        }

        $report = json_decode(substr($message, strlen(self::PREFIX)), true);
        if (! is_array($report) || ($report['load'] ?? null) !== $this->load || ! is_string($report['event'] ?? null)) {
            return;
        }

        $position = $report['position'] ?? null;
        if (is_int($position) || is_float($position)) {
            $this->at = max(0.0, (float) $position);
        }
        $duration = $report['duration'] ?? null;
        $this->length = (is_int($duration) || is_float($duration)) && $duration > 0 ? (float) $duration : null;

        $listener = $this->listener->get();
        match ($report['event']) {
            'ready' => $this->took(),
            'playing' => $listener?->videoPlaying(),
            'pause' => $listener?->videoPaused(),
            'ended' => $listener?->videoEnded(),
            'volume' => $listener?->videoMuted((bool) ($report['muted'] ?? false)),
            'error' => $this->fail(is_string($report['error'] ?? null) && $report['error'] !== '' ? $report['error'] : null),
            default => null,
        };
    }

    private function loadingChanged(QWebEngineLoadingInfo $info): void
    {
        if ($this->released || $info->status() !== LoadStatus::LOAD_FAILED_STATUS || $info->errorCode() === self::ABORTED) {
            return;
        }

        $this->fail($info->errorString() !== '' ? $info->errorString() : null);
    }

    /** The current load's one failure. */
    private function fail(?string $reason): void
    {
        if ($this->failed) {
            return;
        }

        $this->failed = true;
        $this->listener->get()?->videoFailed($reason);
    }

    /** The script has the element: what waited runs now. */
    private function took(): void
    {
        $this->ready = true;
        [$calls, $this->waiting] = [$this->waiting, []];
        foreach ($calls as $call) {
            $this->run($call);
        }
    }

    /** Run $call on the element now, or once the script has it. */
    private function tell(string $call): void
    {
        if ($this->released) {
            return;
        }

        if ($this->ready) {
            $this->run($call);
        } else {
            $this->waiting[] = $call;
        }
    }

    private function run(string $call): void
    {
        $this->page->runJavaScript('(function(p){if(p){'.$call.'}})(window.__venusianVideo);', ScriptWorldId::MAIN_WORLD->value);
    }

    /** Take this player's scripts off the page, each by itself. */
    private function removeScripts(): void
    {
        foreach ($this->scripts as $script) {
            $this->page->scripts()->remove($script);
        }
        $this->scripts = [];
    }

    private static function script(string $name, string $source, InjectionPoint $at): QWebEngineScript
    {
        $script = new QWebEngineScript();
        $script->setName($name);
        $script->setSourceCode($source);
        $script->setInjectionPoint($at);
        $script->setWorldId(ScriptWorldId::MAIN_WORLD->value);
        $script->setRunsOnSubFrames(false);

        return $script;
    }

    /**
     * The document-creation script: takes the element once, applies the load's settings, styles
     * the page, reports the element's events, and reports "ready".
     */
    private function takeScript(bool $muted, bool $loop, bool $controls): string
    {
        return '(function(){var load='.$this->load.',prefix='.json_encode(self::PREFIX).';'
            .'function take(v){if(v.__venusian){return;}v.__venusian=true;'
            .'function say(e,x){var d=v.duration;console.log(prefix+JSON.stringify({load:load,event:e,position:v.currentTime,duration:isFinite(d)?d:null,muted:v.muted,error:x||null}));}'
            .'v.autoplay=false;v.removeAttribute("autoplay");v.pause();v.muted='.json_encode($muted).';v.loop='.json_encode($loop).';v.controls='.json_encode($controls).';'
            .'v.style.cssText="position:fixed;left:0;top:0;width:100%;height:100%;max-width:none;max-height:none;margin:0;object-fit:contain;background:#000";'
            .'document.documentElement.style.background="#000";if(document.body){document.body.style.margin="0";document.body.style.background="#000";}'
            .'["playing","ended","loadedmetadata","durationchange","timeupdate","seeked"].forEach(function(e){v.addEventListener(e,function(){say(e);});});'
            .'v.addEventListener("volumechange",function(){say("volume");});'
            .'v.addEventListener("pause",function(){if(!v.ended&&!(v.currentTime>=v.duration)){say("pause");}});'
            .'v.addEventListener("error",function(){var m=v.error;say("error",m?(m.message||("Media error "+m.code+".")):null);});'
            .'window.__venusianVideo={v:v,say:say};say("ready");}'
            .'var found=document.querySelector("video");if(found){take(found);return;}'
            .'new MutationObserver(function(l,o){var v=document.querySelector("video");if(v){o.disconnect();take(v);}}).observe(document,{childList:true,subtree:true});})();';
    }

    /**
     * The document-ready script: a page with no element is a source Chromium does not play as
     * media.
     */
    private function checkScript(): string
    {
        return '(function(){if(!document.querySelector("video")){console.log('.json_encode(self::PREFIX).'+JSON.stringify({load:'.$this->load.',event:"error",position:0,duration:null,'
            .'error:"Chromium shows this source as "+(document.contentType||"a page")+", not as audio or video."}));}})();';
    }
}
