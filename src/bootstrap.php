<?php

declare(strict_types=1);

// Autoload PSR-4 minimale, per usare il progetto senza Composer.
spl_autoload_register(static function (string $classe): void {
    $prefisso = 'Topocodificatore\\';
    if (strncmp($classe, $prefisso, strlen($prefisso)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($classe, strlen($prefisso))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/funzioni.php';
