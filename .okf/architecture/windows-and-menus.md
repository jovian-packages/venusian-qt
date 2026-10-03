---
type: Module
title: Windows and menus
description: QMainWindow per name, event-filter mail, QMenuBar with roles, parentless default bar on macOS, About box.
resource: src/Windows/
tags: [qt, windows, menus]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-02T22:21:15Z }
sources:
  - id: window
    resource: src/Windows/QtWindow.php
    title: QtWindow
  - id: bar
    resource: src/Windows/QtMenuBar.php
    title: QtMenuBar
  - id: driver
    resource: src/Bridge/QtBridgeDriver.php
    title: QtBridgeDriver
---

# Window

`QtWindow implements ToolkitWindow`, `use HostsPrimitives`. `QMainWindow`, title = name, `resize`, `DELETE_ON_CLOSE`, central widget = `centralWidget()` with a zero-margin `QVBoxLayout`: the one content container ([primitives](/architecture/primitives.md)) is added with stretch 1 and fills it; `content()` is that container. `size()` = central widget size.[^window]

* `QEventFilter` for `CLOSE` and `WINDOW_ACTIVATE`, returns `false` (events pass on). `CLOSE` → close path; QMainWindow accepts the close, Qt deletes the window on the next pump. `WINDOW_ACTIVATE` → `WindowFocused`.
* Second filter, `RESIZE` on the central widget → `postLatest("window.resized.<name>", WindowResized(central size))`: the central widget's own Resize carries the content size after the main window's layout ran; a burst within one pump = one mail.
* Close path: `removeContent()` (whole tree, natives unparented + `deleteLater()`, watched views drop their pending mail), `forgetLatest("window.resized.<name>")`, post `WindowClosed`, driver forgets.
* `destroyed` → same close path, for deletion any other way. Path runs once.
* `close()` → `QWidget::close()`: close event sent for shown and never-shown windows alike.
* `present()` → `show()`, `raise()`, `activateWindow()`.
* `setMenuBar(profile)` → new `QMenuBar` via `QMainWindow::setMenuBar` (Qt deletes the old one).

# Menu bar

`QtMenuBar`: profile → the given `QMenuBar` (window's `menuBar()`, or a new parentless one). Folders → `addMenu`, separators → `addSeparator`.[^bar]

* Roles: About → `ABOUT_ROLE`, Quit → `QUIT_ROLE` (macOS moves both into the application menu); every other item `NO_ROLE` so Qt's text heuristic moves nothing.
* Hotkey → `setShortcut('Ctrl+<KEY>')`; Qt's Ctrl is Command on macOS.
* `triggered(bool)` (user only, never `setChecked`): toggle → `MenuToggled(checked)`; About → session About; Quit → `QuitRequested(window)`; else `MenuActivated`.
* `setToggle` → `setChecked`, no mail.

Placement: Qt shows a window's bar inside it on Linux and as the global bar on macOS while active.

# Default bar (macOS)

`setDefaultMenuBar(profile)`: same instance → no-op; else old bar `deleteLater()`, new `QtMenuBar` on a parentless `QMenuBar` (window `null`). Qt shows a parentless bar automatically whenever no window with its own bar is active. Linux builds none. `defaultMenuBar()` for tests.[^driver]

[^window]: QtWindow
[^bar]: QtMenuBar
[^driver]: QtBridgeDriver
