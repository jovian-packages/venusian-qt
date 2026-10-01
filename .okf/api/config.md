---
type: Reference
title: Config
description: bridge.qt keys read by the driver.
resource: src/Bridge/QtBridgeDriver.php
tags: [qt, config]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-01T20:06:29Z }
---

# Schema

Read through the container's `config`; absent from Surface's published `config/bridge.php`, defaults in code.

| Key | Default | Meaning |
|---|---|---|
| `bridge.qt.application_name` | `Venusian` | name macOS shows in the application menu |
| `bridge.qt.desktop_file_name` | `org.venusian.Surface` | desktop identity: Wayland app_id, the .desktop file it matches |
| `windows.about.*` | Surface config | About box fields |
