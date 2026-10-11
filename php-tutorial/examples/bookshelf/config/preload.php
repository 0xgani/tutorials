<?php

declare(strict_types=1);

// Run once by OPcache when PHP-FPM starts, if php.ini sets opcache.preload to this file (chapter 22).
// Every class in src/ is compiled and linked into shared memory, so requests never load or link them again.
// Requiring through the autoloader means parent classes and interfaces are always loaded before their children.

require dirname(__DIR__) . '/vendor/autoload.php';

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(dirname(__DIR__) . '/src', FilesystemIterator::SKIP_DOTS),
);

foreach ($files as $file) {
    if ($file->getExtension() === 'php') {
        require_once $file->getPathname();
    }
}
