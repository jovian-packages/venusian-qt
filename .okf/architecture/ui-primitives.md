---
type: Module
title: UI primitives
description: The Qt primitives on Topside's TK classes, the native widget each one is, and which optional settings Qt shows natively.
resource: src/UI/
tags: [qt, primitives]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-25T16:17:11Z }
sources:
  - id: native
    resource: src/UI/Concerns/QtNative.php
    title: QtNative
  - id: box
    resource: src/UI/Concerns/QtBoxChildren.php
    title: QtBoxChildren
  - id: margin
    resource: src/UI/Concerns/QtSheetMargin.php
    title: QtSheetMargin
---

# Classes

Each extends Topside's class (`TKColumn`, `TKRow`, `TKFixed`, `TKLabel`, `TKButton`, `TKCheckbox`, `TKToggle`, `TKDropdown`, `TKTextInput`, `TKComboBox`, `TKDatepicker`, `TKLink`, `TKSeparator`, `TKProgressBar`, `TKSpinner`, `TKSlider`, `TKToggleButton`, `TKSegmentedControl`, `TKImage`, `TKScrollView`, `TKTabView`, `TKSplitView`, `TKGrid`, `TKOverlay`, `TKTable`, `TKTextArea`, `TKListView`, `TKCollectionView`, `TKTree`) and fills in its hooks; the driver's `$primitives` map names them. Fonts and alignment of what shows text are in `QtText`. Shared native work is in `QtNative`, what column and row share in `QtBoxChildren`.[^native]

| Class | Native |
|---|---|
| `QtColumn`, `QtRow` | `QWidget` with a `QVBoxLayout` / `QHBoxLayout`, a trailing stretch that gives way when a child fills; a child's stretch and alignment set in the layout (`childLayoutChanged()`); padding as the layout's margins, each side its own, live |
| `QtFixed` | `QWidget` without a layout: child widgets at their frames (`setGeometry`); its minimum size covers every frame |
| `QtLabel` | `QLabel` with plain text, left-aligned at build; font (in pt) and colour in its style sheet |
| `QtButton` | `QPushButton` with Qt's own focus policy; `&` doubled; `clicked()` calls `clicked()`; a border is set with a background so the bezel does not cover it; styles, icon and role under Settings |
| `QtCheckbox` | `QCheckBox`; `toggled(bool)` reports the new state (Qt emits it for `setChecked()` too, which topside ignores as the state it holds) |
| `QtToggle` | Qt has no switch: a horizontal `QSlider` with two positions (0 off, 1 on), fixed 40×22, styled as a pill with a round knob through `subControlSheet()`; `valueChanged(int)` reports the state |
| `QtDropdown` | `QComboBox`; `currentIndexChanged(int)` reports the choice, ignored while its items are replaced (`clear()` and `addItems()` emit it) |
| `QtTextInput` | `QLineEdit`; a search field has Qt's clear button and the theme's search glyph |
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
| `QtGrid` | `QGridLayout`; an empty last row and column take spare room while nothing fills; padding as the layout's margins, each side its own, live |
| `QtOverlay` | `QWidget` with a `QGridLayout`: every child in cell (0, 0) with its alignment (FILL is no flag), padding as the layout's margins, live. The first child is at the back: the widgets are `raise()`d in the children's order after every attach and reorder. A child that fills both ways has `IGNORED` size policy both ways (also kept by `QtNative::applySizePolicy()`), so Qt neither reads its hint nor its minimum hint: it takes the cell and never sizes the overlay; the natural size is the largest of the others. A child's margin is the one it keeps itself (the sheet-margin kinds), as in a column: a nested layout per child would only be needed for kinds Qt gives no margin anywhere |
| `QtTable` | `QTableWidget`, whole rows, one at a time |
| `QtTextArea` | `QPlainTextEdit`; styles as extra selections, which follow the user's edits |
| `QtListView` | `QListView` painted by a `QPaintDelegate` (`QtItemView`) |
| `QtCollectionView` | the same, icon mode, wrapping; a click activates |
| `QtTree` | `QTreeWidget`; a header with no titles gets no height by style sheet (ext-qt binds no `setHeaderHidden`) |

# Settings

Topside's optional hooks, done with Qt's own API where Qt has one; elsewhere the hook stays empty and the setting is not shown (the getter still answers what was set).[^native][^margin]

| Setting | Qt |
|---|---|
| Padding (column, row, grid, tab page) | the layout's `setContentsMargins(left, top, right, bottom)`, at build and on every change |
| Margin: label, link, check box, text input, progress bar | style sheet `margin` (`QtSheetMargin`): Qt's sheet box paints the background inside it and adds it to the size hint, so the layout leaves the space; a label or link has indent 0 while it has a margin |
| Margin: every other kind | not shown (see below) |
| Corners | the four sheet properties `border-top-left-radius` … `border-bottom-left-radius`, a zero corner left out; never the single `border-radius` (`applyCornerRadius()` writes the same four). They round the background and a border |
| Clickable group | every group (column, row, grid, fixed, overlay, scroll view, tab view, split view): a `QEventFilter` on its widget that only watches (returns false). A left press reaching the widget, then a left release inside its rect, calls `groupClicked(x, y)` with the release's position, which Qt gives in the group's coordinates. Qt hands the group a press only when nothing under the pointer accepted it: a button or a line edit accepts its own (no group click), a plain label or a picture ignores it, so it reaches the group. Off, and on release, the filter is removed |
| Move transitions | `MOVE_RIGHT`/`LEFT`/`TOP`/`BOTTOM`: one `QPropertyAnimation` of `pos` from one own width or height toward the edge to where the layout put it, along topside's `TKTransition::curve()`: a `QEasingCurve` of type `BEZIER_SPLINE` with one `addCubicBezierSegment()` (ext-qt c65bd19) from (0, 0) through c1 = (x1, y1), c2 = (x2, y2) to (1, 1); `MOVE_IN_CURVE` (0.22, 1, 0.36, 1, easeOutQuint) appearing; disappearing back out along `MOVE_OUT_CURVE` (0.64, 0, 0.78, 0, easeInQuint), hidden on its `finished()`, then put back at rest. No opacity effect is made. FADE and SLIDE_* run as before (an opacity effect, linear) |
| Clip | not shown: Qt Widgets clip a child to its rectangle only, and a rounded mask would be drawn, not native. The image rounds its own picture (below) |
| Image corners | the label shows the picture as scaled for the label (FIT fitted, FILL covering and cropped to the label, centred, STRETCH stretched, CENTER at its own size, cropped when larger), drawn with `QPainter` into a transparent copy of it through a `QPainterPath` of lines and one `arcTo()` per corner, antialiased; again on every resize and change. Zero corners: the plain path as before |
| Cursor | `setCursor(QCursor(shape))`: arrow, pointing hand, I-beam, cross, size-all, forbidden, open hand, closed hand, size hor/ver, size F/B diagonal; null `unsetCursor()` |
| Tooltip | `setToolTip()`; null is `''` |
| Border | style sheet `border: Wpx solid <colour>`; no colour is `palette(mid)`, read by the sheet from the palette. A plain `QWidget` keeps `STYLED_BACKGROUND` on while a border or a background is set. A border set takes the place of the widget's own: a scroll view's `none`, a button's background-coloured line |
| Button style | BORDERLESS: `setFlat(true)` and sheet `border: none` (no padding, even with a background). LINK: the same and sheet `color: palette(link)`; topside sends the pointing hand. DEFAULT: `setFlat(false)`, the sheet as before |
| Button font, colour, alignment | sheet `font-*`, `color`, `text-align` (`QtText`); a set colour wins over the link colour |
| Button icon | `setIcon(QIcon(QPixmap))` from the file, else `QIcon::fromTheme(symbol)`, else an empty icon; Qt puts it before the label |
| Button role | DEFAULT: `setDefault(true)`, `setAutoDefault(true)`. Qt clicks a focused default button on Return; outside a `QDialog` an unfocused one is not clicked (Qt's own behaviour, no shortcut added). CANCEL: Qt has no cancel button outside a dialog, so not shown |
| Search field | `setClearButtonEnabled(true)` and `addAction(QIcon::fromTheme(ThemeIcon::SYSTEM_SEARCH), LEADING_POSITION)` when the theme has the glyph (macOS 15 does); fixed at build |
| Borderless field | `setFrame(false)`, `MAC_SHOW_FOCUS_RECT` off, and sheet `background-color: transparent` (a frameless `QLineEdit` still paints its base colour; seen); false restores all three |
| Read-only, max length | `setReadOnly()`, `setMaxLength()`; no limit is Qt's 32767. Qt cuts a longer text, from `setMaxLength()` or `setText()`, and signals the cut as `textChanged`: the max length is set inside `write()` (no typing), and both hooks hand topside the field's `text()` through `cutTo()`, so `value()` is what is on screen and nothing is posted |
| Link colour | `style="color: …"` on the anchor the link builds: a `QLabel` draws anchors in the palette's link colour, not the sheet's; null leaves the palette's |

Margin, measured in a real window (macOS 15, Qt 6.11.2, 2026-11-24): Qt layouts keep no space round a child, so the only native margin is the style sheet's box. With `margin: 5px 7px 11px 13px` on each kind between two labels:

* Label, link, progress bar: the size hint grows by exactly 20 × 16 and the next item moves 16 down. Under a sheet box a label has a frame, and Qt's default indent (-1) then indents its text by half an `x` and widens its hint by a whole one (7 px); a label or link with a margin has `setIndent(0)`, and gets -1 back when the margin goes (ext-qt 53b591e).
* Check box and text input: exact (20 × 16) against the same widget under a sheet box with no margin; the sheet box itself drops Qt's native focus allowance (check box 63 × 20 → 59 × 18, text input 125 × 21 → 125 × 20), so against no sheet they grow 16 × 14 and 20 × 15. Look unchanged in a screenshot.
* In all five the background is painted inside the margin: red background, no red at the four margin sides, red inside.
* Not shown, the size hint unchanged (0 × 0): column, row, grid, fixed, split view, tab view (plain `QWidget`s or containers whose hint comes from their layout), separator, spinner, segmented control, image, text area, list, collection, table, tree.
* Not shown, the sheet box changes the control: button and toggle button (any sheet `margin`, even `0px`, swaps the macOS bezel for a sheet-drawn frame, 70 × 32 → 42 × 18), dropdown, combo box and date picker (frame and text lost or clipped), slider (handle clipped), switch (fixed width: the margin squeezes the knob), scroll view (grows 26 × 26, not 20 × 16).

# Notes

* Resizing the window holds the pump until release on macOS (measured by hand 2026-11-22: 3.6 s with no pump return), so timers and mail wait; a repeating timer then fires once and skips the beats it missed.
* Styling: one style sheet per widget, scoped to its object name, so it does not reach the widgets inside it.
* Opacity and transitions: a `QGraphicsOpacityEffect` parented to the widget; transitions are `QPropertyAnimation`s Qt runs (fade, and a `pos` slide).
* Release: `setParent(null)` + `deleteLater()`, flushed by the session's pump.
* `nativeFocus()` refuses a `NO_FOCUS` widget (`setFocus()` ignores the policy). `hasFocus()`: the window's `focusWidget()` (ext-qt 12940eb).

# Checked

Move curve (2026-11-25, a scratch real-window harness, 11 checks, passing twice on 8.4 NTS and twice on ZTS): a drawer filling an overlay with padding 10, MOVE_RIGHT over 1 s. The animation's curve is `BEZIER_SPLINE`, and Qt's `valueForProgress(0.3)` is 0.832 (easeOutQuint, 1 − 0.7⁵). On screen, the fraction of the way in at 0.1, 0.3, 0.5, 0.7 and 0.9 s: 0.33 to 0.39, 0.819, 0.956 to 0.961, 0.993, 1.000; it lands at x 10. Disappearing, the curve is the mirror (0.168 at 0.7 + 0.832 = 1), and the fraction of the way out at the same times is 0.000, 0.006, 0.037, 0.154 to 0.156, 0.585 to 0.589, still shown at 0.9 s, then hidden and back at rest. No opacity effect either way; FADE and SLIDE_UP still use linear curves. Pest 27 windowless and 20 integration pass on both.

Clickable groups and move transitions (2026-11-25, a scratch real-window harness, 25 checks with real clicks posted through Core Graphics, passing on 8.4 NTS (four runs) and ZTS (four runs)): a clickable column (padding 10) posts one `GroupClicked` for a click on itself (494, 394), on a label inside (15, 15 for the label at 10, 10, click 5, 5 into it) and on a picture inside (its centre, 250, 184); a click on a push button posts only `ButtonClicked`, on a line edit only `FocusGained`; disabled or off, nothing (off removes the filter). A clickable overlay (padding 6) posts for a click on its filling column (100, 50) and on a label in it (9, 9). A drawer filling an overlay with padding 10 in a 560 × 400 window, MOVE_RIGHT both ways, 0.8 s: hidden, the button under it clicks; appearing has one `pos` animation (then `OutCubic`, since replaced by topside's curve) and no opacity effect, and 0.2 s in it is at x 203 to 247 of its 540 width (partly off to the right), y 10, its colour exact on screen; it lands at 10, 10, 540 × 380; shown, a click on the button under it posts nothing; disappearing had `InCubic`, is still shown and moving right half-way (x 105), then is hidden and put back at 10, 10; afterwards the button under it clicks again. FADE has an opacity effect part way (0.32) and does not move; SLIDE_UP fades and comes up from 24 below, linear, as before. A screenshot half-way shows the drawer opaque, partly past the window's right edge. Pest 27 windowless and 20 integration pass on both.
Overlay, corners and image corners (2026-11-25, a scratch real-window harness, 28 checks on 8.4 NTS and ZTS, all passing): in an overlay with padding 4 8 12 16 and min size 0 × 140 at 624 wide, the filling child is at 16, 4, 600 × 124, an END/START child's right edge at 616 and top at 4, a CENTER child centred; the natural size is the largest other child plus padding (95 × 36), and the filling child's text does not change it, until it stops filling; by screenshot pixels the first child is at the back, `moveTo(0)` puts a child behind and `moveTo(2)` in front again; padding 0 live puts the filling child at 0, 0 at the overlay's size. Corners `0 0 14 14` on a column: only the two bottom properties, square top-left painted, rounded bottom-left not; one radius writes all four; 0 none; clip changes nothing. A 3000 × 1000 picture (red, green, blue thirds), FILL, filling an overlay with min size 0 × 320: window and overlay keep their size (overlay 624 × 320 in a 640 window), the picture takes the overlay's size, the card takes no spare room; the shown pixmap is the label's size (1248 × 640 at ratio 2); corners `0 0 14 14` leave the top corners opaque and the bottom ones transparent, with partly transparent pixels along the arcs (antialiased); on screen red at the left edge, green in the middle, blue at the right, red narrower than a third (cropped); radius 0 gives the square picture back. Seen in a screenshot. Pest 27 windowless and 20 integration pass on both.

Indent and cut text (2026-11-24, a scratch real-window harness, 14 checks on 8.4 NTS and ZTS, all passing): a label and a link with margin 5 7 11 13 grow by exactly 20 × 16 (label 59 × 16 → 79 × 32, link 25 × 16 → 45 × 32), the next item 16 down, indent 0; margin 0 gives indent -1 and the hint back. Qt's `setMaxLength(5)` on `abcdefgh` emits `textChanged` once; through the adapter, value `abcdefgh` then max 5 reads `abcde` with no mail, and max 5 then `setValue('1234567')` reads `12345` with no mail; no limit takes the whole value. A window's content column with `Insets` padding 3 5 7 9 puts its child at 9, 3. Pest 27 windowless and 20 integration pass on both.

Settings (2026-11-24, a scratch real-window harness through these classes, 83 checks on 8.4 NTS and ZTS, every one passing on both): padding per side and live on a column (child at 16, 4; then 20, 10), a row (9, 3, hint + 14 × 10), a grid (8, 2; then 0, 0) and a tab page (11, 5); margin on the five kinds above (hint + 20 × 16 against the sheet box, + 27 × 16 for label and link; next item 16 down; background inside, not in the margin, by screenshot pixels; margin 0 gives the native hint back), and not on a column or a button (sheet and size untouched); all twelve cursors read back their Qt shape, null unsets; tooltip and none; a column's border painted red at its edge, kept through a background set and cleared, `palette(mid)` painted as `QPalette::Mid`, 0 removes it and `STYLED_BACKGROUND`; a scroll view's border over its own `none` and back; a button's set border over its background's and back, borderless flat with no border or padding, link style flat with the pointing hand and its label painted in `QPalette::Link`, DEFAULT back to no sheet and the native 70 × 32; font, text-align, a PNG icon (56 → 76 wide), the `edit-find` theme symbol, file over symbol, none (back to 56); role DEFAULT (`isDefault`, `autoDefault`), CANCEL and null (neither); search field clear button and glyph action, none on a plain field; borderless (`hasFrame` false, no focus ring, transparent) and back; read-only both ways; max length 5 cuts `abcdefgh` to `abcde`, null is 32767; link colour inline and painted red, null plain. A screenshot showed padding 4 40 24 8 round a label, a borderless search field with the glyph and no field, a link-style button in the link colour with its icon beside a native button, and a 20 px gap before a label with a 30 px left margin.
List, collection and tree (2026-11-22, a scratch harness, NTS and ZTS, and by screenshot): code changes post nothing; select, open, close and activate post; disabled items post nothing; removal. All sixteen primitives of that day's three batches also render from one Sharp file.

Table and text area (2026-11-22, a scratch harness, NTS and ZTS): fill, focus, code changes post nothing, the user's selection and typing post, line selection, scroll to a line, removal.

Scroll view, tab view, split view and grid (2026-11-22, a scratch harness, offscreen on NTS and ZTS, earlier in real windows): sizes, fill through a plain row, code changes post nothing, tab choice, removal. Slider and divider dragged by hand: live.

Separator, progress bar, spinner, slider, toggle button, segmented control and image (2026-11-22, a scratch harness, 25 checks in a real window on NTS and ZTS): sizes in a column and a row; code changes post nothing; the user's slide, press and choice post their mail; disabled posts nothing; PNG and SVG show, another file is refused; removal. Looked at in a screenshot.

Text input, combo box, date picker and link (2026-11-21, a scratch real-window harness, 30 checks, active and inactive window): code changes post nothing; typing, Return, option, completion and pick post one mail each; completions keep focus and hide on Esc, choice and focus leaving; disabled posts nothing; removal. The link is not clicked.

Check box, switch and dropdown (2026-11-21, a scratch real-window harness, 11 checks): changes from code post nothing; the user's toggle, flip and choice each post one mail with the new value; new options with a selection post nothing; nothing from inside a disabled column; removal. Driven by click(), setValue() and setCurrentIndex(), which emit the signals a user's action does.

The real-window harness (a scratch topside-dev, 35 checks) through these classes passes: building, natives inside their containers, fixed frames, label text, alignment and font, a native click posting `ButtonClicked`, the enabled cascade, `focus()` moving between two buttons with `hasFocus()`, reordering, transitions, text colour, removal, close. Commit f743cc7, 7dd0367.

[^native]: QtNative
[^margin]: QtSheetMargin
