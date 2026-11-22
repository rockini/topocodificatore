#!/usr/bin/env php
<?php

declare(strict_types=1);

// Crea (o aggiorna) estensioni, funzioni e tabelle. Si può rieseguire.
// Uso: php bin/schema.php [--config=percorso/config.php]

require dirname(__DIR__) . '/src/bootstrap.php';

use Topocodificatore\Configurazione;

$opzioni = getopt('', ['config:']);
$db = Configurazione::carica($opzioni['config'] ?? null)->db();
foreach (glob(dirname(__DIR__) . '/sql/0*.sql') as $file) {
    echo 'Eseguo ' . basename($file) . PHP_EOL;
    $db->exec((string) file_get_contents($file));
}
echo "Schema pronto.\n";
