---
type: Module
title: Window
description: QMainWindow with a central widget, wired to Topside's window lifecycle through event filters; resizes from the central widget.
resource: src/QtWindow.php
tags: [qt, windows]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-21T17:39:27Z }
verified:
  - { by: claude-opus/5.5, at: 2026-11-20T21:20:00Z }
sources:
  - id: window
    resource: src/QtWindow.php
    title: QtWindow
---

# Overview

`QtWindow extends Surface\Toolkits\ToolkitWindow`. The close path, confirm-close, `close()` from code, resize mail and the after-pump `settle()` call are Topside's; this class builds the native window and connects Qt's events to them.[^window]

# Native

* `QMainWindow`, title = name, `resize()`, `DELETE_ON_CLOSE` (Qt deletes it after an accepted close).
* A child window: `setParent(parent)`, window flags `SHEET` on macOS and `DIALOG` elsewhere, `WINDOW_MODAL`.
* Central widget with a `QVBoxLayout`, no margins or spacing: where the content container goes.
* `native()` = the `QMainWindow`; `size()` = the central widget's size.

# Events

Two `QEventFilter`s, each holding the window weakly (a strong capture would be a cycle through the C++ filter that PHP's collector cannot see).

| Event | Goes to |
|---|---|
| central widget `RESIZE` | `resized()` with the central widget's size: one `WindowResized` per pump |
| window `CLOSE` | `allowsUserClose()`: ignored and kept from the window while confirming; else the close path |
| window `WINDOW_ACTIVATE` | `gainedFocus()` (topside's menus), then `WindowFocused` |
| window `WINDOW_DEACTIVATE` | `lostFocus()` (topside's menus) |
| window `DEVICE_PIXEL_RATIO_CHANGE` (Qt 6.6+), `SCREEN_CHANGE_INTERNAL` (Qt 6.4) | `WindowScaleChanged` when the ratio differs from the last posted, from the first show on |
| window `SHOW` | records the first show and its ratio |
| `destroyed(QObject*)` | the close path, for a window deleted any other way |

# Close

* `closeNative()` = `QMainWindow::close()`: the close event takes the close path.
* `releaseNative()` keeps the native's PHP wrapper in `$departed` until this object goes: the close path runs inside the window's own close event, and an ext-qt before 2026-11-12 deleted the `QMainWindow` when its last reference dropped there.

# Not ported yet

From the [bridge-era window](/architecture/windows-and-menus.md): focus tracking (`quietly()` is a pass-through), window and button-role keys, right clicks, clipboard compare on activation.

[^window]: QtWindow
