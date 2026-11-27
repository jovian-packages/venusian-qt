---
type: Reference
title: Config
description: config/qt.php keys read by the driver and the toolkit.
resource: config/qt.php
tags: [qt, config]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-21T17:39:27Z }
sources:
  - id: config
    resource: config/qt.php
    title: config/qt.php
  - id: driver
    resource: src/QtAdapterDriver.php
    title: QtAdapterDriver
---

# Schema

Merged under `qt` by `VenusianQtServiceProvider` and published as `config/qt.php` (tag `qt-config`). The toolkit is chosen by Topside's `toolkits.default` (`TOOLKIT_DRIVER=qt`).[^config]

| Key | Default | Meaning |
|---|---|---|
| `qt.application_id` | `env('APP_ID', 'com.venusian.qt')` | `QGuiApplication::setDesktopFileName`; the driver throws without one |
| `qt.application_name` | `env('APP_NAME', 'Venusian')` | `QCoreApplication::setApplicationName` |
| `qt.loop_drain_limit` | `64` | `processEvents` calls one pump makes after its wait ([session](/architecture/session.md)) |
| `qt.video.driver` | `qt` | the player of a video view: `qt` (Qt Multimedia) or `webview` (Qt WebEngine), or a name added with `QtAdapterDriver::video()->extend()` ([video](/design/video.md)) |

[^config]: config/qt.php
