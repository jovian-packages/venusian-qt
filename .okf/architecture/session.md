---
type: Module
title: Session
description: QApplication session - construct once, stay up with no windows, budgeted dispatcher pump, deferred deletes, one-shot notifier wake.
resource: src/Bridge/QtSession.php
tags: [qt, bridge, linux, macos]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-01T20:06:29Z }
sources:
  - id: session
    resource: src/Bridge/QtSession.php
    title: QtSession
---

# Overview

`QtSession extends Surface\Bridge\BridgedToolkitSession`; built by `QtBridgeDriver::connect()` from [config](/api/config.md).[^session]

| Hook | Qt calls |
|---|---|
| initialize (once) | `new QApplication([argv0])`, `setApplicationName`, `setDesktopFileName`; single-shot `PRECISE_TIMER` for budgets |
| connect | `setQuitOnLastWindowClosed(false)` |
| disconnect | `setQuitOnLastWindowClosed(true)` (Qt default) |
| `pump($ns)` | budget > 0: start the timer (ceil ms) + one `processEvents(WAIT_FOR_MORE_EVENTS)` on the dispatcher, stop the timer; then up to `DRAIN_LIMIT` (64) `processEvents(ALL_EVENTS)` while they dispatch; then `sendPostedEvents(null, DEFERRED_DELETE)`; on macOS, then `QtCanvas::sweepSdlWindows()` |

Deferred deletes: Qt runs a `deleteLater()` posted outside any event handler only from `exec()`; a pumped app never enters it, so the pump asks for them at its own level. Covers replaced menu bars, released notifiers, app `deleteLater()` calls. Delete-on-close windows go through the same path.

Qt activates itself on `activateWindow()` on macOS; no ext-appkit needed.

# Wake

`QSocketNotifier(fd, READ)`; `activated` disables it, the next `pump()` re-enables (one-shot). Release: disable + `deleteLater()`.

# About

`showAbout(about, ?parent)`: one non-modal `QMessageBox` while open (again → update + raise), `DELETE_ON_CLOSE`, `destroyed` drops it. Title `About <name>`, text `<name> <version>`, informative text = copyright; name falls back to `applicationName()`. `aboutBox()` = the open one.

[^session]: QtSession
