# Log

## 2026-10-02

* New: [primitives](architecture/primitives.md) — factory, containers, shared hooks, leaves, table, video.
* Review fixes: fill = spare space by stretch factors (stack spacer, grid spare line); fixed covers its frames; image host sized by a spacer, smooth DPR scaling; literal button/label text; slider steps; date picker range 100..9999 and today-pick; `handling()` guard; push-button backgrounds with a border; filter closures hold owners weakly (no leaked images or closed windows). [Testing](runbooks/testing.md): lifetime test, `pendingLatest()`.
* [Windows and menus](architecture/windows-and-menus.md): content hosting, `centralWidget()`, coalesced `WindowResized`, close removes the content tree. [Testing](runbooks/testing.md): `pumpFor()` through `ToolkitPump`, primitive coverage, `afterEach` close.

## 2026-10-01

* Bundle created: [session](architecture/session.md), [windows and menus](architecture/windows-and-menus.md), [config](api/config.md), [testing](runbooks/testing.md).
