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
* `triggered(bool)` (user only, never `setChecked`): toggle → `MenuToggled(checked)`; About → session About; Quit → `QuitRequested(window)`; edit role → `QtMenuBar::edit(QApplication::focusWidget())`, the focused `QLineEdit`/`QPlainTextEdit` slot, no shortcut, no mail; else `MenuActivated`.
* `setToggle` → `setChecked`, no mail.

Placement: Qt shows a window's bar inside it on Linux and as the global bar on macOS while active.

# Default bar (macOS)

`setDefaultMenuBar(profile)`: same instance → no-op; else old bar `deleteLater()`, new `QtMenuBar` on a parentless `QMenuBar` (window `null`). Qt shows a parentless bar automatically whenever no window with its own bar is active. Linux builds none. `defaultMenuBar()` for tests.[^driver]

[^window]: QtWindow
[^bar]: QtMenuBar
[^driver]: QtBridgeDriver

# Right clicks

`QtRightClicks` per window installs an application `QEventFilter` (context-menu events go to the widget under the pointer) for `CONTEXT_MENU`, mouse press/move/release; removed when the window closes. `QContextMenuEvent` (Qt raises it for the right button and Ctrl-click on macOS) with reason other than Keyboard → right click. On Linux a left press held within `startDragDistance()` for `QStyleHints::mousePressAndHoldInterval()` is a right click: the Pi's touchscreen is registered as a pointer and reaches Qt as a left press, and Qt raises no context menu for a long one (measured 2026-10-09). An event Qt passes up to parents counts once: only when the watched widget is `QApplication::widgetAt(global)`. `at()`: walk `parentWidget()` to the first native a registry primitive owns → `View\RightClicked` (`mapFromGlobal`), nothing if it or a group is disabled; the central widget → `WindowRightClicked`; the main window (menu bar) → nothing. `contentArea()` is the central widget.

Context menus (slice 27): after `View\RightClicked`, the menu of the primitive or its nearest enabled group with one opens. `QtContextMenu` fills a parentless `QMenu` as the bar fills its menus (separators, submenus; each action's `triggered(bool)` posts `MenuActivated`) and `popup()`s it at the click's global point, non-blocking. The filter returns true for the context-menu event it opened a menu for, so the widget's own menu (a text input's) stays shut; a held press opens it from the timer. One open at a time: the next right click hides the last. The window's close (`detach()`) hides an open one: macOS Qt closes it anyway, Linux Qt leaves the parentless popup up. `openMenu()` is the last one built.

Canvas clicks (slice 28): `QtCanvasClicks` per window, its own application `QEventFilter` for mouse press, move and release (returns false; removed on close). An event counts once: when the watched widget is `QApplication::widgetAt(global)` (a canvas's framebuffer shows in a child `QLabel`, walked up to the canvas's host). A left press without `Qt::MetaModifier` (the Control key on macOS: a right click) on an enabled canvas → the press point (`mapFromGlobal` on the host); a move past `startDragDistance()` cancels, and on Linux a release after `mousePressAndHoldInterval()` is no click (a right click); a left release over the same canvas → `View\CanvasClicked` (`view.clicked`) at the press point.

