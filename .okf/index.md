---
okf_version: "0.2"
---

# jovian/venusian-qt

* [Session](architecture/session.md) - QApplication built once, kept up with no windows, a budgeted dispatcher pump with deferred deletes, and a one-shot notifier wake.
* [Window](architecture/window.md) - QMainWindow with a central widget, wired to Topside's window lifecycle through event filters; resizes from the central widget.
* [Menu bar](architecture/menu-bar.md) - One QMenuBar per window and a parentless one for none focused; how nodes become QActions and how Qt's macOS application menu is filled.
* [UI primitives](architecture/ui-primitives.md) - The Qt primitives on Topside's TK classes, the native widget each one is, and which optional settings Qt shows natively.

# API

* [Config](api/config.md) - config/qt.php keys read by the driver and the toolkit.

# Runbooks

* [Testing](runbooks/testing.md) - Pest suite against the real toolkit in a workbench of path repos; Mac and Pi.

# Deprecated

* [Windows and menus](architecture/windows-and-menus.md) - the bridge-era window, menus, focus and keys (deprecated2/).
* [Primitives](architecture/primitives.md) - the bridge-era Qt primitives (deprecated2/Primitives/).
