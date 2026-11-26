#!/usr/bin/env php
<?php

declare(strict_types=1);

// Geocodifica un file CSV: aggiunge a ogni riga confidenza, latitudine e longitudine
// del miglior candidato trovato per l'indirizzo.
//
// Uso: php bin/geocodifica_csv.php <ingresso.csv> [uscita.csv] [opzioni]
//
//   --colonna=NOME|N      colonna con l'indirizzo, per nome o posizione (da 1);
//                         più colonne separate da virgola vengono unite:
//                         --colonna=via,civico,comune
//                         (predefinita: la prima tra indirizzo, address, via)
//   --separatore=;        separatore dei campi (predefinito: riconosciuto dalla prima riga;
//                         "tab" per la tabulazione)
//   --senza-intestazione  il file non ha la riga di intestazione (indica la colonna per posizione)
//   --config=FILE         configurazione del database (predefinita: config/config.php)
//
// Se manca l'uscita si scrive <ingresso>_geocodificato.csv; con "-" si scrive sullo standard output.

require dirname(__DIR__) . '/src/bootstrap.php';

use Topocodificatore\Configurazione;
use Topocodificatore\GeocodificaCsv;

$uso = "Uso: php bin/geocodifica_csv.php <ingresso.csv> [uscita.csv] [--colonna=nome|n[,...]]"
    . " [--separatore=;] [--senza-intestazione] [--config=file]\n";

$file = [];
$opzioni = ['colonna' => null, 'separatore' => null, 'config' => null, 'intestazione' => true];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--senza-intestazione') {
        $opzioni['intestazione'] = false;
    } elseif ($arg === '-h' || $arg === '--help') {
        echo $uso;
        exit(0);
    } elseif (preg_match('/^--(colonna|separatore|config)=(.*)$/s', $arg, $m)) {
        $opzioni[$m[1]] = $m[2];
    } elseif ($arg !== '-' && strncmp($arg, '--', 2) === 0) {
        fwrite(STDERR, "Opzione sconosciuta: $arg\n$uso");
        exit(2);
    } else {
        $file[] = $arg;
    }
}
if (count($file) < 1 || count($file) > 2) {
    fwrite(STDERR, $uso);
    exit(2);
}
$ingresso = $file[0];
$uscita = $file[1] ?? preg_replace('/(\.csv)?$/i', '', $ingresso, 1) . '_geocodificato.csv';

$separatore = $opzioni['separatore'];
if ($separatore !== null && in_array(strtolower($separatore), ['tab', '\t'], true)) {
    $separatore = "\t";
}

try {
    $in = $ingresso === '-' ? STDIN : @fopen($ingresso, 'rb');
    if ($in === false) {
        throw new RuntimeException("Impossibile leggere $ingresso");
    }

    $colonne = $opzioni['colonna'] !== null
        ? array_values(array_filter(array_map('trim', explode(',', $opzioni['colonna'])), 'strlen'))
        : [];

    $db = Configurazione::carica($opzioni['config'])->db();
    $geocoder = static function (string $indirizzo) use ($db): ?array {
        $candidati = \Topocodificatore\geocodifica($indirizzo, 1, $db);
        return $candidati[0] ?? null;
    };

    $out = $uscita === '-' ? STDOUT : @fopen($uscita, 'wb');
    if ($out === false) {
        throw new RuntimeException("Impossibile scrivere $uscita");
    }

    $elaboratore = new GeocodificaCsv($geocoder, $colonne, $separatore, $opzioni['intestazione'],
        ['confidenza', 'latitudine', 'longitudine'], static function (string $m): void {
            fwrite(STDERR, $m . PHP_EOL);
        });
    $c = $elaboratore->elabora($in, $out);
    if ($out !== STDOUT) {
        fclose($out);
    }
} catch (Throwable $e) {
    if (isset($out) && is_resource($out) && $out !== STDOUT) {
        fclose($out);
        @unlink($uscita);   // niente file a metà
    }
    fwrite(STDERR, 'Errore: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDERR, sprintf(
    "%d righe: %d geocodificate, %d non trovate, %d senza indirizzo.%s\n",
    $c['righe'], $c['trovati'], $c['non_trovati'], $c['vuoti'],
    $uscita === '-' ? '' : " Risultato in $uscita"
));
