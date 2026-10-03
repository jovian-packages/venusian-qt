<?php

declare(strict_types=1);

// Run with QT_MEDIA_BACKEND=none: Qt then loads no media backend. Prints what creating a video throws.

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../Pest.php';

$column = driver()->open('main', 100, 100)->column('m');

try {
    $column->video('v');
    echo "made\n";
} catch (Surface\Contracts\Windows\WindowException $e) {
    echo $e->getMessage(), "\n";
}

echo implode(',', array_map(fn ($child) => $child->name(), $column->children())), "\n";
