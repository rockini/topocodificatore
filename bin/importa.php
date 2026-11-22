#!/usr/bin/env php
<?php

declare(strict_types=1);

// Import ripetibile dei dati. Ogni esecuzione sostituisce i dati del dataset.
//
// Uso: php bin/importa.php <comuni|civici|toponimi|tutto> [--config=percorso/config.php]
//
// L'ordine conta: i civici e i toponimi ricavano il comune dalla posizione,
// quindi i comuni vanno importati per primi ("tutto" segue già quest'ordine).
// Dopo aver reimportato i comuni, reimporta anche civici e toponimi.

require dirname(__DIR__) . '/src/bootstrap.php';

use Topocodificatore\Configurazione;
use Topocodificatore\Import\Importatore;

$opzioni = [];
$dataset = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--config=')) {
        $opzioni['config'] = substr($arg, 9);
    } else {
        $dataset = $arg;
    }
}
$ordine = ['comuni', 'civici', 'toponimi'];
if ($dataset !== 'tutto' && !in_array($dataset, $ordine, true)) {
    fwrite(STDERR, "Uso: php bin/importa.php <comuni|civici|toponimi|tutto> [--config=file]\n");
    exit(2);
}

$config = Configurazione::carica($opzioni['config'] ?? null);
$db = $config->db();
$importatore = new Importatore($db, $config->sezione('importazione'), $config->sezione('db'), static function (string $m): void {
    echo date('H:i:s') . " $m" . PHP_EOL;
});

try {
    foreach ($dataset === 'tutto' ? $ordine : [$dataset] as $d) {
        $importatore->importa($d);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Errore: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
