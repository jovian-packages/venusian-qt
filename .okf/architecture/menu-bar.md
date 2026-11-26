---
type: Module
title: Menu bar
description: One QMenuBar per window and a parentless one for none focused - how nodes become QActions, how Qt's macOS application menu is filled, and what Qt decides there.
resource: src/Menus/
tags: [qt, menus, macos, linux]
status: draft
generated: { by: claude-fable/5.2, at: 2026-11-26T05:30:00Z }
sources:
  - id: bar
    resource: src/Menus/QtMenuBar.php
    title: QtMenuBar
  - id: shortcut
    resource: src/Menus/QtShortcut.php
    title: QtShortcut
  - id: tests
    resource: tests/Windowless/MenuBarTest.php
    title: Windowless menu tests
---

# Overview

`QtMenuBar` is topside's `TKMenuBar` on Qt: it is told single operations and keeps no rule of the menus. The driver names it. A window's bar is its `QMainWindow::menuBar()`; the bar for no window focused is a parentless `QMenuBar`. Qt shows the bar itself, inside the window on Linux and in the macOS system bar for the active window, so `show()` does nothing. `QtWindow` reports `WINDOW_ACTIVATE` and `WINDOW_DEACTIVATE` to topside so it knows whose bar answers.[^bar]

Only Qt's own menu API is used. Nothing reads or writes the macOS bar beside Qt.

# Natives

| What | How |
|---|---|
| Owner | every `QAction` and `QMenu` is a child of the bar and is `deleteLater()`d when its node goes, so a node keeps its natives when it moves |
| A node's place | `QWidget::insertAction()` before the first native of the next sibling that has one |
| A menu | a `QMenu`, put in its parent by its `menuAction()` |
| A choice | one checkable action per option, in no `QActionGroup`: topside owns the mark |
| A toggle or choice chosen | Qt has flipped the action already; the mark is put back and `activated()` reports, and topside sets it through `change()` |
| Label | `&` is doubled: Qt reads one as a mnemonic |
| Edit kinds | the focused widget's own slot (`QLineEdit`, `QPlainTextEdit`); another widget takes nothing. Paste and Match Style is `paste()`: those widgets hold plain text only |
| Close Window | the window's `close()`, so its close event and confirm path run |
| Minimize, full screen | `supports()` is false: ext-qt binds no call for either. Topside shows them disabled and logs once |
| About panel | `QtSession::showAbout()`: Qt has no About panel, so a `QMessageBox`, not modal, one at a time |
| Shortcut | Qt's portable text. Primary is `Ctrl` (Command on macOS); Ctrl is `Meta` on macOS and `Ctrl` elsewhere |

# The macOS application menu

Qt owns it and fills it from actions that carry a menu role. Nodes directly under the menu topside marks `application` get one; every other action has `NO_ROLE`, so Qt moves nothing by its label and an item stays in the menu it was written in.

| Node in the application menu | Role | What shows |
|---|---|---|
| About | `ABOUT_ROLE` | first, labelled by Qt (`About <process>`) |
| Preferences | `PREFERENCES_ROLE` | after About, labelled by Qt |
| Quit | `QUIT_ROLE` | last, labelled by Qt |
| Hide, Hide Others, Show All | no native | Qt always puts its own there, with Services |
| any other item, toggle, choice | `APPLICATION_SPECIFIC_ROLE` | after Preferences, with its own label, in the order written |
| a line | none | Qt draws its own lines |

* Qt adds an application-specific action after the ones already there, whatever it was inserted before. After an insert or a move the later siblings are taken out and put back in order.
* Qt shows a Quit of its own when the bar has none. While the application menu holds no Quit, an invisible action with the Quit role sits in a menu that never shows (`$roles`), and Qt shows no Quit. It is taken out before the application's Quit goes in: put in after, Qt keeps the item hidden until the next change.
* Qt hides a bar menu whose items all went to the application menu, so the `QMenu` that holds them is not seen.
* Hide, Hide Others or Show All written outside the application menu is shown disabled: Qt has no call for them.

Measured 2026-11-22 on Qt 6.11.2, 8.4 NTS and ZTS, in the real system bar with no window shown. Not measured: the swap between two visible windows, and a key press.

# Not here

Removing Services, Hide, Hide Others and Show All, relabelling About, Preferences and Quit, or putting an item below Qt's block. Qt's public API has none of them; taking Qt's items out with AppKit makes Qt abort on its next menu update (`NSRangeException` in `QCocoaMenuLoader`).

A key filter. Qt's own order stands for every key: the focused widget is asked first, and a text widget keeps the keys it edits with.

[^bar]: QtMenuBar
