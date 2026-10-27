---
type: Module
title: Windows and menus
description: QMainWindow per name, event-filter mail, QMenuBar with roles, parentless default bar on macOS, About box.
resource: src/Windows/
tags: [qt, windows, menus]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-22T13:10:00Z }
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

* `QEventFilter` for `CLOSE` and `WINDOW_ACTIVATE`. `CLOSE`: while `confirmClose()` and not inside `close()` → `$event->ignore()`, post `WindowCloseRequested`, return `true` (Qt keeps a window whose close is not accepted); else the close path and `false` (QMainWindow accepts, Qt deletes the window on the next pump). `WINDOW_ACTIVATE` → `WindowFocused`, `false`.
* Child (`open(…, parent)`): `setParent(parent)` (PHP keeps the one-argument form), `setWindowFlags(SHEET)` on macOS / `DIALOG` elsewhere, `setWindowModality(WINDOW_MODAL)`. Qt ignores the user's close of a blocked parent (`processCloseEvent`). `close()` closes the child first (Qt would leave the child's sheet open on the hidden parent); `present()` presents a hidden parent first.
* Focus (`QtFocus`, one per window): an application `QEventFilter` on `FocusIn` for widgets whose `window()` is this window; the holder is the primitive whose native is the widget or its nearest ancestor, kept while the window is inactive (Qt re-sends `FocusIn` to the same widget on activation, which posts nothing). `focus()` and the initial focus run in `quietly($target)`, which sets the holder at once, so a `FocusIn` that comes later (when the window activates) posts nothing; `present()` makes the next `FocusIn` Qt's own when the window holds no focus. Hide and disable of the holder run in `quietly(null)`: Qt passes focus on at once, and the holder becomes `QApplication::focusWidget()`'s primitive. Holders are weak references. `focus()` refuses a native whose `focusPolicy()` is `NoFocus`. A focusable canvas: the host's `StrongFocus`, `WA_InputMethodEnabled` while focusable, and its `QtCanvasKeys` (below), whose filter keeps Tab and Backtab while `takesTab()`.
* Canvas keys (`QtCanvasKeys`, one per focusable canvas, a filter on its host): answers only while the canvas is the focus holder. `KeyPress` posts `KeyPressed` (key through `QtWindowKeys::keyOf`, text through `QtWindowKeys::character`, modifiers, `isAutoRepeat`), then `EditCommanded` for the first `QKeySequence\StandardKey` the event `matches` (`QtCanvasKeys::command()`: selects before moves, words and lines before characters; Qt's own platform bindings, so Ctrl reads as Cmd on macOS; ahead of them on macOS the physical Control+K and Control+Y post DeleteToLineEnd and Yank, as AppKit and GTK do, where Qt's Mac table says Cut and Paste), or InsertTab / InsertBacktab for Tab / Backtab (no StandardKey names them), or DeleteBackward for a Backspace no StandardKey matched (Qt's X11 table binds plain Backspace to none), or `TextCommitted` for a printable `text()` that is not a lone modifier; returns true, so no `WindowKeyPressed`. `KeyRelease` posts `KeyReleased` only for a key whose `KeyPressed` posted, once; an auto-repeat release (X11 sends one before each repeat press) is taken and posts nothing. A lone modifier key's own flag is set on its press and cleared on its release (macOS reports the state before the key on a press and during it on a release). An `InputMethod` event with a commit string posts `TextCommitted` and is left to Qt (composed text: dead keys, candidate windows). Tab and Backtab are declined while the canvas does not take Tab; an unfocused canvas declines every key. Qt's shortcut map (`QtMenuKeys`) and the application-level role filter (`QtButtonKeys`) run before a widget's filter, so accelerators and role keys never arrive.
* Window keys (`QtWindowKeys`): a filter on the window's `QMainWindow` for `KeyPress`es that reach it (unaccepted below), posting `WindowKeyPressed` and eating the key. Physical key: `LinuxKeycodes` of `nativeScanCode() - 8` on X11, `MacKeycodes` of `nativeVirtualKey()` on macOS, `Key::UNKNOWN` when both native codes are 0 and the event has no timestamp (an event made in code; a real macOS A has both codes 0 too, since key code 0 is A and Qt gives macOS events scan code 0, but a real event always carries a timestamp). Requires `venusian-surface/human-input` for the keymaps.
* Button roles (`QtButtonKeys`, one per driver, made on connect): application `QEventFilter` on `KeyPress`; Return / keypad Enter → DEFAULT holder, Esc → CANCEL holder, no modifier but keypad; `click()` and eat the press when the holder `isVisible()` and `isEnabled()`; Return goes on to a focused text area's `QPlainTextEdit`; popups are their own windows, not the driver's, so their keys go on; Esc in a child with no cancel holder → `userClose()`. A window `QShortcut` for Return would beat a focused text area while `QtMenuKeys` eats every `ShortcutOverride` (probe). Look: `QPushButton::setDefault()`.
* Second filter, `RESIZE` on the central widget → `postLatest("window.resized.<name>", WindowResized(central size))`: the central widget's own Resize carries the content size after the main window's layout ran; a burst within one pump = one mail.
* Close path: `removeContent()` (whole tree, natives unparented + `deleteLater()`, watched views drop their pending mail), `forgetLatest("window.resized.<name>")`, post `WindowClosed`, driver forgets.
* `destroyed` → same close path, for deletion any other way. Path runs once.
* `close()` → `QWidget::close()`: close event sent for shown and never-shown windows alike.
* `present()` → `show()`, `raise()`, `activateWindow()`.
* `setMenuBar(profile)` → new `QMenuBar` via `QMainWindow::setMenuBar` (Qt deletes the old one).

# Menu bar

`QtMenuBar`: profile → the given `QMenuBar` (window's `menuBar()`, or a new parentless one). Folders → `addMenu` (its `menuAction()` is the folder's action), separators → `addSeparator`.[^bar]

* Roles: About → `ABOUT_ROLE`, Quit → `QUIT_ROLE` (macOS moves both into the application menu); every other item `NO_ROLE` so Qt's text heuristic moves nothing.
* Accelerator → `setShortcut(QKeySequence::fromString(shortcut($keys)))`, portable text in Qt's order (`Meta+Ctrl+Alt+Shift+<key>`); Qt's Ctrl is Command and Meta is Control on macOS. Key names `QT_NAMES` (`PgDown`, `Esc`, `Del`, …), else the character or upper-case letter.
* Enabled state: `MenuStates`; each node's action gets `setEnabled(effective)` at build and on `setItemEnabled()` (the node and every node below it). A disabled `QAction` does not fire its shortcut.
* `QtMenuKeys` (one per driver, made on connect): application `QEventFilter` returning true for every `ShortcutOverride`, so no focused widget keeps a key from a shortcut; a key without a shortcut reaches the widget as a key press.
* `triggered(bool)` (user only, never `setChecked`): toggle → `MenuToggled(checked)`; About → session About; Quit → `QuitRequested(window)`; edit role → `QtMenuBar::edit(QApplication::focusWidget())`, the focused `QLineEdit`/`QPlainTextEdit` slot, no shortcut, no mail; else `MenuActivated`.
* `setToggle` → `setChecked`, no mail.

Placement: Qt shows a window's bar inside it on Linux and as the global bar on macOS while active.

# Default bar (macOS)

`setDefaultMenuBar(profile)`: same instance → no-op; else old bar `deleteLater()`, new `QtMenuBar` on a parentless `QMenuBar` (window `null`). Qt shows a parentless bar automatically whenever no window with its own bar is active. Linux builds none. `defaultMenuBar()` for tests. With no default profile the driver keeps an empty parentless `QMenuBar` (`globalMenuBar()`): with none, Qt keeps the last bar and a sheet's parent keeps its items enabled. While a window-modal child is focused, Qt shows the parentless bar with every folder and Quit disabled (`QCocoaMenuBar::shouldDisable`, probe 2026-10-20).[^driver]

[^window]: QtWindow
[^bar]: QtMenuBar
[^driver]: QtBridgeDriver

# Right clicks

`QtRightClicks` per window installs an application `QEventFilter` (context-menu events go to the widget under the pointer) for `CONTEXT_MENU`, mouse press/move/release; removed when the window closes. `QContextMenuEvent` (Qt raises it for the right button and Ctrl-click on macOS) with reason other than Keyboard → right click. On Linux a left press held within `startDragDistance()` for `QStyleHints::mousePressAndHoldInterval()` is a right click: the Pi's touchscreen is registered as a pointer and reaches Qt as a left press, and Qt raises no context menu for a long one (measured 2026-10-09). An event Qt passes up to parents counts once: only when the watched widget is `QApplication::widgetAt(global)`. `at()`: walk `parentWidget()` to the first native a registry primitive owns → `View\RightClicked` (`mapFromGlobal`), nothing if it or a group is disabled; the central widget → `WindowRightClicked`; the main window (menu bar) → nothing. `contentArea()` is the central widget.

Context menus: after `View\RightClicked`, the menu of the primitive or its nearest enabled group with one opens. `QtContextMenu` fills a parentless `QMenu` as the bar fills its menus (separators, submenus; each action's `triggered(bool)` posts `MenuActivated`) and `popup()`s it at the click's global point, non-blocking. The filter returns true for the context-menu event it opened a menu for, so the widget's own menu (a text input's) stays shut; a held press opens it from the timer. One open at a time: the next right click hides the last. The window's close (`detach()`) hides an open one: macOS Qt closes it anyway, Linux Qt leaves the parentless popup up. `openMenu()` is the last one built.

Canvas clicks: `QtCanvasClicks` per window, its own application `QEventFilter` for mouse press, double click (Qt delivers the second press of a double click to a widget as that alone), move and release (returns false; removed on close). An event counts once: when the watched widget is `QApplication::widgetAt(global)` (a canvas's framebuffer shows in a child `QLabel`, walked up to the canvas's host). A left press without `Qt::MetaModifier` (the Control key on macOS: a right click) on an enabled canvas → the press point (`mapFromGlobal` on the host); a move past `startDragDistance()` cancels, and on Linux a release after `mousePressAndHoldInterval()` is no click (a right click); a left release over the same canvas → `View\CanvasClicked` (`view.clicked`) at the press point.

A canvas lent to Metal or Vulkan shows a `QWindow` in a window container, whose events never enter the widget tree. Clicks and right clicks take it as its canvas's (`QtCanvasPointer::canvasOf()`, `QtCanvas::lentWindow()`), each event at its first delivery. Qt 6.9+ raises a `QContextMenuEvent` for an ignored right press on a `QWindow`; older Qt raises none, so there `QtRightClicks` takes the right press itself (`lentPressOpensMenu()`, set from `qVersion()`), and ignores the window's context-menu request when it does.

# Canvas pointer

Canvas pointer and wheel (C4): `QtCanvasPointer` per window, its own application `QEventFilter` (removed on close), static closure through a `WeakReference`. Application-level, not the canvas widget's own filter, for the lent `QWindow`s.

* First delivery only (`firstDelivery()`): a press, double click or wheel when the watched object is a `QWindow` a canvas lends (`lends()`; real input reaches a top level's own `QWindow` before the widget, and that one is no canvas's) or `QApplication::widgetAt(global)`; a move or release of an owned press only at the object the press went to, where Qt's implicit grab delivers them inside the window or out of it. Clicks and right clicks use the same check.
* The first button down on a watching, enabled canvas owns the press; another button posts nothing until it ends. `CanvasDragged` only when the canvas point changed. Points: `mapFromGlobal(globalPosition())` on the canvas's native. Exactly one `CanvasReleased` per `CanvasPressed`: the owning button's release, or `interrupt()` at the last point posted, which `QtRightClicks` calls right after `popupAt`.
* Every event of an owned press re-checks the canvas: removed, no longer watching, or disabled itself or inside a group drops the press silently.
* `clicks` counted from event timestamps with `mouseDoubleClickInterval()` / `mouseDoubleClickDistance()` (Qt sends no triple click). A double click while that button's press is owned is the press Qt delivered just before it; otherwise it is the press.
* Buttons: `button()` 1 left, 2 right, 4 middle, 8 X1, 16 X2, others post nothing. Modifiers: `modifiers(int, macos)`, Control and Meta swapped on macOS as Qt swaps them.
* Wheel: `precise(event, ?platform)` by Qt's platform plugin: cocoa when `source()` is `MOUSE_EVENT_SYNTHESIZED_BY_SYSTEM` (cocoa fills `pixelDelta` for mouse wheels too); wayland when `pixelDelta()` is non-zero (finger and continuous sources only); xcb never, since it fills `pixelDelta` from the X driver's raw valuator, 120 a notch on the gaming PC's mouse (a touchpad there reads as fractions of a notch). `dx`, `dy` = `-pixelDelta` when precise, else `-angleDelta / 120`. The filter returns true for a wheel it posted, so the widget never sees it and the window passes it to no parent. Qt 6 propagates no sent wheel, so tests read that off a widget filter and `isAccepted()`.

# Dialogs

Dialogs (C7): `QtDialogs extends ToolkitDialogs` (Surface holds the request ids and the one-modal-per-parent rule). Every dialog is a child of the parent's `QMainWindow`, `WINDOW_MODAL`, shown with `open()`.

* Alert: `QMessageBox`; each label an `ACTION_ROLE` button; `setDefaultButton` (Return), `setEscapeButton` (Esc and the title-bar close). `finished(int)` answers the index of `clickedButton()` in the button list, or the cancel index, and `suppressed` from the check box's `isChecked()`. Style → `setIcon` (Info `INFORMATION`, Warning `WARNING`, Critical `CRITICAL`; none leaves `NO_ICON`); a suppression label → `setCheckBox(new QCheckBox(label))` (`&` doubled, as a button's); a title → `setWindowTitle`. `supports()`: `ShowHidden`; `AlertTitle` everywhere but macOS, where a window-modal box is a sheet with no title bar.
* Files: `QFileDialog(parent, title, folder)`; `EXISTING_FILE`, `EXISTING_FILES`, or `DIRECTORY` with `SHOW_DIRS_ONLY`; save is `ACCEPT_SAVE` with `selectFile(name)`. Filters → `setNameFilters` with `Name (*.a *.b)` each; `showHidden` ORs `QDir\Filter::HIDDEN` into the dialog's own `filter()` (Qt's macOS panel reads the bit too). `finished(1)` answers `selectedFiles()`, any other result `[]`. `native($id)` gives the open request's box or dialog.
* Each id ends by disconnecting `finished(int)` and `deleteLater()` on the dialog. `closeOn(parent)` (from `QtWindow::close()`, after the child) forgets the id, disconnects, closes the dialog, then posts `-1` / `[]`. `closeEvent` keeps the window while `openOn()` has an id.
* The `finished(int)` handlers reach `QtDialogs` through `WeakReference` from `static` closures.
* Alert text and detail go in as escaped rich text (`<qt><p style="white-space:pre-wrap">…</p></qt>`): the box's labels are AutoText, so text that looks like HTML would render as HTML, and Qt 6.4 keeps the informative label AutoText even after `setTextFormat()` (probed 2026-10-22 on 6.4.2 and 6.11.2). Button labels go through `QtDialogs::buttonLabel()`, which doubles `&` (a mnemonic marker).

# Clipboard

`QtClipboard extends ToolkitClipboard` over `QGuiApplication::clipboard()`, made when the session connects so `dataChanged()` runs from the start. `requestText()` answers at once from `text()`, `null` when `mimeData()->hasText()` is false; `hasText()` is that test. `dataChanged()` posts `ClipboardChanged` (latest-wins), for this app's `setText()` and another app's copy. On macOS Qt reports another app's copy only through its activation check, and every `mimeData()` read clears the flag that check reads (`QMacPasteboard::sync()` → `PasteboardSynchronize` & `kPasteboardModified`, Qt 6.11.2): so on macOS the clipboard keeps the text and formats it last saw, and `QtWindow`'s `WINDOW_ACTIVATE` calls `compare()`, which posts when they differ. The same content copied again posts nothing. Elsewhere `compare()` does nothing and no snapshot is taken: `dataChanged()` is complete there, and a snapshot reads the owner's data synchronously, which waits out Qt's selection timeout per read when the owner hangs (40 s on X11 with a stopped xclip, before the fix).
