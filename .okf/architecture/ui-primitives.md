---
type: Module
title: UI primitives
description: The Qt column, row, fixed, label, button, check box, switch and dropdown on Topside's TK classes, and the native widget each one is.
resource: src/UI/
tags: [qt, primitives]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-21T18:58:43Z }
sources:
  - id: native
    resource: src/UI/Concerns/QtNative.php
    title: QtNative
  - id: box
    resource: src/UI/Concerns/QtBoxChildren.php
    title: QtBoxChildren
---

# Classes

Each extends Topside's class (`TKColumn`, `TKRow`, `TKFixed`, `TKLabel`, `TKButton`, `TKCheckbox`, `TKToggle`, `TKDropdown`, `TKTextInput`, `TKComboBox`, `TKDatepicker`, `TKLink`, `TKSeparator`, `TKProgressBar`, `TKSpinner`, `TKSlider`, `TKToggleButton`, `TKSegmentedControl`, `TKImage`, `TKScrollView`, `TKTabView`, `TKSplitView`, `TKGrid`, `TKTable`, `TKTextArea`, `TKListView`, `TKCollectionView`, `TKTree`) and fills in its hooks; the driver's `$primitives` map names them. Fonts and alignment of what shows text are in `QtText`. Shared native work is in `QtNative`, what column and row share in `QtBoxChildren`.[^native]

| Class | Native |
|---|---|
| `QtColumn`, `QtRow` | `QWidget` with a `QVBoxLayout` / `QHBoxLayout`, a trailing stretch that gives way when a child fills; a child's stretch and alignment set in the layout (`childLayoutChanged()`); padding as the layout's margins |
| `QtFixed` | `QWidget` without a layout: child widgets at their frames (`setGeometry`); its minimum size covers every frame |
| `QtLabel` | `QLabel` with plain text, left-aligned at build; font (in pt) and colour in its style sheet |
| `QtButton` | `QPushButton` with Qt's own focus policy; `&` doubled; `clicked()` calls `clicked()`; a border is set with a background so the bezel does not cover it |
| `QtCheckbox` | `QCheckBox`; `toggled(bool)` reports the new state (Qt emits it for `setChecked()` too, which topside ignores as the state it holds) |
| `QtToggle` | Qt has no switch: a horizontal `QSlider` with two positions (0 off, 1 on), fixed 40×22, styled as a pill with a round knob through `subControlSheet()`; `valueChanged(int)` reports the state |
| `QtDropdown` | `QComboBox`; `currentIndexChanged(int)` reports the choice, ignored while its items are replaced (`clear()` and `addItems()` emit it) |
| `QtTextInput` | `QLineEdit` |
| `QtComboBox` | editable `QComboBox` with an unfiltered `QCompleter`; one Return (two `returnPressed`, maybe an `activated`) is one submit. Qt closes the popup when the app stops being active |
| `QtDatepicker` | `QDateEdit` with calendar popup; its `clicked(QDate)` reports picking the day shown |
| `QtLink` | rich text `QLabel` with one escaped anchor and `openExternalLinks` |
| `QtSeparator` | `QFrame` line |
| `QtProgressBar` | `QProgressBar`; a range of nothing is indeterminate |
| `QtSpinner` | Qt has none: a short indeterminate `QProgressBar` over a spacer, hidden while stopped |
| `QtSlider` | `QSlider` over 10000 steps |
| `QtToggleButton` | checkable `QPushButton` |
| `QtSegmentedControl` | exclusive `QToolButton`s |
| `QtImage` | `QLabel` over a spacer of the natural size, scaled on resize; a FIT height follows the width |
| `QtScrollView` | `QScrollArea`, widget resizable |
| `QtTabView` | `QTabWidget` |
| `QtSplitView` | `QSplitter`; `splitterMoved` is the user's alone; a resize puts the fraction back |
| `QtGrid` | `QGridLayout`; an empty last row and column take spare room while nothing fills |
| `QtTable` | `QTableWidget`, whole rows, one at a time |
| `QtTextArea` | `QPlainTextEdit`; styles as extra selections, which follow the user's edits |
| `QtListView` | `QListView` painted by a `QPaintDelegate` (`QtItemView`) |
| `QtCollectionView` | the same, icon mode, wrapping; a click activates |
| `QtTree` | `QTreeWidget`; a header with no titles gets no height by style sheet (ext-qt binds no `setHeaderHidden`) |

# Notes

* Resizing the window holds the pump until release on macOS (measured by hand 2026-11-22: 3.6 s with no pump return), so timers and mail wait; a repeating timer then fires once and skips the beats it missed.
* Styling: one style sheet per widget, scoped to its object name, so it does not reach the widgets inside it.
* Opacity and transitions: a `QGraphicsOpacityEffect` parented to the widget; transitions are `QPropertyAnimation`s Qt runs (fade, and a `pos` slide).
* Release: `setParent(null)` + `deleteLater()`, flushed by the session's pump.
* `nativeFocus()` refuses a `NO_FOCUS` widget (`setFocus()` ignores the policy). `hasFocus()`: the window's `focusWidget()` (ext-qt 12940eb).

# Checked

List, collection and tree (2026-11-22, a scratch harness, NTS and ZTS, and by screenshot): code changes post nothing; select, open, close and activate post; disabled items post nothing; removal. All sixteen primitives of that day's three batches also render from one Sharp file.

Table and text area (2026-11-22, a scratch harness, NTS and ZTS): fill, focus, code changes post nothing, the user's selection and typing post, line selection, scroll to a line, removal.

Scroll view, tab view, split view and grid (2026-11-22, a scratch harness, offscreen on NTS and ZTS, earlier in real windows): sizes, fill through a plain row, code changes post nothing, tab choice, removal. Slider and divider dragged by hand: live.

Separator, progress bar, spinner, slider, toggle button, segmented control and image (2026-11-22, a scratch harness, 25 checks in a real window on NTS and ZTS): sizes in a column and a row; code changes post nothing; the user's slide, press and choice post their mail; disabled posts nothing; PNG and SVG show, another file is refused; removal. Looked at in a screenshot.

Text input, combo box, date picker and link (2026-11-21, a scratch real-window harness, 30 checks, active and inactive window): code changes post nothing; typing, Return, option, completion and pick post one mail each; completions keep focus and hide on Esc, choice and focus leaving; disabled posts nothing; removal. The link is not clicked.

Check box, switch and dropdown (2026-11-21, a scratch real-window harness, 11 checks): changes from code post nothing; the user's toggle, flip and choice each post one mail with the new value; new options with a selection post nothing; nothing from inside a disabled column; removal. Driven by click(), setValue() and setCurrentIndex(), which emit the signals a user's action does.

The real-window harness (a scratch topside-dev, 35 checks) through these classes passes: building, natives inside their containers, fixed frames, label text, alignment and font, a native click posting `ButtonClicked`, the enabled cascade, `focus()` moving between two buttons with `hasFocus()`, reordering, transitions, text colour, removal, close. Commit f743cc7, 7dd0367.

[^native]: QtNative
