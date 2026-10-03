---
type: Runbook
title: Testing
description: Pest suite against the real toolkit in a workbench of path repos; Mac and Pi.
resource: tests/
tags: [qt, pest]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-02T22:59:16Z }
sources:
  - id: pest
    resource: tests/Pest.php
    title: tests/Pest.php
---

# Overview

Suite drives the real toolkit: windows appear on screen. One driver per process (one application per process), built in `tests/Pest.php` with a `ControlPanel` container holding `config`, `toolkit-bridge`, `toolkit-windows`; helpers `driver()`, `session()`, `takeMail()` (empties the outbox), `pumpFor($seconds)` (pumps through `ToolkitPump`, so latest mail is flushed after each pump as the loop does; a raw `session()->pump()` leaves it pending).[^pest]

Covers: session lifecycle, budgeted wait, loop join (kqueue/epoll fd ends the toolkit sleep), open/close/focus mail, menu build, item/toggle/quit mail, About, default bar, profile swap; every primitive (`tests/Primitives/`): containers, leaves, resize mail, table, video (`tests/fixtures/clip.mp4`, 1.2 s silent H.264), the no-backend refusal (child process with `QT_MEDIA_BACKEND=none`; Linux only, macOS Qt falls back to its one backend).

`LifetimeTest` removes every kind and closes a window, then pumps and collects until a `WeakReference` clears (Qt deletes a widget's signal slots one deferred pass after the widget, so a freed object can take a few pumps; a cycle never clears). `pendingLatest()` reads the session's held latest-mail keys, so a raw-pump test can prove the mail it expects dropped was pending.

Primitive test files close every window `afterEach`: an open, freshly changed window keeps Qt busy and breaks the session test's "waits at most the budget". Input-only signals are driven with `QMetaObject::invokeMethod($widget, 'returnPressed')`; the popup calendar's `clicked(QDate)` with `invokeMethod($calendar, 'clicked', 'YYYY-MM-DD')`; the suite needs ext-qt at `ca51d53` or later (invokeMethod arguments, stretch factors, `QSpacerItem`, text format, slider steps, DPR scaling, date edit calendar and range, `selectedItems`, `clearSelection`).

Workbench (never the repo root):

1. Copy the package (no vendor) to a scratch dir.
2. Path repositories, symlinked: `<framework>/src/Voyager/*`, `<surface>/src/Surface/*`.
3. `composer install`; `php84 vendor/bin/pest` and `zhp vendor/bin/pest`.
4. Pi: copy package + Surface + Voyager sources, path repos relative, run with `WAYLAND_DISPLAY=wayland-0 DISPLAY=:0 XDG_RUNTIME_DIR=/run/user/1000 DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/1000/bus`. Announce with `say` before windows light the panel. macOS-only tests skip on Linux.

Delete the workbench (and Pi copy) after.

[^pest]: tests/Pest.php
