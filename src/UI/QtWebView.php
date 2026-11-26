<?php

namespace Jovian\Toolkits\Qt\UI;

use QObject;
use QUrl;
use QWebEngineLoadingInfo;
use QWebEngineLoadingInfo\LoadStatus;
use QWebEngineView;
use QMetaObject\Connection;
use WeakReference;
use Surface\Toolkits\UI\TKWebView;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QWebEngineView (Chromium). The engine's page holds the URL, the title and the loading
 * state; the page's loadingChanged ends a load as WebViewLoaded or WebViewFailed. A load
 * stopped by stop() posts nothing.
 */
class QtWebView extends TKWebView
{
    use QtNative {
        releaseNative as private releaseWidget;
    }

    private ?Connection $loading = null;

    protected function bootstrapNative(): void
    {
        $view = new QWebEngineView();
        $this->adoptNative($view);

        $self = WeakReference::create($this);
        $this->loading = QObject::connect($view->page(), 'loadingChanged(QWebEngineLoadingInfo)', static function (QWebEngineLoadingInfo $info) use ($self): void {
            $self->get()?->loadEnded($info);
        });
    }

    protected function releaseNative(): void
    {
        if (! is_null($this->loading)) {
            QObject::disconnect($this->loading);
            $this->loading = null;
        }

        $this->releaseWidget();
    }

    protected function applyLoad(string $url): void
    {
        $this->webNative()->load(new QUrl($url));
    }

    protected function applyLoadHtml(string $html, ?string $baseUrl): void
    {
        $this->webNative()->setHtml($html, new QUrl($baseUrl ?? ''));
    }

    protected function applyReload(): void
    {
        $this->webNative()->reload();
    }

    protected function applyStop(): void
    {
        $this->webNative()->stop();
    }

    public function url(): ?string
    {
        return self::orNull($this->webNative()->page()->url()->toString());
    }

    public function title(): ?string
    {
        return self::orNull($this->webNative()->page()->title());
    }

    public function isLoading(): bool
    {
        return $this->webNative()->page()->isLoading();
    }

    private function loadEnded(QWebEngineLoadingInfo $info): void
    {
        match ($info->status()) {
            LoadStatus::LOAD_SUCCEEDED_STATUS => $this->loaded($info->url()->toString()),
            LoadStatus::LOAD_FAILED_STATUS => $this->failed($info->url()->toString(), self::orNull($info->errorString())),
            default => null,
        };
    }

    private static function orNull(string $text): ?string
    {
        return $text === '' ? null : $text;
    }

    private function webNative(): QWebEngineView
    {
        /** @var QWebEngineView */
        return $this->native();
    }
}
