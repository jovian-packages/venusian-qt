---
type: Design
title: Video
description: The design for TKVideo on Qt - a video manager with drivers inside this package, the 'qt' driver (QMediaPlayer, today's QtVideo moved into a driver) as the default, the 'webview' driver (QWebEngineView showing the media as its document), the three ext-qt bindings it needs, what was measured, and the build stages.
tags: [qt, toolkits, video, design]
status: approved
generated: { by: claude-opus/5.5, at: 2026-11-27T16:00:00Z }
sources:
  - id: tkvideo
    resource: ../../venusian/topside/src/Surface/Toolkits/UI/TKVideo.php
    title: TKVideo (the hooks a toolkit fills in)
  - id: manager
    resource: ../../venusian/framework/src/Voyager/NutsAndBolts/Manager.php
    title: Manager (driver(), create{Name}Driver(), extend())
  - id: qtvideo
    resource: src/UI/QtVideo.php
    title: QtVideo today (QMediaPlayer, QAudioOutput, QVideoWidget, Qt's theme icons)
  - id: qtwebview
    resource: src/UI/QtWebView.php
    title: QtWebView (QWebEngineView, loadingChanged)
  - id: appkit-design
    resource: ../venusian-appkit/.okf/design/video.md
    title: The AppKit video design (the same manager and interfaces, built 2026-11-27)
  - id: ext-webengine
    resource: ../../php-io-extensions/qt/stubs/QWebEngine.stub.php
    title: ext-qt WebEngine surface (runJavaScript 0614261, QWebEngineConsolePage and setPage aaf9851)
  - id: headers
    resource: /opt/homebrew/opt/qtwebengine/lib/QtWebEngineCore.framework/Headers (Qt 6.11.2)
    title: qwebenginesettings.h, qwebenginescript.h, qwebenginescriptcollection.h, qwebenginepage.h
  - id: debian
    resource: https://salsa.debian.org/qt-kde-team/qt6/qt6-webengine/-/raw/master/debian/rules
    title: Debian's Qt 6 WebEngine build (-DFEATURE_webengine_proprietary_codecs=ON)
  - id: probes
    resource: probe runs 2026-11-27, PHP 8.4 NTS, ext-qt 0.11.2, Qt 6.11.2 (Homebrew), macOS 15.4.1
    title: QWebEngineView media probes
---

# Status: approved, built

Angel approved this document on 2026-11-27. Built: ext-qt 18395eb (settings, scripts) and 87c7cc8 (`QMediaPlayer::loops()`, which the tests read); stargazer2 checked by Angel on both drivers.

**Built differently from the draft:** the scripts are taken off the page with `QWebEngineScriptCollection::remove()`, one by one, never `clear()`. Qt WebEngine's `clearAllScripts()` (`user_resource_controller_host.cpp`) drops the page's entry from `m_perContentsScripts` while its `WebContentsObserverHelper` stays, and the page's next frame then dereferences the missing entry in `RenderFrameCreated()`: a segfault, measured on 6.11.2 (clear, then any navigation); the same code is on Qt's dev branch. `removeUserScript()` keeps the entry. The listener also has `videoMuted(bool)`: the muting the player's own transport makes (the `qt` bar's sound button, the element's `volumechange`) becomes the view's.

# What the author gets

`<Video>` works on Qt the way it does today, through a player the app picks with one config value: `qt` (the default, Qt Multimedia) or `webview` (Qt WebEngine). A source that is an absolute file path, a `file://` URL or an `https://` URL plays, with the mail and calls `TKVideo` already defines. Nothing changes in topside or the framework. ext-qt gets three bindings, all for the `webview` driver.

# Manager and drivers

The same shape as AppKit's, in this package's own classes. The composition asks for a `TKVideo`; `QtVideo` asks the package's video manager for the loaded driver, and the driver makes the player.

| Piece | File | What it is |
|---|---|---|
| `QtVideo` | `src/UI/QtVideo.php` | The one `TKVideo` of this package (already in the primitive map). It keeps no player code: every `apply*` and `read*` hook forwards to its player, and `supported()` asks the driver. |
| `QtVideoManager` | `src/Video/QtVideoManager.php` | Extends the framework's `Manager`. `getDefaultDriver()` reads `qt.video.driver`. `createQtDriver()`, `createWebviewDriver()`; `extend()` for more. |
| `QtVideoDriver` | `src/Video/QtVideoDriver.php` | Interface: `player(QtVideoListener $listener): QtVideoPlayer`, and `supported(): bool`. A driver checks what it needs when it is made and throws with the fix. |
| `QtVideoPlayer` | `src/Video/QtVideoPlayer.php` | Interface, one per view: `widget(): QWidget`, `load(?string $url, bool $muted, bool $loop, bool $controls)`, `play()`, `pause()`, `seek(float)`, `setMuted(bool)`, `setLoop(bool)`, `setControls(bool)`, `position(): float`, `duration(): ?float`, `release()`. |
| `QtVideoListener` | `src/Video/QtVideoListener.php` | Interface the player reports to: `videoPlaying()`, `videoPaused()`, `videoEnded()`, `videoFailed(?string)`. `QtVideo` implements it. |
| `MultimediaVideoDriver`, `MultimediaVideoPlayer` | `src/Video/Multimedia/` | The `qt` driver: today's `QtVideo` code, moved. |
| `WebEngineVideoDriver`, `WebEngineVideoPlayer` | `src/Video/WebEngine/` | The `webview` driver. |

`supported()` is on the Qt driver interface because Qt Multimedia can be installed with no media backend plugin: today `QtVideo::supported()` is `QMediaPlayer::isAvailable()`, and that answer moves into the `qt` driver. The `webview` driver answers true: it is made only when ext-qt has `QWebEngineView`.

How the view reaches the manager: `QtAdapterDriver::video(): QtVideoManager`, built once with the framework core; `QtWindow::adapter()` gives a primitive its window's driver.

Config, in `config/qt.php` (documented in [Config](/api/config.md)):

```php
'video' => [
    'driver' => 'qt',
],
```

Why `qt` is the default: it is Qt's own player, it needs no Chromium in the process, and it is in every Qt build that has Qt Multimedia, Linux included. `webview` is the choice where Qt Multimedia has no backend.

# The qt driver

Today's `QtVideo`, moved behind the interface, with no change in what it does: a host widget holding a `QVideoWidget` fed by a `QMediaPlayer` and its `QAudioOutput`, above a bar of Qt's theme icons (play or pause, a position slider, the time, the sound) shown while the view has controls. Every rule in `QtVideo`'s comment moves with it: paused once loaded and at the end (a stopped player shows no frame), playing only in the playing state with media it can play, the stopped state never a pause, the end then a pause on the last frame, `play()` after the end from the start, errors with Qt's message. Seeks are `setPosition()` in milliseconds.

# The webview driver

One `QWebEngineView` per player, with its own `QWebEngineConsolePage`. The media is the page: Chromium makes a media file (video or audio) a document holding `<video controls autoplay name="media">` (measured, a file and NASA's https mp4). Every source loads with `QWebEngineView::load()`.

Why the media is the page here too: it plays every source with one path (a file on any volume, a stream), and writes nothing next to the user's media.

## Before the load

* The page's settings turn `PlaybackRequiresUserGesture` off. Measured: with it on (Qt's default), `play()` from a script is refused, "NotAllowedError: play() failed because the user didn't interact with the document first". The setting is the page's own, so no other web view changes.
* A `QWebEngineScript` at `DocumentCreation` in the main world takes the `<video>` Chromium adds (now, or by a `MutationObserver`), once: `autoplay` off, paused, muted, loop and controls from the load, the picture whole on black, the events reported. With the gesture rule off, Chromium's `autoplay` would start the media before a script run after the load could stop it: the script has to run when the document is made, which is what `DocumentCreation` is. A second script at `DocumentReady` reports a page with no element (a 404 page, a file that is not media).
* Each `load()` clears the page's scripts and inserts both again, with the load's number.

## Reports and calls

* Reports come back as console messages through `QWebEngineConsolePage`, each a JSON line behind a fixed prefix, with the load's number. A message without the prefix, or from an earlier load, is dropped. `playing`, `pause`, `ended` and `error` go to the listener; every report carries the position and the duration.
* Why the console page and not `QWebChannel`: `QWebChannel` hands the page a `QObject`'s properties, slots and signals; a PHP closure is none of those, so reaching PHP would mean writing a registered object's `objectName` from the page to fire `objectNameChanged` - a channel built on a side effect. `javaScriptConsoleMessage()` is the virtual Qt calls for each message a page writes, it is bound, and it has no setup in the page.
* Calls run with `QWebEnginePage::runJavaScript()` once the script reports it has the element; before that they wait.
* A pause before the end with the position at the duration is dropped, as on AppKit.

## Failures

| What happens | Result |
|---|---|
| `loadingChanged` with LOAD_FAILED (no server, no file) | `videoFailed()` with the `errorString()` |
| A document with no `<video>` | the DocumentReady script reports it: `videoFailed()` |
| The element's `error` event (cannot decode) | `videoFailed()` with its message |
| LOAD_STOPPED with `net::ERR_ABORTED` (-3) | not a failure: Chromium ends every media document's navigation with it (measured, file and https), and a new `load()` aborts the one before |

## Release

The console callable is dropped first (the player keeps a flag that ignores every message after it), the scripts are cleared, the load is stopped and an empty page loaded, so a removed view posts nothing.

## Visibility

Chromium pauses video-only media whose page is hidden (measured on a view never shown: "AbortError: The play() request was interrupted because video-only background media was paused to save power"). That pause is reported as a pause. A view on screen plays.

## Verification when the driver is made

The driver needs `QWebEngineView`, `QWebEngineConsolePage`, `QWebEngineSettings` and `QWebEngineScript`. When one is missing it throws and names the ext-qt version to install.

# ext-qt: three bindings to add

Each is a real Qt 6 member (Qt 6.11.2 headers):

| Binding | Header | Why |
|---|---|---|
| `QWebEnginePage::settings()`, `QWebEngineSettings::setAttribute()` and `testAttribute()`, the `QWebEngineSettings::WebAttribute` enum | qwebenginepage.h:292, qwebenginesettings.h:31, 104, 105 | `PlaybackRequiresUserGesture` off for the player's page |
| `QWebEngineScript`: constructor, `setName()`, `setSourceCode()`, `setInjectionPoint()`, `setWorldId()`, `setRunsOnSubFrames()`, the `InjectionPoint` and `ScriptWorldId` enums | qwebenginescript.h:32, 40, 48, 55, 61, 64, 67, 70 | The script that runs when the document is made |
| `QWebEnginePage::scripts()`, `QWebEngineScriptCollection::insert()`, `remove()`, `clear()`, `count()` | qwebenginepage.h:291, qwebenginescriptcollection.h:22, 25, 27, 28 | Putting the scripts on the page, and taking them off at each load |

# Measured

Probe runs on 2026-11-27 (Qt WebEngine 6.11.2 from Homebrew, macOS 15.4.1). The clip is the 1.2 s fixture `clip.mp4` (H.264), the audio `tone.m4a` (AAC), NASA's https file the APOD mp4 of 2026-07-08.

| Case | Result |
|---|---|
| H.264 and AAC | `canPlayType('video/mp4; codecs="avc1.42E01E, mp4a.40.2"')` is "probably"; the clip and the tone load to `readyState` 4 |
| The media as the page: a file on the external volume, NASA's https mp4 | a media document, `<video controls autoplay name="media">`, paused (the gesture rule stopped its autoplay); the navigation ends LOAD_STOPPED -3 `net::ERR_ABORTED` |
| `play()` from `runJavaScript`, sound on | refused: NotAllowedError (the user-gesture rule) |
| `play()` from `runJavaScript`, muted, view not shown | refused: AbortError (video-only background media paused to save power) |
| Seek to 0.95 s | reads 0.95 s |

On Linux, Debian's (and so Ubuntu's) Qt 6 WebEngine is built with `-DFEATURE_webengine_proprietary_codecs=ON`: H.264 and AAC decode there too.

# Tests

`tests/Integration/VideoTest.php` runs every case once for each driver, `qt` and `webview`, reading the player's own state (the `QMediaPlayer`, or the element through `runJavaScript`). Today's six cases stay, and the AppKit set joins them: a file, the audio and both over HTTP to the end; paused until `play()`; an exact seek; muting, looping and controls before and after the source; looping with no end; no controls while it takes no input; four failures with a reason; a source left behind; a removed view. The bar's buttons stay a `qt` driver case. `tests/Windowless/VideoManagerTest.php`: the default is `qt`, `webview` gives the WebEngine driver, an unknown name throws, `extend()`, and `supported()` from the driver. ext-qt has a case for each new binding. Both run on Cocoa, NTS and ZTS; offscreen the player cases skip, as today's do.

# Stages

1. The three ext-qt bindings, with tests.
2. The manager, the three interfaces, `QtVideo` on them, the config key, `QtAdapterDriver::video()` and `QtWindow::adapter()`.
3. The `qt` driver: today's code moved; today's six tests pass unchanged on it.
4. The `webview` driver and the tests on both drivers.
5. The README line, this bundle's UI primitives page, config page and log.
6. stargazer2 with `TOOLKIT_DRIVER=qt`, on each driver.

# Not in this work

* A mail that says "ready to play" (for a loading face): its own topside design, as on AppKit.
