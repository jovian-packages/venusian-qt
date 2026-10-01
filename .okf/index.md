---
okf_version: "0.2"
---

# jovian/venusian-qt

* [Session](architecture/session.md) - QApplication session: construct once, stay up with no windows, budgeted dispatcher pump, deferred deletes, one-shot notifier wake.
* [Windows and menus](architecture/windows-and-menus.md) - QMainWindow per name, event-filter mail, QMenuBar with roles, parentless default bar on macOS, About box.

# API

* [Config](api/config.md) - bridge.qt keys read by the driver.

# Runbooks

* [Testing](runbooks/testing.md) - Pest suite against the real toolkit in a workbench of path repos; Mac and Pi.
