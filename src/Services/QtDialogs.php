<?php

namespace Jovian\Toolkits\Qt\Services;

use Jovian\Toolkits\Qt\Bridge\QtBridgeDriver;
use Jovian\Toolkits\Qt\Bridge\QtSession;
use Jovian\Toolkits\Qt\Windows\QtWindow;
use QDialog;
use QFileDialog;
use QMainWindow;
use QMessageBox;
use QMetaObject\Connection;
use QObject;
use QPushButton;
use Qt\WindowModality;
use Surface\Contracts\Windows\Services\Alert;
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

    protected function showAlert(string $id, ToolkitWindow $parent, Alert $alert): void
    {
        $box = new QMessageBox($this->parentWindow($parent));
        $box->setText(self::literal($alert->message));
        $box->setInformativeText(is_null($alert->detail) ? '' : self::literal($alert->detail));
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
            $weak->get()?->ended($id, $index === false ? $cancel : $index);
        });
        $this->open[$id] = ['native' => $box, 'finished' => $finished];
        $box->open();
    }

    protected function showOpen(string $id, ToolkitWindow $parent, OpenRequest $request): void
    {
        $dialog = $this->fileDialog($parent, $request->title, $request->directory);
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
        $dialog = $this->fileDialog($parent, $request->title, $request->directory);
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
    private function ended(string $id, int|array $value): void
    {
        if (is_null($this->release($id))) {
            return;
        }
        is_int($value) ? $this->answerAlert($id, $value) : $this->answerFiles($id, $value);
    }

    private function fileDialog(ToolkitWindow $parent, ?string $title, ?string $directory): QFileDialog
    {
        $dialog = new QFileDialog($this->parentWindow($parent), $title ?? '', self::folder($directory) ?? '');
        $dialog->setWindowModality(WindowModality::WINDOW_MODAL);

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
