<?php

namespace Jovian\Toolkits\Qt;

use Jovian\Toolkits\deprecated2\Services\QtClipboard;
use QAbstractEventDispatcher;
use QApplication;
use QCoreApplication;
use QEvent\Type as EventType;
use QEventLoop\ProcessEventsFlag;
use QGuiApplication;
use QObject;
use QSocketNotifier;
use Qt\TimerType;
use QTimer;
use ReflectionException;
use Surface\Contracts\Toolkits\Toolkit;

class Qt implements Toolkit
{
    protected readonly int $drain_limit;
    /**
     * The loop's waiter descriptor as a socket notifier, while joined.
     */
    protected ?QSocketNotifier $wake = null;

    /**
     * Single-shot precise timer that ends a pump's wait at its budget.
     * @var QTimer|null
     */
    protected ?QTimer $budget = null;

    //protected ?QtClipboard $clipboard = null;

    /**
     * @throws ReflectionException
     */
    public function __construct(
        protected ?QApplication $application = null
    ) {
        $argc = 1;
        $this->application ??= new QApplication($argc, [$_SERVER['argv'][0] ?? PHP_BINARY]);

        $this->drain_limit = config('qt.loop_drain_limit', 64);
    }

    public function connect(): void
    {
        QGuiApplication::setQuitOnLastWindowClosed(false);
    }

    public function disconnect(): void
    {
        QGuiApplication::setQuitOnLastWindowClosed(true);
    }

    public function setQApplicationNames(string $application_name, string $desktop_file_name): static
    {
        QCoreApplication::setApplicationName($application_name);
        QGuiApplication::setDesktopFileName($desktop_file_name);

        return $this;
    }

    public function setTimerBudget(): static
    {
        $this->budget = new QTimer();
        $this->budget->setSingleShot(true);

        // Firing is all it does: the timer event it dispatches ends the wait.
        $this->budget->setTimerType(TimerType::PRECISE_TIMER);

        return $this;
    }

    public function setupClipboard(QtSession $session): void
    {
        //$this->clipboard ??= new QtClipboard($session);
    }

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

        while ($dispatched < $this->drain_limit && $dispatcher->processEvents(ProcessEventsFlag::ALL_EVENTS)) {
            $dispatched++;
        }

        // Qt runs a deleteLater() posted outside any event handler only from exec(), which a
        // pumped application never enters; asking for deferred deletes at this level runs them.
        QCoreApplication::sendPostedEvents(null, EventType::DEFERRED_DELETE->value);

        return $dispatched;
    }

    public function wakeDescriptor(int $fd): void
    {
        $this->wake = new QSocketNotifier($fd, QSocketNotifier\Type::READ);
        QObject::connect($this->wake, 'activated(QSocketDescriptor,QSocketNotifier::Type)', function (): void {
            $this->wake?->setEnabled(false);
        });
    }
}