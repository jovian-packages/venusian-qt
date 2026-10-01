---
type: Runbook
title: Testing
description: Pest suite against the real toolkit in a workbench of path repos; Mac and Pi.
resource: tests/
tags: [qt, pest]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-01T20:06:29Z }
sources:
  - id: pest
    resource: tests/Pest.php
    title: tests/Pest.php
---

# Overview

Suite drives the real toolkit: windows appear on screen. One driver per process (one application per process), built in `tests/Pest.php` with a `ControlPanel` container holding `config`, `toolkit-bridge`, `toolkit-windows`; helpers `driver()`, `session()`, `takeMail()` (empties the outbox), `pumpFor($seconds)`.[^pest]

Covers: session lifecycle, budgeted wait, loop join (kqueue/epoll fd ends the toolkit sleep), open/close/focus mail, menu build, item/toggle/quit mail, About, default bar, profile swap.

Workbench (never the repo root):

1. Copy the package (no vendor) to a scratch dir.
2. Path repositories, symlinked: `<framework>/src/Voyager/*`, `<surface>/src/Surface/*`.
3. `composer install`; `php84 vendor/bin/pest` and `zhp vendor/bin/pest`.
4. Pi: copy package + Surface + Voyager sources, path repos relative, run with `WAYLAND_DISPLAY=wayland-0 DISPLAY=:0 XDG_RUNTIME_DIR=/run/user/1000 DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/1000/bus`. Announce with `say` before windows light the panel. macOS-only tests skip on Linux.

Delete the workbench (and Pi copy) after.

[^pest]: tests/Pest.php
