---
type: Module
title: Session
description: QApplication built once, kept up with no windows, a budgeted dispatcher pump with deferred deletes, and a one-shot notifier wake.
resource: src/Qt.php
tags: [qt, session, linux, macos]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-26T05:30:00Z }
sources:
  - id: qt
    resource: src/Qt.php
    title: Qt (the Toolkit)
  - id: session
    resource: src/QtSession.php
    title: QtSession
  - id: driver
    resource: src/QtAdapterDriver.php
    title: QtAdapterDriver
---

# Overview

Three classes, on Topside's toolkit layer:

| Class | Extends / implements | Job |
|---|---|---|
| `QtAdapterDriver` | `Surface\Toolkits\Drivers\ToolkitAdapterDriver` | refuses without ext-qt; makes the session from [config](/api/config.md); declares `QtWindow` as its window class |
| `QtSession` | `Surface\Toolkits\ToolkitSession` | builds the `Qt` toolkit once, with the application and desktop file names and the budget timer; then `applyColorScheme()` calls `QStyleHints::setColorScheme()` (`Qt\ColorScheme` dark or light) or `unsetColorScheme()` for system (Qt 6.8; needs ext-qt d8a404d) |
| `Qt` | `Surface\Contracts\Toolkits\Toolkit` | the Qt calls below |

The window list and the after-pump settle are Topside's (`ToolkitSession`).[^session]

# Calls

| When | Qt calls |
|---|---|
| built (once per process) | `new QApplication([argv0])`, `QCoreApplication::setApplicationName`, `QGuiApplication::setDesktopFileName`, a single-shot `PRECISE_TIMER` for budgets |
| `connect()` | `setQuitOnLastWindowClosed(false)` |
| `disconnect()` | `setQuitOnLastWindowClosed(true)` (Qt's default) |
| `pump($ns)` | budget > 0: start the timer (ceil ms), one `processEvents(WAIT_FOR_MORE_EVENTS)` on the dispatcher, stop the timer. Then up to `qt.loop_drain_limit` (64) `processEvents(ALL_EVENTS)` while they dispatch. Then `sendPostedEvents(null, DEFERRED_DELETE)`: Qt runs a `deleteLater()` posted outside an event handler only from `exec()`, which a pumped application never enters |

[^qt]

# Wake

`wakeDescriptor($fd)` makes a `QSocketNotifier($fd, READ)` whose `activated` disables it; each `pump()` enables it again, so a descriptor the loop has not read yet cannot keep a drain busy.[^qt]

[^qt]: Qt (the Toolkit)
[^session]: QtSession
