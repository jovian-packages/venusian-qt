<?php

namespace Jovian\Toolkits\Qt\UI;

use QEvent;
use QObject;
use Qt\Key;
use QKeyEvent;
use QComboBox;
use QCompleter;
use QApplication;
use QEventFilter;
use WeakReference;
use QEvent\Type;
use QStringListModel;
use QSizePolicy\Policy;
use Qt\CaseSensitivity;
use QComboBox\InsertPolicy;
use QCompleter\CompletionMode;
use Surface\Toolkits\UI\TKComboBox;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * An editable QComboBox for the options, and a QCompleter on its line edit for the
 * completions, unfiltered: the app does the filtering.
 *
 * What Qt signals, and what is made of it:
 *  - A pick changes the edit text with the new index already current, then emits
 *    activated(int): that text change is the choice's, not typing.
 *  - One Return emits returnPressed twice, and activated(int) when the text is an option:
 *    that is one submit and no choice.
 *  - The completer keeps its popup open through a focus move from code: the combo's focus
 *    filter hides it.
 */
class QtComboBox extends TKComboBox
{
    use QtNative;

    private QCompleter $completer;

    private QStringListModel $model;

    /** An installed filter is held by its object only weakly. @var list<QEventFilter> */
    private array $filters = [];

    /** The index last seen current. */
    private int $seen = -1;

    /** A Return key is down. */
    private bool $returning = false;

    /** The Return that is down has submitted. */
    private bool $did_submit = false;

    /** A row of the completions is highlighted. */
    private bool $highlighted = false;

    protected function bootstrapNative(): void
    {
        $combo = new QComboBox();
        $combo->setEditable(true);
        $combo->setCompleter(null);
        $combo->setInsertPolicy(InsertPolicy::NO_INSERT);
        $this->adoptNative($combo);
        $this->applyOptions($this->options());
        $combo->setEditText($this->value());

        $this->model = new QStringListModel($combo);
        $this->completer = new QCompleter($this->model, $combo);
        $this->completer->setWidget($combo->lineEdit());
        $this->completer->setCompletionMode(CompletionMode::UNFILTERED_POPUP_COMPLETION);
        $this->completer->setCaseSensitivity(CaseSensitivity::CASE_SENSITIVE);

        $self = WeakReference::create($this);
        QObject::connect($combo, 'editTextChanged(QString)', static function (string $text) use ($self): void {
            $self->get()?->edited($text);
        });
        QObject::connect($combo, 'activated(int)', static function (int $index) use ($self): void {
            $self->get()?->activated($index);
        });
        QObject::connect($this->completer, 'highlighted(QString)', static function (string $text) use ($self): void {
            $box = $self->get();
            if (! is_null($box)) {
                $box->highlighted = $text !== '';
            }
        });
        QObject::connect($this->completer, 'activated(QString)', static function (string $text) use ($self): void {
            $box = $self->get();
            if (! is_null($box)) {
                $box->highlighted = false;
                $box->choseCompletion($text);
            }
        });
        QObject::connect($combo->lineEdit(), 'returnPressed()', static function () use ($self): void {
            $self->get()?->returnPressed();
        });

        $keys = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            $self->get()?->keyEvent($event);

            return false;
        });
        $combo->lineEdit()->installEventFilter($keys);

        $hiding = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            if ($event->type() === Type::HIDE) {
                $self->get()?->completionsHidden();
            }

            return false;
        });
        $this->completer->popup()?->installEventFilter($hiding);

        $focus = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            if ($event->type() === Type::FOCUS_OUT) {
                $self->get()?->focusLeft();
            }

            return false;
        });
        $combo->installEventFilter($focus);

        $this->filters = [$keys, $hiding, $focus];
    }

    /**
     * The line edit's focus proxy is the combo: either is the combo's focus.
     */
    public function hasFocus(): bool
    {
        if (is_null($this->native)) {
            return false;
        }
        $focus = $this->native->window()->focusWidget();

        return ! is_null($focus) && ($focus === $this->native || $focus === $this->comboNative()->lineEdit());
    }

    protected function applyValue(string $value): void
    {
        $this->comboNative()->setEditText($value);
        $this->seen = $this->comboNative()->currentIndex();
    }

    protected function applyOptions(array $options): void
    {
        $combo = $this->comboNative();
        $combo->clear();
        $combo->addItems($options);
        $this->seen = $combo->currentIndex();
    }

    protected function applyCompletions(array $completions): void
    {
        $this->highlighted = false;
        if ($completions === []) {
            $this->completer->popup()?->hide();

            return;
        }

        $this->model->setStringList($completions);
        $this->completer->setCompletionPrefix($this->comboNative()->currentText());
        $this->completer->complete();
    }

    /**
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::EXPANDING, Policy::FIXED];
    }

    private function edited(string $text): void
    {
        if ($this->isRemoved()) {
            return;
        }

        $combo = $this->comboNative();
        $index = $combo->currentIndex();
        $chosen = $index !== $this->seen && $combo->itemText($index) === $text;
        $this->seen = $index;

        if (! $chosen) {
            $this->typed($text);
        }
    }

    private function activated(int $index): void
    {
        $this->seen = $index;
        if (! $this->returning) {
            $this->choseOption($index);
        }
    }

    private function returnPressed(): void
    {
        if ($this->isRemoved() || $this->highlighted || ($this->returning && $this->did_submit)) {
            return;
        }

        $this->did_submit = $this->returning;
        $this->submitted($this->comboNative()->currentText());
    }

    private function keyEvent(QEvent $event): void
    {
        if ($event instanceof QKeyEvent && in_array($event->key(), [Key::RETURN->value, Key::ENTER->value], true)) {
            $this->returning = $event->type() === Type::KEY_PRESS;
            $this->did_submit = false;
        }
    }

    private function focusLeft(): void
    {
        if (! $this->isRemoved() && QApplication::focusWidget() !== $this->native) {
            $this->completionsHidden();
        }
    }

    private function comboNative(): QComboBox
    {
        /** @var QComboBox */
        return $this->native();
    }
}
