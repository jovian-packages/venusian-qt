# Log

## 2026-10-06

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
