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

## Menu state and accelerators

`$window->setItemEnabled('view.refresh', false)` disables a node of the window's bar; `isItemEnabled()` reads its own state back. The driver's `setItemEnabled()` does the same for the default bar (macOS shows it). A folder that is disabled disables every node below it; when it is enabled again, each node below it returns to its own state. A node that is not enabled is a disabled `QAction` (a folder: its menu's `menuAction()`): it shows as disabled, posts nothing, and its shortcut does nothing. The calls post nothing. `'enabled' => false` in a profile or a context menu makes a node start disabled.

`'keys' => 'Primary+Shift+F5'` becomes Qt portable text through `QtMenuBar::shortcut()`: on macOS Qt's `Ctrl` is Command and Qt's `Meta` is Control, so Primary is `Ctrl` everywhere. `QtMenuKeys`, an application filter the driver installs when it connects, eats every `ShortcutOverride` event, so a focused `QLineEdit` or `QPlainTextEdit` never keeps a key from a shortcut; a key no shortcut takes still reaches the widget.

## Child windows, close confirmation, button roles

`driver()->open('goto', 300, 120, null, $main)` opens a child, window-modal to `$main`: a sheet on macOS, a dialog above it elsewhere. `$main->close()` closes the child first. On macOS, while a child is focused, Qt shows the default bar (or an empty one) with its menus disabled.

`$window->confirmClose()` keeps the window open on its close button or Alt+F4 and posts `WindowCloseRequested`; `close()` still closes it.

`$button->setRole(ButtonRole::DEFAULT)` makes Return and keypad Enter click the button, wherever the focus is, except in a text area or a popup; `ButtonRole::CANCEL` does the same for Esc. The default button paints as a default `QPushButton`. Esc in a child with no cancel button closes it (or posts `WindowCloseRequested` while it confirms close).

## Focus and window keys

`$input->focus()` gives the input keyboard focus through `QWidget::setFocus` and posts nothing; `hasFocus()` and `$window->focused()` read it back, also while the window is not active. A native with no focus policy (a label) refuses it. `$window->setInitialFocus($input)` focuses it on each `present()`; Qt's own first focus for a shown window posts nothing either, nor does code that hides or disables the focused widget (Qt passes focus to the next one). The user's moves, by click, Tab or Qt, post `FocusLost` then `FocusGained`.

A canvas takes no focus until `$canvas->setFocusable()` (`Qt::StrongFocus`: click and Tab); `setFocusable(true, takesTab: true)` keeps Tab and Shift-Tab on it.

A key press the focused widget leaves unaccepted travels up to the window, where it posts `WindowKeyPressed` with the physical key (from `nativeVirtualKey` on macOS, `nativeScanCode` on X11), the text (`''` for a function key), the modifiers and the repeat flag. Tab, menu accelerators and the default and cancel buttons' keys never get there.

```php
$window->setInitialFocus($window->view('m.path'));
$window->present();
// loop($mail): WindowKeyPressed('main', Key::F5, '', new Modifiers(), false) when F5 is pressed outside a text input
```

## Dialogs, clipboard, desktop

```php
$id = driver()->dialogs()->alert($main, new Alert('Save changes?', 'Two files', ['Save', 'Discard', 'Cancel']));
// loop($mail): AlertAnswered($id, 0 | 1 | 2) on dialog.alert.<id>; -1 when $main closed first
$id = driver()->dialogs()->openFiles($main, new OpenRequest('Compare', getcwd(), multiple: true));
$id = driver()->dialogs()->saveFile($main, new SaveRequest(name: 'report.txt'));
// loop($mail): FilesChosen($id, [absolute paths]) on dialog.files.<id>; [] when cancelled or $main closed first
driver()->clipboard()->setText('/path/to/file');
driver()->desktop()->open('/path/to/file'); // its default app; InvalidArgumentException when nothing is there
```

`QMessageBox` and `QFileDialog`, window-modal to `$main` (sheets on macOS). Return answers the default button, Esc and the title-bar close the cancel button. A parent holds one modal: a second dialog or a child throws `WindowException` while one is open, and the window manager's close does nothing. `$main->close()` answers the open dialog first.

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

## Split and tab views

`split(name, horizontal)` is a QSplitter whose widgets are the panes, with opaque resize and panes that never collapse under a drag; Qt gives a hidden pane no size and hides its handle, and `positions()` / `setPositions()` are the dividers between visible panes as fractions of the split's length, from `sizes()` and the handle width. Its first layout shares the length equally unless positions were set before it. A user's handle drag posts `view.split-moved.<window>.<path>` (`SplitMoved`) at the button's release, if a handle moved: `splitterMoved` fires only for the user's drags and marks one, and a filter on each handle sees the release. Window resizes and `setPositions()` post nothing.

`tabs(name)` is a QTabWidget with one tab per `addPage()`. A user's tab switch posts `view.tab-selected.<window>.<path>` (`TabSelected`); `select()`, adding the first page and removing the selected one post nothing.

```php
$split = $window->column('main')->split('panes')->fill();
$split->column('left')->textArea('a', $left)->fill();
$split->column('right')->textArea('b', $right)->fill();
$split->setPositions([0.5]);

$tabs = $window->column('main')->tabs('sessions')->fill();
$tabs->addPage('one', 'left.txt ↔ right.txt')->label('hint', 'Drop files here');
```

## Trees

`tree(name, columns, nodes)` is a read-only QTreeWidget that selects one row at a time. It has one QTreeWidgetItem per node, with the node's id in the first column's user role and a coloured cell's foreground set to the node's colour. `item(id)` returns a node's item. The selected node is read from the selected items, so a Ctrl-click deselect reports null.

These post mail, but only for the user's actions:

- a user's selection: `view.node-selected.<window>.<path>` (`NodeSelected`), and only when the selected node changed;
- a user's expand or collapse: `view.node-expanded.<window>.<path>` (`NodeExpanded`);
- `itemActivated` (a double click), or Return or Enter: `view.node-activated.<window>.<path>` (`NodeActivated`). QAbstractItemView activates on Return and Enter everywhere but macOS, so an event filter on the tree takes both keys on every platform, activates the selected node and consumes the key.

`expand()`, `selectNode()` and `setNodes()` post nothing. Qt keeps a hidden node's expansion and shows it again when an ancestor opens. Opening a node, by code or by the user, therefore closes any child that Qt shows expanded but that Surface's record has collapsed.

```php
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TreeNode;
use Surface\NutsAndBolts\Color;

$tree = $window->column('main')->tree('files', [new TableColumn('name', 'Name'), new TableColumn('size', 'Size')], [
    new TreeNode('src', ['name' => 'src'], [new TreeNode('src/a.php', ['name' => 'a.php', 'size' => '2 KB'], colors: ['name' => Color::rgb(200, 0, 0)])]),
])->fill();
$tree->expand('src')->selectNode('src/a.php');
```

## Canvas pointer mail

A canvas that watches the pointer posts what the user does on it, in points from its top-left, whatever draws it (its framebuffer, a GL context, or a Metal or Vulkan window):

```php
$board = $window->column('m')->canvas('board')->fill()->watchPointer();
```

* `view.pressed.<window>.<path>` (`x`, `y`, `button`, `clicks`, `modifiers`) when a button goes down on it. That canvas then owns the press: another button posts nothing until it ends, and `clicks` counts presses of the same button within Qt's double-click interval and distance, so a triple click is 3.
* `view.dragged.<window>.<path>` for each move that changed the point while the button is held, outside the canvas and the window too (the coordinates go negative or past its size).
* `view.released.<window>.<path>` exactly once per press: at the button's release, or at the last point posted when a context menu opens for it. A press the code ends — `watchPointer(false)`, disabling the canvas or a group above it, removing it, closing the window — posts nothing, as no mail comes from code.
* `view.wheeled.<window>.<path>` (`dx`, `dy`, `precise`, `modifiers`) for the wheel over it, which the canvas keeps: a scroll view around it does not scroll. `precise` is true for trackpad points, false for notches (one notch is 1.0). While it is not watching, the wheel scrolls that view as before and nothing posts.

`view.clicked` and the right-click rules post as they do otherwise, watching or not; both work on a canvas lent to Metal or Vulkan too.
