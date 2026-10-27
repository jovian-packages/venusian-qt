<?php

namespace Jovian\Toolkits\Qt\Services;

use Jovian\Toolkits\Qt\Bridge\QtSession;
use QClipboard;
use QClipboard\Mode;
use QGuiApplication;
use QObject;
use Surface\Contracts\Windows\Mail\Services\ClipboardChanged;
use Surface\Windows\Services\ToolkitClipboard;
use Voyager\Contracts\Signals\NamedSignal;
use WeakReference;

/**
 * The application's clipboard. requestText() answers at once; dataChanged() posts ClipboardChanged
 * (latest-wins). On macOS every read clears Qt's own modified flag (QMacPasteboard::sync), so
 * Qt's dataChanged() on activation never comes after one: a window of the app becoming active
 * calls compare(), which posts when the content differs from the last seen. Elsewhere
 * dataChanged() is complete, and the compare is skipped: it reads the owner's data
 * synchronously, which waits out Qt's selection timeout when the owner hangs.
 */
final class QtClipboard extends ToolkitClipboard
{
    private readonly QClipboard $clipboard;

    /** Only macOS loses changes to its own reads. */
    private readonly bool $compares;

    /** @var array{string, list<string>} the text and formats last seen (macOS) */
    private array $seen = ['', []];

    public function __construct(private readonly QtSession $session)
    {
        // The driver's session made the application first, so Qt hands back its clipboard.
        $this->clipboard = QGuiApplication::clipboard();
        $this->compares = PHP_OS_FAMILY === 'Darwin';
        if ($this->compares) {
            $this->seen = $this->snapshot();
        }
        $weak = WeakReference::create($this);
        QObject::connect($this->clipboard, 'dataChanged()', static fn () => $weak->get()?->changed());
    }

    public function setText(string $text): void
    {
        $this->clipboard->setText($text, Mode::CLIPBOARD);
    }

    public function hasText(): bool
    {
        return $this->clipboard->mimeData(Mode::CLIPBOARD)?->hasText() ?? false;
    }

    /** A window of the app became active: post ClipboardChanged when the content differs from the last seen. */
    public function compare(): void
    {
        if ($this->compares && $this->snapshot() !== $this->seen) {
            $this->changed();
        }
    }

    protected function readText(string $id): void
    {
        $this->answerText($id, $this->hasText() ? $this->clipboard->text(Mode::CLIPBOARD) : null);
    }

    protected function post(NamedSignal $mail): void
    {
        $this->session->post($mail);
    }

    private function changed(): void
    {
        if ($this->compares) {
            $this->seen = $this->snapshot();
        }
        $this->session->postLatest('clipboard.changed', new ClipboardChanged());
    }

    /** @return array{string, list<string>} */
    private function snapshot(): array
    {
        return [$this->clipboard->text(Mode::CLIPBOARD), $this->clipboard->mimeData(Mode::CLIPBOARD)?->formats() ?? []];
    }
}
