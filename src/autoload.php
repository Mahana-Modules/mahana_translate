<?php

spl_autoload_register(function ($class) {
    $prefix = 'MahanaTranslate\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});
