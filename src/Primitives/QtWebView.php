<?php

namespace Jovian\Toolkits\Qt\Primitives;

use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QObject;
use QUrl;
use QWebEngineView;
use Surface\Contracts\Toolkits\Mail\Views\WebViewFailed;
use Surface\Contracts\Toolkits\Mail\Views\WebViewLoaded;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\ToolkitWindow;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;
use Surface\Toolkits\Primitives\TKWebView;
use WeakReference;

/**
 * A QWebEngineView (Qt WebEngine, Chromium). loadStarted() reports loading; loadFinished(bool)
 * posts WebViewLoaded for true and WebViewFailed for false (Qt gives no reason with it, so the
 * reason names the URL); titleChanged and urlChanged keep title() and url() current.
 */
class QtWebView extends TKWebView implements QtNative
{
    use QtPrimitive;

    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $view = $this->adoptNative(new QWebEngineView());

        // The slots can outlive the widget: they reach the primitive through a weak reference.
        $self = WeakReference::create($this);
        $live = static fn (): ?self => ($v = $self->get()) === null || $v->removed ? null : $v;
        QObject::connect($view, 'loadStarted()', static fn () => $live()?->report(true));
        QObject::connect($view, 'loadFinished(bool)', static fn (bool $ok) => $live()?->finished($ok));
        QObject::connect($view, 'titleChanged(QString)', static fn () => $live()?->report($live()->loading));
        QObject::connect($view, 'urlChanged(QUrl)', static fn () => $live()?->report($live()->loading));
    }

    public function native(): QWebEngineView
    {
        return $this->native;
    }

    protected function applyLoad(string $url): void
    {
        $this->native->load(new QUrl($url));
    }

    protected function applyHtml(string $html, ?string $baseUrl): void
    {
        $this->native->setHtml($html, new QUrl($baseUrl ?? ''));
    }

    protected function applyReload(): void
    {
        $this->native->reload();
    }

    protected function applyStop(): void
    {
        $this->native->stop();
    }

    /** Record what the view reports now. */
    protected function report(bool $loading): void
    {
        $url = $this->native->url()->toString();
        $title = $this->native->title();
        $this->nativeLoadChanged($loading, $url === '' ? null : $url, $title === '' ? null : $title);
    }

    protected function finished(bool $ok): void
    {
        $this->report(false);
        $this->session()->post($ok
            ? new WebViewLoaded($this->window->name(), $this->path(), $this->uuid, $this->url)
            : new WebViewFailed($this->window->name(), $this->path(), $this->uuid, $this->url, 'The page could not be loaded: '.($this->url ?? 'no URL').'.'));
    }
}
