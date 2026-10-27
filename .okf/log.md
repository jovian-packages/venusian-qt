# Log

## 2026-10-27

* No `AlertTitle` on macOS (a window-modal box is a sheet). `QtClipboard::compare()` on window activation posts `ClipboardChanged` when the text or formats differ from the last seen: on macOS a clipboard read swallows Qt's own change report. Needs ext-qt 31fcbaf (`QMimeData::formats`). [windows and menus](architecture/windows-and-menus.md)
* Services: message box icons from the alert style, the suppression check box (answered as `suppressed`) and the window title; `supports()` gives both capabilities; file dialog name filters and hidden files (`QDir\Filter::HIDDEN` added to the dialog's filter); `QtClipboard` reads text, `hasText()` from `mimeData()`, `ClipboardChanged` from `dataChanged()`. Needs ext-qt 9e06ac5. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-26

* Canvas keys: `QtCanvasKeys` replaces the canvas's Tab-only filter: `KeyPress` posts `KeyPressed` and `EditCommanded` (the first `QKeySequence\StandardKey` the event matches) or `TextCommitted`; `KeyRelease` posts `KeyReleased`; an `InputMethod` commit posts `TextCommitted` (`WA_InputMethodEnabled` while focusable); a focused canvas takes every key. `QtWindowKeys::keyOf()` and `character()` shared. Needs ext-qt 0312633. [windows and menus](architecture/windows-and-menus.md)
* Canvas releases post only after their press; a lone modifier's press carries its modifier and its release does not; a real macOS A (native codes 0, with a timestamp) reads `Key::A`; macOS Control+K and Control+Y post DeleteToLineEnd and Yank. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-24

* By-eye fix (C11): an image-above button's picture and text touched the macOS bezel (labels on a fixed 4 px margin). The face now lies inside the style's `SE_PushButtonContents` rect, measured at the button's size (twice: macOS draws a tall push button as a bevel with other insets), and the minimum size is content plus those insets. Needs ext-qt ea23fb2.

## 2026-10-23

* Menus: radio items in the bar (checkable `QAction`s in one exclusive `QActionGroup` per group; `triggered(bool)` posts `MenuRadioChosen` when another item is chosen; `setChecked` emits `toggled`, never `triggered`, so `setRadio()` posts nothing), relabel (`setText`), `setRadio()` / `radio()` / `setItemLabel()` / `itemLabel()` on the window and the driver's default bar; `QtMenuBar` takes a `MenuStates` to share with the driver. Menu labels go to Qt literally (`&` doubled, as button text), at build and on relabel. `QtContextMenu` takes checkable toggles and radio groups and an owner told the user's choices; `QtPrimitive::applyPopupMenu()` ends an owned canvas press and pops the menu at `mapToGlobal` of the point, returning at once (`openPopup()` while it shows); removal closes it. `QtTabView` inserts with `insertTab`. Fix (C12): the window's ratio is read at its first show, and a ratio change before it posts nothing (a window shown on a 1× screen posted WindowScaleChanged). [primitives](architecture/primitives.md)
* Canvas scrolling: `QtScrollBar` (a standalone `QScrollBar`, range 0..max(0, maximum − page), page and single steps; the user's `valueChanged(int)` posts `ScrollChanged` latest-wins), hover in `QtCanvasPointer` (every canvas host tracks the mouse; an unpressed move with no button posts `CanvasMoved` latest-wins and is the canvas's enter too; ENTER and LEAVE count on the canvas's own widget or lent window only, a child's crossings being inside it; nothing during an owned press; a release away posts `CanvasLeft` after `CanvasReleased`; the canvas's pending move flushed before its exit), wheel phases (`QtCanvasPointer::phase()`: a scroll end after momentum is MOMENTUM_ENDED), `WindowScaleChanged` from the window's DEVICE_PIXEL_RATIO_CHANGE or SCREEN_CHANGE_INTERNAL (Qt 6.4) when `devicePixelRatioF()` changed. Found: neither scale event nor ENTER is a plain QEvent the ext makes, so tests run the handlers directly and enter by a move. [primitives](architecture/primitives.md)
* Path bar and toolbar: `QtComboBox` (an editable `QComboBox`, no insert, no completer of its own; completions through a `QCompleter` over a `QStringListModel` on its line edit, unfiltered popup; `activated(int)` a choice of an option, the completer's `activated(QString)` a choice of a completion), button images (`QtButtonImage`, `QtPixmap`: a Framebuffer as a `QPixmap` whose device pixel ratio is the scale; ABOVE lays out a pixmap label over a text label, transparent for mouse events) and `setFlat` on push and toggle buttons, `QtMenuButton` (a `QToolButton`, instant popup, the QMenu `QtContextMenu` builds). Found: one Return emits the combo's line edit `returnPressed` twice, and `activated(int)` with each on a matching option, so a key filter marks the press; focus is the combo's, not the line edit's, and the completer keeps its popup through a focus move made from code, so the combo's focus filter hides it; a Down that steps the combo sets the edit text before `activated(int)`, so that text change posts no TextChanged. Window keys pass a combo's Return as they pass a text input's. [primitives](architecture/primitives.md)
* Review fixes (C9): Return or Enter a focused text input submits posts no WindowKeyPressed (a line edit leaves the key unaccepted for a default button); hiding, disabling or removing the focused primitive or a container holding it posts no focus mail (`QtFocus::within`); a canvas that stops being focusable lets go (`clearFocus`); `present()` arms Qt's first focus only for a window not yet shown or active; Super and Hyper pressed alone post nothing; `setInitialFocus()` refuses another window's primitive. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-22

* A modifier pressed alone (Shift, Control, Meta, Alt, Caps Lock, AltGr) posts no WindowKeyPressed, as AppKit sends none.
* Hide and disable of the focused widget post no focus mail: they run in `quietly(null)`, and the holder becomes the widget Qt passed focus to. [windows and menus](architecture/windows-and-menus.md)
* Focus and window keys: `focus()` / `hasFocus()` on every primitive, `focused()` / `setInitialFocus()` on the window, focus mail from `FocusIn` (`QtFocus`), focusable canvases, `WindowKeyPressed` from the keys that reach the window (`QtWindowKeys`). Now requires `venusian-surface/human-input` (the keymaps). [windows and menus](architecture/windows-and-menus.md)
* Dialogs, clipboard and desktop: `QtDialogs` (`QMessageBox` with action-role buttons, default and escape buttons; `QFileDialog` modes; window-modal, `open()`; `finished(int)` answers; the parent's close disconnects it, then closes the dialog), `QtClipboard` (`QGuiApplication::clipboard()`), `QtDesktop` (`QDesktopServices::openUrl`). A window with an open dialog keeps the window manager's close. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-20

* Modal children, close veto and button roles: window-modal children (sheet on macOS, dialog elsewhere), `confirmClose()` in the window filter, `QtButtonKeys` (application key filter), an empty parentless bar on macOS when no default profile is set. [windows and menus](architecture/windows-and-menus.md)
* Menu enabled state and accelerators: folder `menuAction()`s, `setItemEnabled()` on bars, windows and the driver's default profile, `shortcut()` from `Accelerator`, `QtMenuKeys` so a shortcut gets its key before a focused text widget. [windows and menus](architecture/windows-and-menus.md)
* By-eye fixes on the gaming PC (X11, Qt 6.4.2): a top level's own `QWindow`, which real input reaches before the widget, no longer counts as a lent canvas (clicks were lost on every platform); a wheel is precise by platform (cocoa: source, wayland: pixel delta, xcb: never, as xcb fills `pixelDelta` with the raw valuator, 120 a notch); the second click of a double click posts `view.clicked` (Qt delivers it as a double-click event). [windows and menus](architecture/windows-and-menus.md)

## 2026-10-19

* Canvas pointer and wheel: `QtCanvasPointer` per window, an application event filter posting `CanvasPressed`, `CanvasDragged`, `CanvasReleased` and `CanvasWheeled` for a canvas that watches the pointer, one press owned at a time, clicks counted with Qt's double-click hints, a wheel over a watching canvas eaten. `QtRightClicks` releases an owned press when its menu opens. Clicks and right clicks now work on a canvas lent to Metal or Vulkan (its `QWindow`, `QtCanvas::lentWindow()`): before, both posted nothing there. Requires ext-qt ^0.10.3 with `QWheelEvent`. [windows and menus](architecture/windows-and-menus.md)
* Removed primitives are freed. QtVideo's player slots can outlive the widget while the media backend winds down (Linux), and held the primitive; they now reach it through a WeakReference from static closures. `FreedTest` builds, removes and collects every kind Qt has.

## 2026-10-18

* QtTree: a read-only QTreeWidget, one QTreeWidgetItem per node (id in the user role, coloured foregrounds). NodeSelected, NodeExpanded and NodeActivated post only for user actions; Return and Enter go through an event filter on every platform. Opening a node closes the children Qt remembers expanded that the record has collapsed. Requires ext-qt ^0.10.3 and Surface contracts and windows ^0.10.3.

* QtSplitView: SplitMoved only when a divider moved between the handle's press and release (splitterMoved fires on every move); the handle filter goes on every handle after each add, with no object-id bookkeeping.
* QtSplitView (QSplitter, equal first layout, positions as fractions from sizes() and the handle width, SplitMoved at the release of a handle drag that splitterMoved marked) and QtTabView (QTabWidget, TabSelected for user switches; a page's removal is not one); venusian-surface contracts and windows ^0.10.2, which carry TKSplitView and TKTabView. 0.10.5.

## 2026-10-14

* QtTextArea: read-only, wrap, monospace (system fixed font), extra-selection line backgrounds and range colours, line selection over UTF-16, scroll-bar scroll position and ViewScrolled.

## 2026-10-10

* QtLink: TKLink over a rich-text QLabel that opens its link (toolkit links).
* Edit menu: edit roles call the focused line edit's or plain text edit's own slot, no shortcut.

## 2026-10-09

* Canvas clicks: `QtCanvasClicks` posts `view.clicked` with the point for a press and release on a canvas. [windows and menus](architecture/windows-and-menus.md)
* Context menus: `QtContextMenu`, a `QMenu` popped up by `QtRightClicks` for the primitive or nearest group with a menu, the widget's own menu kept shut; items post `MenuActivated`. Requires ext-qt `QMenu::popup`. [windows and menus](architecture/windows-and-menus.md)
* Right-click mail: `QtRightClicks` posts `view.right-clicked` / `window.right-clicked` for Qt's context-menu request (right button, Ctrl-click on macOS) and a touchscreen long press; `QtWindow::contentArea()`. [windows and menus](architecture/windows-and-menus.md)
* The desktop identity is `config/app.php` `app.id`; `bridge.qt.desktop_file_name` and the `org.venusian.Surface` default are gone, and connect throws without `app.id`. A packaged build names its `.desktop` file after the same id. [Config](api/config.md).

## 2026-10-08

* `QtCanvas::applyPixels()` takes the damage list Surface now passes; the whole frame is still shown.

## 2026-10-06

* Vulkan lending review fixes: explicit Qt teardown on reclaim, the canvas takes the surface back when the device lets go first, Qt must load ext-vulkan's own loader; verified on xcb too. [primitives](architecture/primitives.md)
* `QtCanvas` lends a Vulkan surface to the `vulkan` engine on macOS and Linux: a Vulkan `QWindow` whose `QVulkanInstance` is the engine's `VkInstance`, the surface Qt makes for it, first in `surfaces()`. macOS needs `QT_VULKAN_LIB`. [primitives](architecture/primitives.md)
* `QtCanvas` lends its `QOpenGLWidget`'s context to the `opengl` engine on macOS and Linux: 4.1 core on macOS, ES 3 elsewhere; `paintGL()` copies the frame. [primitives](architecture/primitives.md)

## 2026-10-05

* `QtCanvas` lends an SDL window over Qt's `NSWindow` to the `sdl3` engine on macOS: SDL's swapchain view moved into the Metal `QWindow`'s view, the window destroyed once the device lets go. [primitives](architecture/primitives.md)
* The canvas restores the responder chain SDL rewires; the session sweeps parked SDL windows every pump. [primitives](architecture/primitives.md)
* `QtCanvas` lends Qt's `CAMetalLayer` (a Metal `QWindow` in a window container, the layer read through ext-appkit) to the `metal` engine on macOS. [primitives](architecture/primitives.md)

## 2026-10-04

* `QtCanvas` pipes an ext-fb framebuffer: the `QImage` copies its memory by address, so no pixel byte passes through PHP. [primitives](architecture/primitives.md)

## 2026-10-03

* `QtCanvas`: the framebuffer a `TKCanvas` hands out, shown as a `QLabel`'s pixmap. [primitives](architecture/primitives.md)

## 2026-10-02

* New: [primitives](architecture/primitives.md) — factory, containers, shared hooks, leaves, table, video.
* Review fixes: fill = spare space by stretch factors (stack spacer, grid spare line); fixed covers its frames; image host sized by a spacer, smooth DPR scaling; literal button/label text; slider steps; date picker range 100..9999 and today-pick; `handling()` guard; push-button backgrounds with a border; filter closures hold owners weakly (no leaked images or closed windows). [Testing](runbooks/testing.md): lifetime test, `pendingLatest()`.
* [Windows and menus](architecture/windows-and-menus.md): content hosting, `centralWidget()`, coalesced `WindowResized`, close removes the content tree. [Testing](runbooks/testing.md): `pumpFor()` through `ToolkitPump`, primitive coverage, `afterEach` close.

## 2026-10-01

* Bundle created: [session](architecture/session.md), [windows and menus](architecture/windows-and-menus.md), [config](api/config.md), [testing](runbooks/testing.md).
