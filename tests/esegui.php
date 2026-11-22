<?php

declare(strict_types=1);

// Test senza dipendenze esterne: php tests/esegui.php
//
// I test dell'analizzatore girano sempre. Quelli sul database (normalizzazione
// SQL, import, /search, /reverse) girano se è impostata GEOCODER_TEST_DSN, per esempio
//   GEOCODER_TEST_DSN="pgsql:host=localhost;dbname=geocoder_test" GEOCODER_TEST_UTENTE=... php tests/esegui.php
// ATTENZIONE: i test sul database cancellano e reimportano tutti i dati del geocoder:
// usa un database dedicato. Serve ogr2ogr (gdal-bin) per l'import dei comuni.

require dirname(__DIR__) . '/src/bootstrap.php';

use Topocodificatore\AnalizzatoreIndirizzo;
use Topocodificatore\Api;
use Topocodificatore\Configurazione;
use Topocodificatore\Import\Importatore;

$falliti = 0;
$eseguiti = 0;

function verifica(string $nome, mixed $atteso, mixed $ottenuto): void
{
    global $falliti, $eseguiti;
    $eseguiti++;
    if ($atteso === $ottenuto) {
        return;
    }
    $falliti++;
    echo "FALLITO: $nome\n  atteso:   " . json_encode($atteso, JSON_UNESCAPED_UNICODE)
        . "\n  ottenuto: " . json_encode($ottenuto, JSON_UNESCAPED_UNICODE) . "\n";
}

// --- Analizzatore del testo libero -------------------------------------------

$casi = [
    'Via Roma 12/A, 20121 Milano (MI)' => ['via' => 'Via Roma', 'civico' => '12/A', 'cap' => '20121', 'comune' => 'Milano', 'provincia' => 'MI'],
    'via XX Settembre 5 Genova' => ['via' => 'via XX Settembre', 'civico' => '5', 'comune' => 'Genova'],
    'Via 20 Settembre 5, Roma' => ['via' => 'Via 20 Settembre', 'civico' => '5', 'comune' => 'Roma'],
    'Corso 22 Marzo, Milano' => ['via' => 'Corso 22 Marzo', 'comune' => 'Milano'],
    'Piazza Duomo, 1, Milano' => ['via' => 'Piazza Duomo', 'civico' => '1', 'comune' => 'Milano'],
    'Via Roma 3, fraz. Pieve, Cortina' => ['via' => 'Via Roma', 'civico' => '3', 'localita' => 'fraz. Pieve', 'comune' => 'Cortina'],
    'Strada Provinciale 12, Lecco' => ['via' => 'Strada Provinciale 12', 'comune' => 'Lecco'],
    'Via Garibaldi 12 bis Torino TO' => ['via' => 'Via Garibaldi', 'civico' => '12 bis', 'comune' => 'Torino', 'provincia' => 'TO'],
    'Via Garibaldi snc, Bari' => ['via' => 'Via Garibaldi', 'civico' => 'snc', 'comune' => 'Bari'],
    'Via Roma 12 A, Milano' => ['via' => 'Via Roma', 'civico' => '12 A', 'comune' => 'Milano'],
    'Via Garibaldi, 7/1, 16124 Genova GE' => ['via' => 'Via Garibaldi', 'civico' => '7/1', 'cap' => '16124', 'comune' => 'Genova', 'provincia' => 'GE'],
    'Via Garibaldi 12r Genova' => ['via' => 'Via Garibaldi', 'civico' => '12r', 'comune' => 'Genova'],
    'Viale IV Novembre 10 Treviso' => ['via' => 'Viale IV Novembre', 'civico' => '10', 'comune' => 'Treviso'],
    'Piazza Duomo Milano' => ['via' => 'Piazza Duomo Milano'],
    'Milano' => ['via' => 'Milano'],
];
$analizzatore = new AnalizzatoreIndirizzo();
foreach ($casi as $testo => $atteso) {
    verifica("analizza('$testo')", $atteso, $analizzatore->analizza($testo)->comeArray());
}

// --- Database -------------------------------------------------------------------

if (!getenv('GEOCODER_TEST_DSN')) {
    echo "GEOCODER_TEST_DSN non impostata: salto i test sul database.\n";
} else {
    $config = Configurazione::carica(__DIR__ . '/config.test.php');
    $db = $config->db();
    foreach (glob(dirname(__DIR__) . '/sql/0*.sql') as $file) {
        $db->exec((string) file_get_contents($file));
    }
    $db->exec((string) file_get_contents(__DIR__ . '/fixtures/toponimi_igm.sql'));

    // Normalizzazione SQL.
    $norm = [
        'P.zza S. Maria Maggiore' => 'piazza s maria maggiore',
        'V.le XX Settembre' => 'viale 20 settembre',
        "Via Sant'Antonio" => 'via s antonio',
        'Via Prof. Gaetano Salvemini' => 'via gaetano salvemini',
        'V. Roma' => 'via roma',
        'S.S. 16 Adriatica' => 'ss 16 adriatica',
        'Corso Vittorio Emanuele II' => 'corso vittorio emanuele 2',
        'Località Cà di Sopra' => 'localita ca di sopra',
        'C.so Italia' => 'corso italia',
        'Via F.lli Rosselli' => 'via fratelli rosselli',
    ];
    $st = $db->prepare('SELECT geocoder.normalizza(?)');
    foreach ($norm as $in => $out) {
        $st->execute([$in]);
        verifica("normalizza('$in')", $out, $st->fetchColumn());
    }
    $st = $db->prepare('SELECT geocoder.denominazione(geocoder.normalizza(?))');
    foreach (['Via della Repubblica' => 'repubblica', 'Via G. Garibaldi' => 'garibaldi', 'Strada Provinciale 12' => '12'] as $in => $out) {
        $st->execute([$in]);
        verifica("denominazione('$in')", $out, $st->fetchColumn());
    }
    $st = $db->prepare('SELECT (geocoder.civico(?)).norm');
    foreach (['12' => '12', '12/A' => '12/a', '12 a' => '12/a', '12bis' => '12/bis', '12 bis' => '12/bis',
              '12/1' => '12/1', '12 rosso' => '12/r', 's.n.c.' => 'snc', 'SNC' => 'snc', '007' => '7', 'km 3,5' => 'km 3,5'] as $in => $out) {
        $st->execute([$in]);
        verifica("civico('$in')", $out, $st->fetchColumn());
    }

    // Import (comuni da GeoJSON con ogr2ogr, civici da CSV OpenAddresses, toponimi da tabella in EPSG:3004).
    $imp = new Importatore($db, $config->sezione('importazione'), $config->sezione('db'));
    verifica('import comuni', 3, $imp->importa('comuni')['importate']);
    $civici = $imp->importa('civici');
    verifica('import civici: righe lette', 47, $civici['righe']);
    verifica('import civici: importati', 46, $civici['importate']);
    verifica('import toponimi (scarta quello senza nome)', 3, $imp->importa('toponimi')['importate']);
    verifica('strade aggregate (due "Via Roma" distinte a Genova)', 10, (int) $db->query('SELECT count(*) FROM geocoder.strade')->fetchColumn());
    verifica('civici tutti assegnati a una strada', 0, (int) $db->query('SELECT count(*) FROM geocoder.civici WHERE strada_id IS NULL')->fetchColumn());
    verifica('reimport idempotente', 46, $imp->importa('civici')['importate']);

    // Import da GeoJSON a righe (formato attuale di OpenAddresses).
    $imp2 = new Importatore($db, ['civici' => ['tipo' => 'geojsonl', 'file' => __DIR__ . '/fixtures/oa_civici.geojson']], $config->sezione('db'));
    verifica('import civici da geojsonl', 5, $imp2->importa('civici')['importate']);
    $imp->importa('civici');

    $api = new Api($db);
    $cerca = static function (array $p) use ($api): array {
        [$stato, $corpo] = $api->gestisci('GET', '/search', $p);
        return [$stato, $corpo['features'][0]['properties'] ?? null, $corpo['features'][0]['geometry']['coordinates'] ?? null, $corpo];
    };

    [$s, $r, $xy] = $cerca(['q' => 'Via Giuseppe Garibaldi 13, 20121 Milano']);
    verifica('search civico esatto: stato', 200, $s);
    verifica('search civico esatto: livello', 'civico', $r['livello']);
    verifica('search civico esatto: civico', '13', $r['civico']);
    verifica('search civico esatto: confidenza', 1.0, $r['confidenza']);
    verifica('search civico esatto: coordinate', [9.187, 45.48065], $xy);

    [, $r] = $cerca(['q' => 'v. g. garibaldi 12/a milano']);
    verifica('search con abbreviazioni ed esponente', ['civico', '12 A'], [$r['livello'], $r['civico']]);

    [, $r] = $cerca(['q' => 'Via Garibaldi 22, Milano']);
    verifica('search senza nome di battesimo', ['civico', 'Via Giuseppe Garibaldi'], [$r['livello'], $r['via']]);
    verifica('search senza nome di battesimo: confidenza alta', true, $r['confidenza'] >= 0.8);

    [, $r, $xy] = $cerca(['q' => 'Via Giuseppe Garibaldi 31, Milano']);
    verifica('search civico mancante senza vicini dopo: il più vicino', 'civico_vicino', $r['livello']);
    [, $r, $xy] = $cerca(['q' => 'Via Giuseppe Garibaldi 34, Milano']);
    verifica('search civico interpolato tra 30 e 40', 'civico_interpolato', $r['livello']);
    verifica('search civico interpolato: posizione', [9.1873, 45.4817], [round($xy[0], 4), round($xy[1], 4)]);

    [, $r] = $cerca(['q' => 'Corso 22 Marzo 5 Milano']);
    verifica('search numeri romani', ['civico', 'Corso XXII Marzo'], [$r['livello'], $r['via']]);

    [, $r] = $cerca(['q' => 'Via XX Settembre 12 rosso, Genova']);
    verifica('search civico rosso', ['civico', '12r'], [$r['livello'], $r['civico']]);

    [, $r] = $cerca(['q' => 'Via S. Ambrogio snc, Milano']);
    verifica('search snc', ['civico', "Via Sant'Ambrogio"], [$r['livello'], $r['via']]);

    [, $r] = $cerca(['via' => 'corso italia', 'civico' => '16', 'comune' => "cortina d'ampezzo"]);
    verifica('search strutturata', ['civico', '16', "Cortina d'Ampezzo"], [$r['livello'], $r['civico'], $r['comune']]);

    [, $r] = $cerca(['q' => 'Via Rome 3, Genova']);
    verifica('search con refuso', ['civico', 'Via Roma', '16158'], [$r['livello'], $r['via'], $r['cap']]);

    [, , , $corpo] = $cerca(['q' => 'Via Roma, Genova']);
    verifica('search via omonima in due zone', 2, count($corpo['features']));

    [, $r] = $cerca(['q' => 'Via Roma 3, fraz. Pocol, Cortina']);
    verifica('search con frazione e comune abbreviato', ['civico', '025016'], [$r['livello'], $r['istat']]);

    [, $r] = $cerca(['q' => 'Località Pocol, Cortina']);
    verifica('search fallback toponimo IGM', ['toponimo', 'Pocol', 'igm'], [$r['livello'], $r['nome'], $r['fonte']]);

    [, $r] = $cerca(['q' => 'Chiaravalle']);
    verifica('search solo toponimo', ['toponimo', '015146'], [$r['livello'], $r['istat']]);

    [, $r] = $cerca(['q' => 'Via Inesistente 4, Milano']);
    verifica('search fallback comune', 'comune', $r['livello']);
    verifica('search fallback comune: confidenza bassa', true, $r['confidenza'] < 0.5);

    [, $r] = $cerca(['q' => 'Piazza Duomo Milano']);
    verifica('search comune in fondo senza virgola', ['via', 'P.zza del Duomo'], [$r['livello'], $r['via']]);

    [, $r] = $cerca(['q' => 'Milano']);
    verifica('search solo comune', ['comune', 1.0], [$r['livello'], $r['confidenza']]);

    [$s] = $cerca([]);
    verifica('search senza parametri: 400', 400, $s);

    [$s, $corpo] = $api->gestisci('GET', '/reverse', ['lat' => '45.4806', 'lon' => '9.1873', 'limit' => '2']);
    verifica('reverse: due civici', 2, count($corpo['features']));
    verifica('reverse: il più vicino', '12 A', $corpo['features'][0]['properties']['civico']);
    verifica('reverse: GeoJSON', 'FeatureCollection', $corpo['type']);

    [, $corpo] = $api->gestisci('GET', '/reverse', ['lat' => '46.5291', 'lon' => '12.0971']);
    verifica('reverse: toponimo se non ci sono civici', 'Pocol', $corpo['features'][0]['properties']['nome'] ?? null);

    [, $corpo] = $api->gestisci('GET', '/reverse', ['lat' => '45.40', 'lon' => '9.10']);
    verifica('reverse: comune come ultima risorsa', ['comune', 'Milano'], [
        $corpo['features'][0]['properties']['livello'] ?? null, $corpo['features'][0]['properties']['comune'] ?? null,
    ]);

    [$s] = $api->gestisci('GET', '/reverse', ['lat' => '45', 'lon' => '500']);
    verifica('reverse: coordinate non valide', 400, $s);
}

echo $falliti === 0 ? "OK: $eseguiti verifiche superate.\n" : "$falliti verifiche fallite su $eseguiti.\n";
exit($falliti === 0 ? 0 : 1);
