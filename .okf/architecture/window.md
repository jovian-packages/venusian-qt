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

# Alert

`showAlert()`: a `QMessageBox` parented to the window, window-modal, `open()`ed; buttons added with `ActionRole` in order and kept (`$alert_buttons`), since the box gives back the clicked button and not its index; `setDefaultButton` and `setEscapeButton` from the alert. `&` in a label is doubled. The box's text format is `PLAIN_TEXT` (ext-qt 52cd709), so text shows as written in Qt's own box; Qt still decides native from the raw text (`QCocoaMessageDialog::show` refuses text `mightBeRichText`), so plain text keeps the `NSAlert` and text that looks like HTML gets Qt's own box, shown as written. The bridge-era `<qt>` wrapper is not ported: it made every box Qt's own. On macOS Qt 6.11 shows the box as an `NSAlert` sheet and, matching AppKit, gives Esc to no button when the default is also the escape button (qcocoamessagedialog.mm); elsewhere Esc clicks the escape button. Its check box takes the native box's state as the alert ends, before the click. `closeAlert()` closes the box. `tests/Integration/AlertTest.php`: 6 cases, the tick case skipped on macOS (the tick is in the native box).

# Browse

`openUrl()`: `QDesktopServices::openUrl(new QUrl($url))`.

# Not ported yet

From the [bridge-era window](/architecture/windows-and-menus.md): focus tracking (`quietly()` is a pass-through), window and button-role keys, right clicks, clipboard compare on activation.

[^window]: QtWindow
