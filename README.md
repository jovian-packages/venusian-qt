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
