<?php

namespace Jovian\Toolkits\Qt\Services;

use Jovian\Toolkits\Qt\Bridge\QtBridgeDriver;
use Jovian\Toolkits\Qt\Bridge\QtSession;
use Jovian\Toolkits\Qt\Windows\QtWindow;
use QCheckBox;
use QDialog;
use QDir\Filter;
use QFileDialog;
use QMainWindow;
use QMessageBox;
use QMessageBox\Icon;
use QMetaObject\Connection;
use QObject;
use QPushButton;
use Qt\WindowModality;
use Surface\Contracts\Windows\Services\Alert;
use Surface\Contracts\Windows\Services\AlertStyle;
use Surface\Contracts\Windows\Services\DialogCapability;
use Surface\Contracts\Windows\Services\OpenRequest;
use Surface\Contracts\Windows\Services\SaveRequest;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Services\ToolkitDialogs;
use Voyager\Contracts\Signals\NamedSignal;
use WeakReference;

/**
 * QMessageBox and QFileDialog, window-modal to their parent and opened with open(). finished(int)
 * answers; the parent's close disconnects it first, then closes the dialog.
 */
final class QtDialogs extends ToolkitDialogs
{
    /**
     * The open dialogs by request id, with their finished(int) connection.
     * @var array<string, array{native: QDialog, finished: Connection}>
     */
    private array $open = [];

    public function __construct(QtBridgeDriver $driver, private readonly QtSession $session)
    {
        parent::__construct($driver);
    }

    /** macOS shows a window-modal box as a sheet, which has no title bar. */
    public function supports(DialogCapability $capability): bool
    {
        return $capability !== DialogCapability::AlertTitle || PHP_OS_FAMILY !== 'Darwin';
    }

    /** The QMessageBox or QFileDialog of an open request, or null. */
    public function native(string $id): QMessageBox|QFileDialog|null
    {
        return $this->open[$id]['native'] ?? null;
    }

    protected function showAlert(string $id, ToolkitWindow $parent, Alert $alert): void
    {
        $box = new QMessageBox($this->parentWindow($parent));
        $box->setText(self::literal($alert->message));
        $box->setInformativeText(is_null($alert->detail) ? '' : self::literal($alert->detail));
        if (! is_null($alert->style)) {
            $box->setIcon(match ($alert->style) {
                AlertStyle::Info => Icon::INFORMATION,
                AlertStyle::Warning => Icon::WARNING,
                AlertStyle::Critical => Icon::CRITICAL,
            });
        }
        if (! is_null($alert->suppression)) {
            // The box owns the check box; its label reads & as a mnemonic, as a button's does.
            $box->setCheckBox(new QCheckBox(self::buttonLabel($alert->suppression)));
        }
        if (! is_null($alert->title)) {
            $box->setWindowTitle($alert->title);
        }
        /** @var list<QPushButton> $buttons */
        $buttons = array_map(static fn (string $label): QPushButton => $box->addButton(self::buttonLabel($label), QMessageBox\ButtonRole::ACTION_ROLE), $alert->buttons);
        $box->setDefaultButton($buttons[$alert->default]);
        // Esc and the title-bar close click the escape button.
        $box->setEscapeButton($buttons[$alert->cancel]);
        $box->setWindowModality(WindowModality::WINDOW_MODAL);
        $weak = WeakReference::create($this);
        $cancel = $alert->cancel;
        $finished = QObject::connect($box, 'finished(int)', static function (int $result) use ($weak, $id, $box, $buttons, $cancel): void {
            $clicked = $box->clickedButton();
            $index = array_search($clicked, $buttons, true);
            $weak->get()?->ended($id, $index === false ? $cancel : $index, $box->checkBox()?->isChecked() ?? false);
        });
        $this->open[$id] = ['native' => $box, 'finished' => $finished];
        $box->open();
    }

    protected function showOpen(string $id, ToolkitWindow $parent, OpenRequest $request): void
    {
        $dialog = $this->fileDialog($parent, $request->title, $request->directory, $request->filters, $request->showHidden);
        $dialog->setFileMode(match (true) {
            $request->folders => QFileDialog\FileMode::DIRECTORY,
            $request->multiple => QFileDialog\FileMode::EXISTING_FILES,
            default => QFileDialog\FileMode::EXISTING_FILE,
        });
        if ($request->folders) {
            $dialog->setOption(QFileDialog\Option::SHOW_DIRS_ONLY);
        }
        $this->begin($id, $dialog);
    }

    protected function showSave(string $id, ToolkitWindow $parent, SaveRequest $request): void
    {
        $dialog = $this->fileDialog($parent, $request->title, $request->directory, $request->filters, $request->showHidden);
        $dialog->setAcceptMode(QFileDialog\AcceptMode::ACCEPT_SAVE);
        if (! is_null($request->name)) {
            $dialog->selectFile($request->name);
        }
        $this->begin($id, $dialog);
    }

    /** A button label as written: Qt reads one & as a mnemonic marker, two as an &. */
    public static function buttonLabel(string $label): string
    {
        return str_replace('&', '&&', $label);
    }

    /**
     * Text a message box shows as written. Its labels are AutoText, so text that looks like HTML
     * would render as HTML; Qt 6.4 keeps the informative label AutoText even after
     * setTextFormat(). Escaped rich text with whitespace kept renders as the text on every version.
     */
    private static function literal(string $text): string
    {
        return '<qt><p style="white-space:pre-wrap">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></qt>';
    }

    protected function dismiss(string $id): void
    {
        $dialog = $this->release($id);
        $dialog?->close();
    }

    protected function post(NamedSignal $mail): void
    {
        $this->session->post($mail);
    }

    /**
     * The dialog finished: let go of it, then answer. An id the parent's close already ended answers nothing.
     * @param int|list<string> $value
     */
    private function ended(string $id, int|array $value, bool $suppressed = false): void
    {
        if (is_null($this->release($id))) {
            return;
        }
        is_int($value) ? $this->answerAlert($id, $value, $suppressed) : $this->answerFiles($id, $value);
    }

    /**
     * @param array<string, list<string>> $filters each as 'Name (*.a *.b)'
     * @param bool $hidden adds QDir's Hidden bit to the dialog's own filter
     */
    private function fileDialog(ToolkitWindow $parent, ?string $title, ?string $directory, array $filters, bool $hidden): QFileDialog
    {
        $dialog = new QFileDialog($this->parentWindow($parent), $title ?? '', self::folder($directory) ?? '');
        $dialog->setWindowModality(WindowModality::WINDOW_MODAL);
        if ($filters !== []) {
            $dialog->setNameFilters(array_map(
                static fn (string $name, array $patterns): string => $name . ' (' . implode(' ', $patterns) . ')',
                array_keys($filters),
                array_values($filters),
            ));
        }
        if ($hidden) {
            $dialog->setFilter($dialog->filter() | Filter::HIDDEN->value);
        }

        return $dialog;
    }

    /** Accepted (1) answers the selected files; any other result answers []. */
    private function begin(string $id, QFileDialog $dialog): void
    {
        $weak = WeakReference::create($this);
        $finished = QObject::connect($dialog, 'finished(int)', static function (int $result) use ($weak, $id, $dialog): void {
            $weak->get()?->ended($id, $result === 1 ? $dialog->selectedFiles() : []);
        });
        $this->open[$id] = ['native' => $dialog, 'finished' => $finished];
        $dialog->open();
    }

    /** Forget the dialog of $id: disconnect finished(int) and delete it on the next pump; the dialog, or null when it was gone. */
    private function release(string $id): ?QDialog
    {
        $open = $this->open[$id] ?? null;
        unset($this->open[$id]);
        if (is_null($open)) {
            return null;
        }
        QObject::disconnect($open['finished']);
        $open['native']->deleteLater();

        return $open['native'];
    }

    private function parentWindow(ToolkitWindow $parent): QMainWindow
    {
        /** @var QtWindow $parent the parent check made it a window of this driver */
        return $parent->native();
    }
}
