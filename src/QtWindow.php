<?php

namespace Jovian\Toolkits\Qt;

use QEvent;
use QEvent\Type as EventType;
use QEventFilter;
use QCheckBox;
use QDesktopServices;
use QUrl;
use QMainWindow;
use QMessageBox;
use QPushButton;
use QObject;
use Qt\WidgetAttribute;
use Qt\WindowModality;
use Qt\WindowType;
use QVBoxLayout;
use QWidget;
use RuntimeException;
use Surface\Contracts\Toolkits\Mail\Windows\WindowFocused;
use Surface\Contracts\Toolkits\Mail\Windows\WindowScaleChanged;
use Surface\Toolkits\ToolkitWindow;
use Surface\Contracts\Toolkits\Dialogs\Alert;
use Surface\Contracts\Toolkits\Dialogs\AlertStyle;
use Surface\Contracts\Toolkits\UI\TKColumn as ColumnContract;
use WeakReference;

class QtWindow extends ToolkitWindow
{
    /** The device pixel ratio last posted, or read at the first show. */
    private float $ratio = 1.0;

    /**
     * Before the first show the window is on no screen of its own: a ratio change then is not the user's.
     *
     * @var bool
     */
    private bool $shown_once = false;

    /**
     * The content area's size at its last Resize event; null until its first layout.
     *
     * @var array{int, int}|null
     */
    private ?array $laid_out = null;

    /**
     * Where the content container goes: the central widget, below the bar.
     */
    protected ?QWidget $central = null;

    /**
     * The native window while open; null once closed. Qt deletes it after close.
     */
    protected ?QMainWindow $window = null;

    /**
     * Sees the window's close and activation events.
     */
    protected ?QEventFilter $filter = null;

    /**
     * The native's wrapper after close, held so it is not freed inside its own close event.
     */
    protected ?QMainWindow $departed = null;

    /**
     * Holds the content container's widget at the central widget's full size.
     */
    protected ?QVBoxLayout $central_layout = null;

    /**
     * Sees the central widget's Resize events: the content area's size.
     */
    protected ?QEventFilter $resize_filter = null;

    /** The message box open on the window, until it is answered. */
    protected ?QMessageBox $alert = null;

    /**
     * Its buttons, in the alert's order: the box gives back the one clicked, not its index.
     *
     * @var list<QPushButton>
     */
    protected array $alert_buttons = [];


    public function __construct(
        string $name,
        QtSession $session,
        QtAdapterDriver $driver,
        int $width,
        int $height,
        ?QtWindow $parent = null,
    ) {
        parent::__construct($name, $session, $driver, $width, $height, $parent);
    }

    public function present(): static
    {
        $window = $this->live();
        if ($this->parent instanceof self && ! $this->parent->native()->isVisible()) {
            $this->parent->present();
        }
        $window->show();
        $window->raise();
        $window->activateWindow();
        $this->focusInitial();

        return $this;
    }

    /**
     * The native window, for engines that draw into it.
     * @throws RuntimeException Once closed.
     */
    public function native(): QMainWindow
    {
        return $this->live();
    }

    public function isOpen(): bool
    {
        return ! is_null($this->window);
    }

    /**
     * The content area: the central widget, below the bar.
     *
     * @return array{int, int}
     * @throws RuntimeException Once closed.
     */
    public function size(): array
    {
        $this->live();
        $size = $this->central->size();

        return [$size->width(), $size->height()];
    }

    /** WindowScaleChanged when the device pixel ratio differs from the last posted. */
    public function scaleChanged(): void
    {
        $ratio = $this->window->devicePixelRatioF();
        if ($ratio === $this->ratio) {
            return;
        }
        $this->ratio = $ratio;
        if ($this->shown_once) {
            $this->session->post(new WindowScaleChanged($this->name, $ratio));
        }
    }


    public function setTitle(string $title): static
    {
        $this->live()->setWindowTitle($title);

        return $this;
    }

    public function firstShown(): void
    {
        if (! $this->shown_once) {
            $this->shown_once = true;
            $this->ratio = $this->window->devicePixelRatioF();
        }
    }

    protected function bootstrapWindow(int $width, int $height): void
    {
        $this->window = new QMainWindow();

        $this->window->setWindowTitle($this->name);
        $this->window->resize($width, $height);
        // Qt deletes the window after its close event is accepted.
        $this->window->setAttribute(WidgetAttribute::DELETE_ON_CLOSE);
        if ($this->parent instanceof self) {
            // setParent keeps the one-argument form in PHP; setWindowFlags then makes the child a window.
            $this->window->setParent($this->parent->native());
            $this->window->setWindowFlags(device_os_family() === 'mac' ? WindowType::SHEET : WindowType::DIALOG);
            $this->window->setWindowModality(WindowModality::WINDOW_MODAL);
        }

        $this->central = new QWidget();
        $this->central_layout = new QVBoxLayout($this->central);
        $this->central_layout->setContentsMargins(0, 0, 0, 0);
        $this->central_layout->setSpacing(0);
        $this->window->setCentralWidget($this->central);

        // The filters hold this window weakly: a strong capture would be a cycle through the
        // C++ filter that PHP's collector cannot see, keeping every closed window alive.
        $self = WeakReference::create($this);

        // A burst of resizes within one pump is one WindowResized, with the last size.
        $this->resize_filter = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            if ($event->type() === EventType::RESIZE) $self->get()?->centralResized();

            return false;
        });

        $this->central->installEventFilter($this->resize_filter);

        // false passes every event on: QMainWindow accepts the close, so the mail goes first. While
        // the window confirms close, the user's close is ignored (Qt keeps a window whose close event
        // is not accepted), posts WindowCloseRequested, and true keeps it from the window.
        $this->filter = new QEventFilter(static function (QObject $watched, QEvent $event) use ($self): bool {
            $type = $event->type();
            if ($type === EventType::CLOSE) {
                return $self->get()?->closeEvent($event) ?? false;
            }
            if ($type === EventType::WINDOW_ACTIVATE || $type === EventType::WINDOW_DEACTIVATE) {
                $self->get()?->filtered($type);
            }
            // Qt 6.6+ sends the ratio change; Qt 6.4 only the screen change.
            if ($type === EventType::DEVICE_PIXEL_RATIO_CHANGE || $type === EventType::SCREEN_CHANGE_INTERNAL) {
                $self->get()?->scaleChanged();
            }
            if ($type === EventType::SHOW) {
                $self->get()?->firstShown();
            }

            return false;
        });
        $this->ratio = $this->window->devicePixelRatioF();
        $this->window->installEventFilter($this->filter);
        // Deleted any other way (a parent, the application going): the same close path.
        QObject::connect($this->window, 'destroyed(QObject*)', fn () => $this->closed());

        // Not ported yet (deprecated/Windows): the right-click, group-click, canvas click/pointer
        // and window-key filters, and QtFocus.
        /*if (! is_null($menu)) {
            $this->useMenu($menu, $this->window->menuBar());
        }*/
    }

    protected function filtered(EventType|int $type): void
    {
        if ($type === EventType::WINDOW_ACTIVATE) {
            // Qt on macOS loses another app's copy once anything read the clipboard: compare the content.
            //$this->adapter->clipboard()->compare();
            // Qt shows the active window's bar itself; this tells topside whose bar answers.
            $this->gainedFocus();
            $this->session->post(new WindowFocused($this->name));
        }
        if ($type === EventType::WINDOW_DEACTIVATE) {
            $this->lostFocus();
        }
    }

    /**
     * The window's close event. Kept (the user's close while confirming): ignored, and true so
     * the window does not see it. Else the close path.
     */
    protected function closeEvent(QEvent $event): bool
    {
        // A parent with an open child or dialog keeps the user's close silently: Qt's modal block
        // holds only once the child shows, and only for window-system closes.
        /*if (! $this->closing && (! is_null(ChildWindows::childOf($this->adapter, $this)) || ! is_null($this->adapter->dialogs()->openOn($this)))) {
            $event->ignore();

            return true;
        }*/
        if (! $this->allowsUserClose()) {
            $event->ignore();

            return true;
        }
        $this->closed();

        return false;
    }

    /**
     * A QMessageBox, modal to the window, opened without waiting. Its default and escape
     * buttons take Return and Esc. Qt shows it with the platform's own alert where it can:
     * on macOS an NSAlert sheet, which, as AppKit does, gives Esc to no button when the
     * default is also the escape button. The title shows where the box has a title bar.
     */
    protected function showAlert(Alert $alert): void
    {
        $box = new QMessageBox($this->live());
        // As written, whichever box Qt shows: its own reads text that looks like HTML as HTML.
        $box->setTextFormat(\Qt\TextFormat::PLAIN_TEXT);
        $box->setText($alert->message);
        $box->setInformativeText($alert->detail ?? '');
        if (! is_null($alert->style)) {
            $box->setIcon(match ($alert->style) {
                AlertStyle::Info => QMessageBox\Icon::INFORMATION,
                AlertStyle::Warning => QMessageBox\Icon::WARNING,
                AlertStyle::Critical => QMessageBox\Icon::CRITICAL,
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
        // Esc and the box's own close click the escape button.
        $box->setEscapeButton($buttons[$alert->cancel]);
        $box->setWindowModality(WindowModality::WINDOW_MODAL);

        $self = WeakReference::create($this);
        $cancel = $alert->cancel;
        QObject::connect($box, 'finished(int)', static function () use ($self, $box, $cancel): void {
            $window = $self->get();
            if (is_null($window) || $window->alert !== $box) {
                return;
            }
            $index = array_search($box->clickedButton(), $window->alert_buttons, true);
            $window->alert = null;
            $window->alert_buttons = [];
            $window->alertAnswered($index === false ? $cancel : $index, $box->checkBox()?->isChecked() ?? false);
        });
        $this->alert = $box;
        $this->alert_buttons = $buttons;
        $box->open();
    }

    /** QDesktopServices opens it with the user's browser. */
    protected function openUrl(string $url): void
    {
        QDesktopServices::openUrl(new QUrl($url));
    }

    /** The box goes with the window; its finished() then finds it gone. */
    protected function closeAlert(): void
    {
        $box = $this->alert;
        $this->alert = null;
        $this->alert_buttons = [];
        $box?->close();
    }

    /** A button label as written: Qt reads one & as a mnemonic marker, two as an &. */
    private static function buttonLabel(string $label): string
    {
        return str_replace('&', '&&', $label);
    }

    /**
     * The central widget's Resize event: the content area's new size. Its first layout is the
     * size the window opened at, not a resize, as on the other toolkits.
     */
    protected function centralResized(): void
    {
        $size = $this->size();
        $first = is_null($this->laid_out);
        if ($first || $size === $this->laid_out) {
            $this->laid_out = $size;

            return;
        }
        $this->laid_out = $size;
        $this->resized(...$size);
    }

    /**
     * The content container fills the central widget's layout.
     */
    protected function mountContent(ColumnContract $content): void
    {
        $this->central_layout->addWidget($content->native(), 0, 0);
    }

    /**
     * Out of the layout; the container's own release takes it off the central widget.
     */
    protected function unmountContent(ColumnContract $content): void
    {
        $this->central_layout->removeWidget($content->native());
    }

    /**
     * Qt's close sends the close event, which takes the close path; Qt then deletes the
     * window (DELETE_ON_CLOSE).
     */
    protected function closeNative(): void
    {
        $this->live()->close();
    }


    protected function live(): QMainWindow
    {
        return $this->window ?? throw new RuntimeException("Qt Window '{$this->name}' is closed.");
    }

    /**
     * Keep the native's PHP wrapper until this object goes. closed() runs inside the window's
     * own close event filter; an ext-qt before 2026-11-12 deleted the QMainWindow when the
     * last reference dropped there, while Qt was still sending it that event. Qt deletes the
     * window itself after the close (DELETE_ON_CLOSE), so nothing is lost by holding it.
     */
    protected function releaseNative(): void
    {
        $this->departed = $this->window;
        $this->window = null;
    }
}
