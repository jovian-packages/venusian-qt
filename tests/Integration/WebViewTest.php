<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\Mail\Views\WebViewFailed;
use Surface\Contracts\Toolkits\Mail\Views\WebViewLoaded;
use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\ButtonStyle;
use Surface\Contracts\Toolkits\UI\Insets;

it('posts WebViewLoaded with the base URL, and reads the title and loading state back', function (): void {
    $window = openWindow();
    $web = $window->column('root')->webView('web');
    takeMail();

    $web->loadHtml('<title>Hello</title><p>hi</p>', 'https://example.test/dir/');
    $mail = mailUntil('WebViewLoaded', 20.0);
    $loaded = array_values(array_filter($mail, fn (object $m): bool => $m instanceof WebViewLoaded));

    expect($loaded)->toHaveCount(1)
        ->and($loaded[0]->url)->toBe('https://example.test/dir/')
        ->and($web->title())->toBe('Hello')
        ->and($web->url())->toBe('https://example.test/dir/')
        ->and($web->isLoading())->toBeFalse();
});

it('posts WebViewFailed with Qt\'s error string for a page it cannot load', function (): void {
    $window = openWindow();
    $web = $window->column('root')->webView('web');
    takeMail();

    $web->load('file:///nonexistent/webview-test.html');
    $mail = mailUntil('WebViewFailed', 20.0);
    $failed = array_values(array_filter($mail, fn (object $m): bool => $m instanceof WebViewFailed));

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->reason)->not->toBeEmpty();
});

it('posts nothing from a removed web view', function (): void {
    $window = openWindow();
    $column = $window->column('root');
    $web = $column->webView('web');
    takeMail();

    $web->loadHtml('<p>x</p>', 'https://example.test/');
    $web->remove();
    pumpFor(1.5);

    expect(array_values(array_filter(mailNames(takeMail()), fn (string $name): bool => str_starts_with($name, 'WebView'))))->toBe([]);
});

it('grows a button by its padding on each side, and gives its own back when cleared', function (): void {
    $window = openWindow();
    $button = $window->column('root')->button('go', 'Continue')
        ->setStyle(ButtonStyle::BORDERLESS)->align(Align::START, Align::START);
    $hint = fn (): array => (function (): array {
        $size = $this->native()->sizeHint();

        return [$size->width(), $size->height()];
    })->call($button);
    pumpFor(0.2);
    [$width, $height] = $hint();

    $button->setPadding('8 16');
    pumpFor(0.2);
    expect($hint())->toBe([$width + 32, $height + 16]);

    $button->setPadding('0 4 0 28');
    pumpFor(0.2);
    expect($hint())->toBe([$width + 32, $height]);

    $button->setPadding(Insets::none());
    pumpFor(0.2);
    expect($hint())->toBe([$width, $height]);
});
