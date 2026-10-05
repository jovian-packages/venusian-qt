# jovian/venusian-qt

Qt 6 toolkit driver for the Venusian Surface bridge.

## Canvas

`QtCanvas` shows a `TKCanvas`'s framebuffer as a `QLabel`'s pixmap: the RGBA8 bytes become a `QImage` in the `RGBX8888` format (the fourth byte ignored, so opaque), stretched over the label.

An ext-fb framebuffer is piped. `present()` hands the framebuffer's memory to `QImage` by address, and Qt copies what it needs. No pixel byte passes through PHP. A framebuffer held in PHP (the native driver) takes the same path from a string.
