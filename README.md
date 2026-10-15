# jovian/venusian-qt

Qt 6 toolkit driver for the Venusian Surface bridge.

## Canvas

`QtCanvas` shows a `TKCanvas`'s framebuffer as a `QLabel`'s pixmap: the RGBA8 bytes become a `QImage` in the `RGBX8888` format (the fourth byte ignored, so opaque), stretched over the label.

An ext-fb framebuffer is piped. `present()` hands the framebuffer's memory to `QImage` by address, and Qt copies what it needs. No pixel byte passes through PHP. A framebuffer held in PHP (the native driver) takes the same path from a string.

On macOS with ext-appkit and ext-metal, a GPU engine borrows a Metal layer instead: `surfaces()` starts with `METAL_LAYER`. The canvas puts a `QWindow` with a Metal surface in a window container over its label and lends the `CAMetalLayer` Qt makes for it (the content sublayer of Qt's container layer, reached through ext-appkit) to the `metal` engine (jovian/venusian-metal). Frames reach the window with no pixel through PHP. `reclaim()` removes the container and the label shows the canvas's own framebuffer again. Elsewhere those two kinds are not offered.

```php
$engine = app('drawing')->renderer('metal', ['output' => $canvas]);
$engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(16, 24, 32))->fillEllipse(160, 120, 40, 40, Color::rgb(255, 128, 0)));
$canvas->present();
```

With ext-sdl3 loaded as well, `surfaces()` is `[METAL_LAYER, SDL_WINDOW]`, and the `sdl3` engine (jovian/venusian-sdl3) draws into the canvas too. SDL wraps Qt's window and leaves its layout alone; the swapchain view SDL_GPU makes is moved into the same Metal `QWindow` over the label and follows the canvas's size. The canvas starts SDL's video subsystem and never quits it. SDL's listener is taken back out of the window's responder chain; Qt's application delegate keeps its slot. After `reclaim()` the SDL window is destroyed by the session's next pump once the engine's device has let go of it.

```php
$engine = app('drawing')->renderer('sdl3', ['output' => $canvas]);
```

With ext-opengl loaded, on every platform, the canvas lends a GL context too, last in `surfaces()`: a `QOpenGLWidget` takes the label's place, asked for OpenGL 4.1 core on macOS and OpenGL ES 3 elsewhere, and the `opengl` engine (jovian/venusian-opengl) draws in its context. `present()` schedules a paint, and the widget's `paintGL()` copies the engine's frame into its framebuffer on the GPU. Present the window first: Qt makes the context when the widget is first shown. On Linux the context is offered under Wayland or eglfs; under X11, only with `QT_XCB_GL_INTEGRATION=xcb_egl` set before the application starts (Qt's X11 default, GLX, is not EGL).

```php
$canvas = $window->column('m')->canvas('view')->fill();
$window->present();
$engine = app('drawing')->renderer('opengl', ['output' => $canvas]);
$engine->frame(fn ($g) => $g->clear(Color::rgb(16, 24, 32))->fillEllipse(160, 120, 40, 40, Color::rgb(255, 128, 0)));
$canvas->present();
```

With ext-vulkan loaded and a Qt built with Vulkan, the canvas lends a Vulkan surface first in `surfaces()`: a `QWindow` with a Vulkan surface takes the label's place, its `QVulkanInstance` set over the engine's own `VkInstance`, and the `vulkan` engine (jovian/venusian-vulkan) presents into the `VkSurfaceKHR` Qt makes for it through its swapchain. Present the window first. On macOS Qt loads the Vulkan loader by name and Homebrew's is outside dyld's search path: set `QT_VULKAN_LIB` before the application starts (`export QT_VULKAN_LIB="$(pkg-config --variable=libdir vulkan)/libvulkan.1.dylib"`). Without it, or when it names another loader than the one ext-vulkan calls (`vk_loader_path()`), the canvas offers no Vulkan surface there, and the `vulkan` engine presents through the Metal layer instead (MoltenVK). On Linux it works under Wayland and X11 (xcb) alike. If the engine's device is let go before the canvas reclaims, the canvas takes the surface back itself.

```php
$engine = app('drawing')->renderer('vulkan', ['output' => $canvas]);
```

## Edit menu

A profile's edit roles call the focused `QLineEdit`'s or `QPlainTextEdit`'s own slot: `undo`, `redo`, `cut`, `copy`, `paste`, `selectAll`. They take no shortcut: Qt's text widgets already take the standard keys (Command on macOS), and a table keeps its own Select All key.

## Links

`link(name, text, url)` is a rich-text QLabel holding one anchor, with `openExternalLinks` on: Qt opens the URL when it is clicked or activated from the keyboard, in the browser for http and https, and nothing posts. Text and URL are HTML-escaped, so each shows and opens as written. A disabled link is disabled.

## Right-click mail

A right click in a window posts mail naming what was clicked: `view.right-clicked.<window>.<path>` for the innermost primitive under the pointer (`x`, `y` in that primitive), or `window.right-clicked.<window>` on bare content (`x`, `y` in the content area). A right click is the right button, Ctrl-click on macOS, or (on Linux) a left press held still for Qt's press-and-hold interval, which is how a touchscreen registered as a pointer right-clicks; the keyboard menu key posts nothing, and neither does a disabled primitive. Native context menus (a text field's edit menu) still open.

```php
foreach ($mail as $m) {
    if ($m instanceof \Surface\Contracts\Windows\Mail\View\RightClicked) {
        $this->menuFor($m->path, $m->x, $m->y);
    }
}
```

## Text areas

`textArea(name, value)` is a `QPlainTextEdit`. Read-only keeps mouse and keyboard selection; wrap is the widget width or none; a monospace font with no family names the system fixed font (`QFontDatabase`). Line backgrounds (full width) and range colours are extra selections painted over the text, so the document and its undo stack never change, and a new value clears them. Line selection and the selection read back count UTF-16 like Qt, so accented letters and emoji land on the right characters. The scroll position is the scroll bars' as content fractions; while watched, `view.scrolled.<window>.<path>` posts `ViewScrolled` for the user's scrolls (bar, wheel, keyboard, a resize) and never for the area's own setters.
