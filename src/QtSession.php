<?php

namespace Jovian\Toolkits\Qt;

use QTimer;
use QObject;
use QWidget;
use QMessageBox;
use QSocketNotifier;
use Qt\WidgetAttribute;
use QAbstractEventDispatcher;
use QEvent\Type as EventType;
use QEventLoop\ProcessEventsFlag;
use QApplication;
use Qt\TimerType;
use QGuiApplication;
use QCoreApplication;
use Surface\Toolkits\ToolkitSession;

class QtSession extends ToolkitSession
{
    /**
     * The application, from initialization until the process ends.
     * @var QApplication|null
     */
    protected ?QApplication $application = null;

    /**
     * Single-shot precise timer that ends a pump's wait at its budget.
     * @var QTimer|null
     */
    protected ?QTimer $budget = null;

    /**
     * Non-blocking passes one pump runs after its wait. A source that is always ready
     * (a zero-interval timer) waits for the next pump instead of holding this one.
     */
    public const int DRAIN_LIMIT = 64;

    /**
     * The loop's waiter descriptor as a socket notifier, while joined.
     */
    protected ?QSocketNotifier $wake = null;

    /**
     * The About box while it is open; it deletes itself when closed.
     */
    protected ?QMessageBox $about_box = null;

    public function __construct(
        protected readonly string $application_name,
        protected readonly string $desktop_file_name,
    ) {
        parent::__construct();
    }

    /**
     * Construct the application (one per process) and name it.
     * @return void
     */
    protected function initializeEngine(): void
    {
        //QtCanvas::shareGLContexts();
        $argc = 1;
        $this->application = new QApplication($argc, [$_SERVER['argv'][0] ?? PHP_BINARY]);
        QCoreApplication::setApplicationName($this->application_name);
        QGuiApplication::setDesktopFileName($this->desktop_file_name);

        $this->budget = new QTimer();
        $this->budget->setSingleShot(true);
        // Firing is all it does: the timer event it dispatches ends the wait.
        $this->budget->setTimerType(TimerType::PRECISE_TIMER);
    }

    protected function connectToEngine(): void
    {
        QGuiApplication::setQuitOnLastWindowClosed(false);
    }

    /**
     * The inverse of connectToEngine(): Qt's default, the last window closing quits.
     */
    protected function disconnectEngine(): void
    {
        QGuiApplication::setQuitOnLastWindowClosed(true);
    }

    /**
     * Wait at most $budget_ns for an event, dispatch it, then dispatch what else is ready.
     *
     * @param int $budget_ns Zero dispatches what is ready without waiting.
     * @return int Passes that dispatched.
     */
    public function pump(int $budget_ns): int
    {
        // The wake notifier is one-shot: re-armed for each wait, so a descriptor the loop has
        // not read yet cannot keep a drain busy.
        $this->wake?->setEnabled(true);

        $dispatcher = QAbstractEventDispatcher::instance();
        $dispatched = 0;

        if ($budget_ns > 0) {
            $this->budget->start(max(1, intdiv($budget_ns + 999_999, 1_000_000)));
            if ($dispatcher->processEvents(ProcessEventsFlag::WAIT_FOR_MORE_EVENTS)) {
                $dispatched++;
            }
            $this->budget->stop();
        }

        while ($dispatched < self::DRAIN_LIMIT && $dispatcher->processEvents(ProcessEventsFlag::ALL_EVENTS)) {
            $dispatched++;
        }

        // Qt runs a deleteLater() posted outside any event handler only from exec(), which a
        // pumped application never enters; asking for deferred deletes at this level runs them.
        QCoreApplication::sendPostedEvents(null, EventType::DEFERRED_DELETE->value);

        // Not ported yet: the canvas's SDL window sweep on macOS (QtCanvas waits on TKCanvas).

        return $dispatched;
    }

    /**
     * The descriptor becomes a read notifier in Qt's dispatcher: readable ends the wait.
     */
    protected function wakeDescriptor(int $fd): void
    {
        $this->wake = new QSocketNotifier($fd, QSocketNotifier\Type::READ);
        QObject::connect($this->wake, 'activated(QSocketDescriptor,QSocketNotifier::Type)', function (): void {
            $this->wake?->setEnabled(false);
        });
    }

    protected function releaseWakeDescriptor(): void
    {
        if (! is_null($this->wake)) {
            $this->wake->setEnabled(false);
            $this->wake->deleteLater();
        }
        $this->wake = null;
    }

    /**
     * The application, for window hosts.
     */
    public function application(): QApplication
    {
        return $this->application;
    }

    /**
     * Show the app's non-modal About box with the identity given, over $parent when there is
     * one. While it is open, showing it again updates and raises the same box, as macOS does.
     *
     * @param array{name?: string|null, version?: string|null, copyright?: string|null} $about
     */
    public function showAbout(array $about, ?QWidget $parent): QMessageBox
    {
        if (is_null($this->about_box)) {
            $this->about_box = new QMessageBox($parent);
            $this->about_box->setModal(false);
            $this->about_box->setAttribute(WidgetAttribute::DELETE_ON_CLOSE);
            QObject::connect($this->about_box, 'destroyed(QObject*)', function (): void {
                $this->about_box = null;
            });
        }

        $name = empty($about['name']) ? QCoreApplication::applicationName() : (string) $about['name'];
        $box = $this->about_box;
        $box->setWindowTitle("About {$name}");
        $box->setText(empty($about['version']) ? $name : "{$name} {$about['version']}");
        $box->setInformativeText(empty($about['copyright']) ? '' : (string) $about['copyright']);
        $box->show();
        $box->raise();
        $box->activateWindow();

        return $box;
    }

    /** The About box while it is open. */
    public function aboutBox(): ?QMessageBox
    {
        return $this->about_box;
    }
}
