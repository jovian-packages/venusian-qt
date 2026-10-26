<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QApplication;
use QComboBox;
use QComboBox\InsertPolicy;
use QCompleter;
use QCompleter\CompletionMode;
use QEvent;
use QEvent\Type;
use QEventFilter;
use QKeyEvent;
use QObject;
use QSizePolicy\Policy;
use QStringListModel;
use Qt\CaseSensitivity;
use Qt\Key;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Primitives\TKComboBox;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * An editable QComboBox for the options (no insert, no completer of its own), and a QCompleter
 * over a QStringListModel on its line edit for the completions, in unfiltered popup mode: every row
 * the app gives shows, the app filters. The completer's activated(QString) is a choice of a
 * completion; the popup hiding (a choice, Escape, focus leaving) hides them for Surface too.
 *
 * QComboBox moves its current index for the user (Down in the field, a pick from its list): it
 * sets the edit text first, with the new index already current, then emits activated(int). That
 * text change is the choice's, not typing, so TextChanged leaves it out and activated(int) posts
 * SelectionChanged. One Return in the field emits the line edit's returnPressed twice (probe
 * 2026-10-23), and the combo's activated(int) with each when the text matches an option; that Return
 * is a submit: the line edit's key filter marks the press, TextSubmitted posts once for it and
 * activated(int) posts nothing. Focus is the combo's (its line edit's focus proxy), and the completer
 * keeps its popup open through a focus move made from code (probe 2026-10-23): the combo's focus
 * filter hides it when focus goes to another widget.
 */
class QtComboBox extends TKComboBox implements QtNative
{
    use QtPrimitive;

    protected QCompleter $completer;

    protected QStringListModel $model;

    /** The popup's, the line edit's and the combo's filters: an installed filter is held by its object only weakly. */
    protected QEventFilter $popup_filter;

    protected QEventFilter $key_filter;

    protected QEventFilter $focus_filter;

    /** The index the combo was last seen at: a text change at a new index is a choice's. */
    private int $seen_index;

    /** Set while a Return key is down: the combo's activated(int) posts nothing for it. */
    private bool $returning = false;

    /** Whether the Return down has posted its TextSubmitted. */
    private bool $submitted = false;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $value, array $options)
    {
        parent::__construct($name, $window, $parent, $placement, $value, $options);
        $combo = $this->adoptNative(new QComboBox());
        $combo->setEditable(true);
        $combo->setCompleter(null);
        $combo->setInsertPolicy(InsertPolicy::NO_INSERT);
        // addItems makes the first option current and its text the edit text: the value goes after.
        $this->applyOptions($this->options);
        $this->applying(fn () => $combo->setEditText($value));

        $this->model = new QStringListModel($combo);
        $this->completer = new QCompleter($this->model, $combo);
        $this->completer->setWidget($combo->lineEdit());
        $this->completer->setCompletionMode(CompletionMode::UNFILTERED_POPUP_COMPLETION);
        $this->completer->setCaseSensitivity(CaseSensitivity::CASE_SENSITIVE);

        QObject::connect($combo, 'editTextChanged(QString)', function (string $text): void {
            $index = $this->native()->currentIndex();
            $chosen = $index !== $this->seen_index && $this->native()->itemText($index) === $text;
            $this->seen_index = $index;
            if (! $this->handling() || $chosen) {
                return;
            }
            $this->nativeEdited($text);
            $this->session()->post(new TextChanged($this->window->name(), $this->path(), $this->uuid, $text));
        });
        QObject::connect($combo, 'activated(int)', function (int $index): void {
            $this->seen_index = $index;
            $text = $this->options[$index] ?? null;
            if (! $this->handling() || $this->returning || is_null($text)) {
                return;
            }
            $this->nativeChose($text);
            $this->session()->post(new SelectionChanged($this->window->name(), $this->path(), $this->uuid, $index, $text));
        });
        QObject::connect($this->completer, 'activated(QString)', fn (string $text) => $this->chooseCompletion($text));
        QObject::connect($combo->lineEdit(), 'returnPressed()', function (): void {
            if (! $this->handling() || $this->completer->popup()?->isVisible() === true || ($this->returning && $this->submitted)) {
                return;
            }
            $this->submitted = $this->returning;
            $this->nativeEdited($this->native()->currentText());
            $this->session()->post(new TextSubmitted($this->window->name(), $this->path(), $this->uuid, $this->value));
        });

        // The primitive holds its filters, whose callables PHP's collector cannot see: a weak reference keeps them from holding the primitive.
        $self = \WeakReference::create($this);
        $this->popup_filter = new QEventFilter(static fn (QObject $watched, QEvent $event): bool => $self->get()?->popupEvent($event) ?? false);
        $this->completer->popup()?->installEventFilter($this->popup_filter);
        $this->key_filter = new QEventFilter(static fn (QObject $watched, QEvent $event): bool => $self->get()?->lineEditEvent($event) ?? false);
        $combo->lineEdit()?->installEventFilter($this->key_filter);
        $this->focus_filter = new QEventFilter(static fn (QObject $watched, QEvent $event): bool => $self->get()?->comboEvent($event) ?? false);
        $combo->installEventFilter($this->focus_filter);
    }

    /** The popup hiding (a choice, Escape, a click outside, focus leaving) hides the completions. */
    protected function popupEvent(QEvent $event): bool
    {
        if ($event->type() === Type::HIDE && $this->handling()) {
            $this->nativeHidden();
        }

        return false;
    }

    /** Marks a Return press, down to its release. */
    protected function lineEditEvent(QEvent $event): bool
    {
        if ($event instanceof QKeyEvent && in_array($event->key(), [Key::RETURN->value, Key::ENTER->value], true)) {
            $this->returning = $event->type() === Type::KEY_PRESS;
            $this->submitted = false;
        }

        return false;
    }

    /** Focus going to another widget hides the popup, which the completer keeps open. */
    protected function comboEvent(QEvent $event): bool
    {
        if ($event->type() === Type::FOCUS_OUT && QApplication::focusWidget() !== $this->native() && $this->handling()) {
            $this->completer->popup()?->hide();
        }

        return false;
    }

    public function native(): QComboBox
    {
        return $this->native;
    }

    public function completer(): QCompleter
    {
        return $this->completer;
    }

    public function completionModel(): QStringListModel
    {
        return $this->model;
    }

    protected function naturalPolicy(): array
    {
        return [Policy::EXPANDING, Policy::FIXED];
    }

    protected function applyValue(string $value): void
    {
        $this->applying(function () use ($value): void {
            $this->completer->popup()?->hide();
            $this->native()->setEditText($value);
        });
    }

    protected function applyOptions(array $options): void
    {
        $this->applying(function () use ($options): void {
            $text = $this->native()->currentText();
            $this->native()->clear();
            $this->native()->addItems($options);
            $this->native()->setEditText($text);
            $this->seen_index = $this->native()->currentIndex();
        });
    }

    protected function applyCompletions(array $completions): bool
    {
        if (! $this->nativeHasFocus()) {
            $this->applying(fn () => $this->completer->popup()?->hide());

            return false;
        }
        $this->applying(function () use ($completions): void {
            $this->model->setStringList($completions);
            if ($completions === []) {
                $this->completer->popup()?->hide();

                return;
            }
            $this->completer->setCompletionPrefix($this->native()->currentText());
            $this->completer->complete();
        });

        return true;
    }

    protected function chooseCompletion(string $text): void
    {
        if (! $this->handling()) {
            return;
        }
        $this->applying(fn () => $this->native()->setEditText($text));
        $this->nativeChose($text);
        $this->session()->post(new SelectionChanged($this->window->name(), $this->path(), $this->uuid, -1, $text));
    }
}
