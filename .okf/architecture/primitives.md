---
type: Module
title: Primitives
description: Qt concretes of Surface's TK primitives - factory, containers over Qt layouts, style-sheet styling, event-filter resize mail, leaf signals to view mail, table and video.
resource: src/Primitives/
tags: [qt, primitives, widgets, layout]
status: draft
generated: { by: grok/4.7, at: 2026-10-05T01:30:00Z }
sources:
  - id: trait
    resource: src/Primitives/Concerns/QtPrimitive.php
    title: QtPrimitive (shared hooks)
  - id: factory
    resource: src/Primitives/QtPrimitiveFactory.php
    title: QtPrimitiveFactory
  - id: containers
    resource: src/Primitives/
    title: QtColumn, QtRow, QtGrid, QtFixed, QtScrollView
  - id: video
    resource: src/Primitives/QtVideo.php
    title: QtVideo
  - id: table
    resource: src/Primitives/QtTable.php
    title: QtTable
---

# Overview

Every concrete = Surface abstract (`Surface\Windows\Primitives\TK*`) + `QtPrimitive` trait + `QtNative` (`native(): QWidget`, `qtAlignment(): int`, `fills(): array{bool, bool}`). Containers also `QtContainer` (`placeChild`). Ctor: `parent::__construct()` first (validates), then builds the widget from the constructed state.[^trait]

`QtPrimitiveFactory` (one per window, `QtWindow::factory()`): mint = `new Qt<Kind>($name, $window, $host, $host?->takePlacement() ?? Placement::next(), …)`. Mints declare the **contract** return types: a concrete return type (`mintColumn(): QtColumn`) makes PHP autoload the concrete while it is still linking `TKPrimitive` → fatal "Class TKGroup not found". `toggle()` / `spinner()` throw `WindowException("TK<Kind> is not available on qt.")`: Qt has no switch or spinner widget.[^factory]

# Shared hooks

| Hook | Qt |
|---|---|
| visible / enabled | `setVisible` / `setEnabled` |
| background, font, text colour | one style sheet per widget, `#tk_<uuid hex> { … }` on its object name (never cascades to children); background also sets `WA_StyledBackground` (a plain `QWidget` paints no style-sheet background without it); push buttons (button, toggle button) also get `border: 1px solid <colour>` + padding, since a QPushButton's native bezel covers a style-sheet background; font = `font-size: Npt`, `font-weight` (CSS weight), `font-family` |
| fill | "expand into spare space": the policy lets a filled axis grow (`EXPANDING`), the container's stretch factors decide who takes the spare space (see Containers); unfilled → the widget's own Qt default policy (`naturalPolicy()`, read from Qt 6.11: label/QWidget Preferred×2, button/checkbox/date edit Minimum×Fixed, combo Preferred×Fixed, slider/line edit/progress Expanding×Fixed, text area/table/scroll area Expanding×2, line separator Minimum×Fixed or Fixed×Minimum) |
| align | `Align` → `Qt::AlignmentFlag` (START→LEFT/TOP, CENTER→H/V_CENTER, END→RIGHT/BOTTOM, FILL→0); the parent re-places: box `QLayout::setAlignment`, grid remove + re-add in its cell, fixed/scroll no-op (frames / viewport place children), content → `QtWindow::placeContent` |
| minSize | `setMinimumSize` |
| watchSize | `QEventFilter` for `RESIZE` on the widget → `postLatest("view.resized.<window>.<path>", ViewResized)`; off → filter removed + `forgetLatest` |
| remove | `setParent(null)` (a widget leaving its parent leaves the parent's `QLayout`) + `deleteLater()` (run by the session pump) |
| size | `QWidget::size()` |

Native writes during teardown go through `whileNativeAlive()`: only the close path that starts from the window's `destroyed()` meets natives Qt already deleted. Code-driven setters write under `applying()`; every signal handler asks `handling()` (not applying, not removed) first, so an echo of a code write, or a signal from a removed primitive whose widget still waits for its deferred delete, posts nothing. User-driven signals post after the `native*()` callback records state.[^trait]

Event-filter closures that outlive a call (image resize, window close/activate/resize) hold their owner through a `WeakReference`: a strong `\$this` capture is a cycle through the C++ filter that PHP's collector cannot see, and parenting the filter to the watched widget instead lets Qt's own teardown free the owner mid-destruction. The filter wrapper stays PHP-owned and is freed with its owner.

# Containers

| Kind | Native | Insert |
|---|---|---|
| column / row | `QWidget` + `QVBoxLayout` / `QHBoxLayout` (spacing, padding = contents margins), then a trailing `addStretch(1)` spacer | `insertWidget(count - 1, child, 0, alignment)` (before the spacer); reorder = `removeWidget` + `insertWidget(index, …)`; stretch 1 for children filling the stack's axis, the spacer 1 only when none does, so an unfilled child keeps its size hint |
| grid | `QWidget` + `QGridLayout` | `addWidget(child, row, column, rowSpan, columnSpan, alignment)`; row/column stretch 1 where a child fills that axis, else an empty spare row/column past the last takes the spare space |
| fixed | `QWidget`, no layout | `setParent(fixed)` + `setGeometry(frameOf(child))` + `show()` (a child given an already-showing parent stays hidden otherwise); move/resize → `move`/`resize`; minimum = max(`minSize()`, the frames' extent), since a layout-less widget has no size hint to keep it open beside a filling sibling |
| scroll view | `QScrollArea`, `setWidgetResizable(true)` | `setWidget(child)`; scrollbars → `AS_NEEDED` / `ALWAYS_OFF` |

[^containers]

# Leaves

| Kind | Native | Mail |
|---|---|---|
| label | `QLabel`, `PLAIN_TEXT` (AutoText would render HTML-looking text); alignment keeps vertical centring (`LEFT|V_CENTER`…) | none |
| button | `QPushButton`, text with `&` doubled (Qt's mnemonic marker) | `clicked(bool)` → `ButtonClicked` (disabled: Qt swallows the click) |
| image | host `QWidget` + `QGridLayout`: one cell holds a `QSpacerItem` at the file's size and an Ignored×Ignored `QLabel`; `QPixmap::load` (false → `WindowException`, image left empty) | none |
| canvas | host `QWidget` + `QGridLayout` holding one Ignored×Ignored `QLabel` with scaled contents, so the layout sizes it whatever the framebuffer's size. `present()` → `QImage` in `RGBX8888` (a copy; alpha ignored) → `QPixmap::fromImage` → `setPixmap`. An ext-fb framebuffer is piped: the `QImage` copies its memory by address and no pixel byte passes through PHP. Scale = `devicePixelRatioF`. On macOS with ext-appkit and ext-metal, lends a Metal layer to a GPU engine (WindowOutput): a `QWindow` with `SurfaceType::METAL_SURFACE` in a `createWindowContainer` widget over the hidden label; Qt hosts its `CAMetalLayer` as the content sublayer of a container layer on the window's `NSView` (`winId`), reached through ext-appkit's `NSView::fromPointer()->layer()->sublayers()`. Qt leaves the drawable size at zero; the device sets it at `present()`. `reclaim()` deletes the container and shows the label. With ext-sdl3 too, lends an SDL window to the sdl3 engine: the same Metal `QWindow` container, SDL wrapping Qt's `NSWindow` (`SDL.window.create.cocoa.window`, content view unchanged; SDL 3.4 makes any view it is handed the content view), SDL_GPU's swapchain view moved from the content view into the Metal `QWindow`'s view at the first present and pinned to its edges, its drawable set to the canvas's pixel size every present; it composes over Qt's empty Metal layer. SDL video started by the canvas, never quit. SDL requires release from the GPU device before `SDL_DestroyWindow`, and the lend is reclaimed before the device lets go: a window whose swapchain view is still attached is parked (the view hidden back in the content view, since the container goes) and destroyed by the first sweep (session pump, canvas present, SDL lend) after SDL removes the view. SDL makes its listener the next responder of the window and its content view; the canvas restores both links right after creating the SDL window. Qt's own application delegate keeps the slot SDL would take. With ext-opengl, lends a GL context on every platform: a `QOpenGLPainter` (ext-qt's `QOpenGLWidget` trampoline) over the hidden label, asked for OpenGL 4.1 core on macOS (Qt's default there is a legacy 2.1 context) and OpenGL ES 3 elsewhere (the Pi's default desktop context is GL 3.1, GLSL 1.40); the window must be shown first (`WindowException` "has no GL context yet: present the window first"), Qt is pumped up to 2 s until the widget is valid, and its context is read back (`CGLGetCurrentContext()` on macOS, `eglGetCurrentContext()` + `eglGetCurrentDisplay()` elsewhere); whatever context was current before the lend is put back. Off macOS the GL context is offered only where Qt's GL is EGL: Wayland, eglfs, or xcb with `QT_XCB_GL_INTEGRATION=xcb_egl` (Qt's X11 default is GLX, which ext-opengl cannot read). `present()` calls `update()`; `paintGL()` (context current, `defaultFramebufferObject()` bound) calls the borrower's `presentInto()` and begins the frame's epoch, so Qt's own repaints copy the last frame again; with no borrower it clears to black. `reclaim()` hides and `deleteLater()`s the widget and shows the label. With ext-vulkan and a Qt built with Vulkan, lends a Vulkan surface first: `lendsVulkan()` probes once a process (a throwaway `QVulkanInstance` of Qt's own, made and destroyed; false and asked again before the application exists); on macOS it holds only when `QT_VULKAN_LIB` names the loader (Qt loads it by name; Homebrew's is outside dyld's search path). The lend needs the window shown ("has no Vulkan surface yet: present the window first") and the borrower's `instance` handle: a `QVulkanInstance` over that `VkInstance` (Qt adopts it and never destroys it), a `QWindow` with `SurfaceType::VULKAN_SURFACE` and that instance in a `createWindowContainer` widget over the hidden label, created, Qt pumped up to 2 s until exposed, `QVulkanInstance::surfaceForWindow()` handed out as `surface`; the device checks its queue can present into it. `presentLent()` is the parent's: the device's swapchain copy. `reclaim()`: the device has dropped its swapchain (the surface's release runs first); the canvas destroys the window's platform window (and with it Qt's `VkSurfaceKHR`, while the device's instance still lives), takes the instance off the window and destroys it, so nothing a caller still holds reaches Qt's surface, then deletes the container and shows the label. A device let go first (released, or freed before its engine) drops its swapchain and releases the surface itself: the canvas's own `onRelease()` then reclaims. `lendsVulkan()` also requires Qt to load the same Vulkan loader ext-vulkan calls: a `QT_VULKAN_LIB` that does not resolve to `vk_loader_path()` is refused; unset, Linux's system loader by name is the same one and macOS finds none. Verified on Qt over Wayland and over xcb on the Pi (the device's instance enables the xcb and Xlib surface extensions). Verified on macOS (MoltenVK) and the Pi (Qt on Wayland, V3DV). `surfaces()` = `[VULKAN_SURFACE]` where Qt makes Vulkan instances; then `[METAL_LAYER, SDL_WINDOW]` with ext-sdl3, `[METAL_LAYER]` without, nothing off macOS; then `GL_CONTEXT` with ext-opengl. |
| separator | `QFrame` `H_LINE`/`V_LINE`, `SUNKEN` | none |
| progress bar | `QProgressBar` 0..1000, text hidden; null → range 0..0 (busy) | none |
| text input | `QLineEdit`; secret → `PASSWORD` echo | `textChanged(QString)` → `TextChanged`; `returnPressed()` → `TextSubmitted` |
| text area | `QPlainTextEdit` | `textChanged()` → `TextChanged` (`toPlainText()`) |
| checkbox | `QCheckBox`, `&` doubled | `toggled(bool)` → `Toggled` |
| toggle button | checkable `QPushButton`, `&` doubled | `toggled(bool)` → `Toggled` |
| slider | `QSlider(HORIZONTAL)` (ext-qt keeps Qt's vertical default), 0..10000 ↔ [min, max], single step 100 and page step 1000 (a hundredth / a tenth of the range) | `valueChanged(int)` → `ValueChanged(min + (max-min)·step/10000)` |
| dropdown | `QComboBox`; options → `clear` + `addItems` under `applying` | `currentIndexChanged(int)` (≥ 0) → `SelectionChanged` |
| datepicker | `QDateEdit`, calendar popup, minimum date 0100-01-01 (Qt's default floor is 1752-09-14); years outside 100..9999 → `WindowException`; null date shows today (Qt's own default is 2000-01-01) | `dateChanged(QDate)` (ISO string from ext-qt) and the popup calendar's `clicked(QDate)` → `DateChanged` at midnight, once per new date: clicking the day already shown on an unset picker is a pick dateChanged never reports |

Image scaling: the host's natural size is the spacer's (the file's), never the last scaled pixmap's, so the slot shrinks and grows back; the label ignores its own hint and gets the whole cell. FIT / FILL re-scale the loaded pixmap (`KEEP` / `KEEP_BY_EXPANDING`, `SMOOTH_TRANSFORMATION`, at the label's `devicePixelRatioF()`, ratio set on the result) on every label `RESIZE` once it has had its first; CENTER unscaled, centred; STRETCH `setScaledContents(true)`. `label()` exposes the QLabel.

# Table

`QTableWidget`, whole rows, single selection, `NO_EDIT_TRIGGERS`, header labels from the columns. `applyRows` → `clearContents` + `setRowCount` + `setItem` per normalised cell; `applySelectedRow` → `selectRow` / `clearSelection`. `itemSelectionChanged()` → row from `selectedItems()[0]->row()` (none → null; `currentRow()` would keep the old row after a Ctrl-click deselect) → `RowSelected(row, cells)`.[^table]

# Video

`QVideoWidget` + `QMediaPlayer` + `QAudioOutput`, player and output children of the widget. Creation throws `WindowException("TKVideo needs a Qt Multimedia backend plugin (Debian: libqt6multimedia6; Homebrew: qt).")` when `QMediaPlayer::isAvailable()` is false (no backend plugin loaded).[^video]

Playing = `PLAYING` state **and** `BUFFERING`/`BUFFERED` media, recomputed on every state change, status change and error. macOS's backend enters `PLAYING` on `play()` while the file still loads and stays there when it fails; the FFmpeg backend (Pi) waits for `LOADED`. So a failing file posts `VideoFailed` and never `VideoPlaying` on both. Transitions: true → `VideoPlaying`, false → `VideoPaused`; `END_OF_MEDIA` → `VideoPaused` then `VideoEnded` (`isPlaying()` already false). Loop → `setLoops(INFINITE_LOOPS)` (no end reported); seek → `setPosition(ms)`; muted → `QAudioOutput::setMuted`. Remove stops the player before the widget's `deleteLater()`; handlers post nothing once removed.

[^trait]: QtPrimitive (shared hooks)
[^factory]: QtPrimitiveFactory
[^containers]: QtColumn, QtRow, QtGrid, QtFixed, QtScrollView
[^video]: QtVideo
[^table]: QtTable
