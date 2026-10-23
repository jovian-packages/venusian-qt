# Log

## 2026-10-23

* Review fixes (C9): Return or Enter a focused text input submits posts no WindowKeyPressed (a line edit leaves the key unaccepted for a default button); hiding, disabling or removing the focused primitive or a container holding it posts no focus mail (`QtFocus::within`); a canvas that stops being focusable lets go (`clearFocus`); `present()` arms Qt's first focus only for a window not yet shown or active; Super and Hyper pressed alone post nothing; `setInitialFocus()` refuses another window's primitive. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-22

* A modifier pressed alone (Shift, Control, Meta, Alt, Caps Lock, AltGr) posts no WindowKeyPressed, as AppKit sends none.
* Hide and disable of the focused widget post no focus mail: they run in `quietly(null)`, and the holder becomes the widget Qt passed focus to. [windows and menus](architecture/windows-and-menus.md)
* Focus and window keys (compare-app toolkit C9): `focus()` / `hasFocus()` on every primitive, `focused()` / `setInitialFocus()` on the window, focus mail from `FocusIn` (`QtFocus`), focusable canvases, `WindowKeyPressed` from the keys that reach the window (`QtWindowKeys`). Now requires `venusian-surface/human-input` (the keymaps). [windows and menus](architecture/windows-and-menus.md)
* Dialogs, clipboard and desktop (compare-app toolkit C7): `QtDialogs` (`QMessageBox` with action-role buttons, default and escape buttons; `QFileDialog` modes; window-modal, `open()`; `finished(int)` answers; the parent's close disconnects it, then closes the dialog), `QtClipboard` (`QGuiApplication::clipboard()`), `QtDesktop` (`QDesktopServices::openUrl`). A window with an open dialog keeps the window manager's close. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-20

* Modal children, close veto and button roles (compare-app toolkit C6): window-modal children (sheet on macOS, dialog elsewhere), `confirmClose()` in the window filter, `QtButtonKeys` (application key filter), an empty parentless bar on macOS when no default profile is set. [windows and menus](architecture/windows-and-menus.md)
* Menu enabled state and accelerators (compare-app toolkit C5): folder `menuAction()`s, `setItemEnabled()` on bars, windows and the driver's default profile, `shortcut()` from `Accelerator`, `QtMenuKeys` so a shortcut gets its key before a focused text widget. [windows and menus](architecture/windows-and-menus.md)
* By-eye fixes on the gaming PC (X11, Qt 6.4.2): a top level's own `QWindow`, which real input reaches before the widget, no longer counts as a lent canvas (clicks were lost on every platform); a wheel is precise by platform (cocoa: source, wayland: pixel delta, xcb: never, as xcb fills `pixelDelta` with the raw valuator, 120 a notch); the second click of a double click posts `view.clicked` (Qt delivers it as a double-click event). [windows and menus](architecture/windows-and-menus.md)

## 2026-10-19

* Canvas pointer and wheel (compare-app toolkit C4): `QtCanvasPointer` per window, an application event filter posting `CanvasPressed`, `CanvasDragged`, `CanvasReleased` and `CanvasWheeled` for a canvas that watches the pointer, one press owned at a time, clicks counted with Qt's double-click hints, a wheel over a watching canvas eaten. `QtRightClicks` releases an owned press when its menu opens. Clicks and right clicks now work on a canvas lent to Metal or Vulkan (its `QWindow`, `QtCanvas::lentWindow()`): before, both posted nothing there. Requires ext-qt ^0.10.3 with `QWheelEvent`. [windows and menus](architecture/windows-and-menus.md)
* Removed primitives are freed. QtVideo's player slots can outlive the widget while the media backend winds down (Linux), and held the primitive; they now reach it through a WeakReference from static closures. `FreedTest` builds, removes and collects every kind Qt has.

## 2026-10-18

* QtTree: a read-only QTreeWidget, one QTreeWidgetItem per node (id in the user role, coloured foregrounds). NodeSelected, NodeExpanded and NodeActivated post only for user actions; Return and Enter go through an event filter on every platform. Opening a node closes the children Qt remembers expanded that the record has collapsed. Requires ext-qt ^0.10.3 and Surface contracts and windows ^0.10.3.

* QtSplitView: SplitMoved only when a divider moved between the handle's press and release (splitterMoved fires on every move); the handle filter goes on every handle after each add, with no object-id bookkeeping.
* QtSplitView (QSplitter, equal first layout, positions as fractions from sizes() and the handle width, SplitMoved at the release of a handle drag that splitterMoved marked) and QtTabView (QTabWidget, TabSelected for user switches; a page's removal is not one); venusian-surface contracts and windows ^0.10.2, which carry TKSplitView and TKTabView (compare-app split and tabs, C2). 0.10.5.

## 2026-10-14

* QtTextArea: read-only, wrap, monospace (system fixed font), extra-selection line backgrounds and range colours, line selection over UTF-16, scroll-bar scroll position and ViewScrolled (compare-app text panes, C1).

## 2026-10-10

* QtLink: TKLink over a rich-text QLabel that opens its link (toolkit links).
* Edit menu: edit roles call the focused line edit's or plain text edit's own slot, no shortcut (HumanInput slice 29).

## 2026-10-09

* Canvas clicks: `QtCanvasClicks` posts `view.clicked` with the point for a press and release on a canvas (HumanInput slice 28). [windows and menus](architecture/windows-and-menus.md)
* Context menus: `QtContextMenu`, a `QMenu` popped up by `QtRightClicks` for the primitive or nearest group with a menu, the widget's own menu kept shut; items post `MenuActivated` (HumanInput slice 27). Requires ext-qt `QMenu::popup`. [windows and menus](architecture/windows-and-menus.md)
* Right-click mail: `QtRightClicks` posts `view.right-clicked` / `window.right-clicked` for Qt's context-menu request (right button, Ctrl-click on macOS) and a touchscreen long press; `QtWindow::contentArea()` (HumanInput slice 26). [windows and menus](architecture/windows-and-menus.md)
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
