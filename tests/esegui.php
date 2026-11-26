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
use Topocodificatore\GeocodificaCsv;
use Topocodificatore\Import\Importatore;

$falliti = 0;
$eseguiti = 0;

/**
 * @param mixed $atteso
 * @param mixed $ottenuto
 */
function verifica(string $nome, $atteso, $ottenuto): void
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

// --- Geocodifica di un CSV (con un geocoder finto) -------------------------------

/** @param list<string> $colonne */
function csvGeocodificato(string $csv, array $colonne, ?string $sep = null, bool $intestazione = true): array
{
    $chiamate = 0;
    $finto = static function (string $indirizzo) use (&$chiamate): ?array {
        $chiamate++;
        if (stripos($indirizzo, 'errore') !== false) {
            throw new RuntimeException('database non raggiungibile');
        }
        return stripos($indirizzo, 'roma') !== false
            ? ['confidenza' => 0.83456, 'lat' => 41.9027835, 'lon' => 12.4963655]
            : null;
    };
    $avvisi = [];
    $in = fopen('php://temp', 'w+b');
    fwrite($in, $csv);
    rewind($in);
    $out = fopen('php://temp', 'w+b');
    $conteggio = (new GeocodificaCsv($finto, $colonne, $sep, $intestazione, ['confidenza', 'latitudine', 'longitudine'],
        static function (string $m) use (&$avvisi): void {
            $avvisi[] = $m;
        }))->elabora($in, $out);
    rewind($out);
    return [stream_get_contents($out), $conteggio, $chiamate, $avvisi];
}

[$uscita, $conteggio, $chiamate] = csvGeocodificato(
    "id;Indirizzo;note\n1;Via del Corso 1, Roma;\"a;b\"\n2;Via Inesistente, Nessuno;\n3;;vuoto\n4;via del corso 1, roma;\n",
    ['indirizzo']
);
verifica('csv: colonne aggiunte e separatore riconosciuto', "id;Indirizzo;note;confidenza;latitudine;longitudine\n"
    . "1;\"Via del Corso 1, Roma\";\"a;b\";0.835;41.9027835;12.4963655\n"
    . "2;\"Via Inesistente, Nessuno\";;;;\n"
    . "3;;vuoto;;;\n"
    . "4;\"via del corso 1, roma\";;0.835;41.9027835;12.4963655\n", $uscita);
verifica('csv: conteggio', ['righe' => 4, 'trovati' => 2, 'non_trovati' => 1, 'vuoti' => 1], $conteggio);
verifica('csv: indirizzi ripetuti geocodificati una volta', 2, $chiamate);

[$uscita] = csvGeocodificato("\xEF\xBB\xBFvia,civico,comune\nVia del Corso,1,Roma\n", ['via', 'civico', '3']);
verifica('csv: più colonne, per nome e posizione, con BOM', "\xEF\xBB\xBFvia,civico,comune,confidenza,latitudine,longitudine\n"
    . "\"Via del Corso\",1,Roma,0.835,41.9027835,12.4963655\n", $uscita);

[$uscita] = csvGeocodificato("Via del Corso 1 Roma\tx\n", ['1'], "\t", false);
verifica('csv: senza intestazione', "\"Via del Corso 1 Roma\"\tx\t0.835\t41.9027835\t12.4963655\n", $uscita);

[$uscita] = csvGeocodificato("address,x\nRoma,1\n", []);
verifica('csv: colonna riconosciuta dal nome', "address,x,confidenza,latitudine,longitudine\nRoma,1,0.835,41.9027835,12.4963655\n", $uscita);

[$uscita, $conteggio, , $avvisi] = csvGeocodificato("indirizzo\nerrore Roma\nRoma\n", ['indirizzo']);
verifica('csv: un errore non ferma il file', ["indirizzo,confidenza,latitudine,longitudine\n\"errore Roma\",,,\nRoma,0.835,41.9027835,12.4963655\n", 1],
    [$uscita, count($avvisi)]);

try {
    csvGeocodificato("nome,citta\nx,y\n", ['indirizzo']);
    verifica('csv: colonna mancante', 'eccezione', 'nessuna eccezione');
} catch (RuntimeException $e) {
    verifica('csv: colonna mancante', true, strpos($e->getMessage(), 'nome, citta') !== false);
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
    $db->exec((string) file_get_contents(__DIR__ . '/fixtures/fonti_prova.sql'));

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
    verifica('import civici: righe lette (48 OpenAddresses + 4 OSM)', 52, $civici['righe']);
    verifica('import civici: importati (scartati una riga illeggibile e un doppione OSM)', 50, $civici['importate']);
    verifica('civici per fonte', ['openaddresses' => 47, 'osm' => 3],
        array_map('intval', $db->query('SELECT fonte, count(*) FROM geocoder.civici GROUP BY fonte ORDER BY fonte')->fetchAll(PDO::FETCH_KEY_PAIR)));
    verifica('interno (UNIT) separato dal civico', ['50', 50, 'INT 4'],
        array_values($db->query("SELECT civico, numero, interno FROM geocoder.civici WHERE interno IS NOT NULL")->fetch(PDO::FETCH_NUM)));
    verifica('import toponimi DBSN (scarta quello senza nome)', 4, $imp->importa('toponimi')['importate']);
    verifica('tipo e importanza DBSN', ['centro abitato con più di 1000 abitanti', 0.808],
        array_map(static fn ($v) => is_numeric($v) ? round((float) $v, 3) : $v,
            $db->query("SELECT tipo, importanza FROM geocoder.toponimi_igm WHERE nome = 'Chiaravalle' ORDER BY importanza DESC")->fetch(PDO::FETCH_NUM)));
    verifica('strade aggregate (due "Via Roma" distinte a Genova)', 11, (int) $db->query('SELECT count(*) FROM geocoder.strade')->fetchColumn());
    verifica('nome della strada: preferita la grafia non maiuscola', 1,
        (int) $db->query("SELECT count(*) FROM geocoder.strade WHERE nome = 'Via Giuseppe Garibaldi'")->fetchColumn());
    verifica('civici tutti assegnati a una strada', 0, (int) $db->query('SELECT count(*) FROM geocoder.civici WHERE strada_id IS NULL')->fetchColumn());
    verifica('reimport idempotente', 50, $imp->importa('civici')['importate']);

    // Import da GeoJSON a righe (formato attuale di OpenAddresses).
    $imp2 = new Importatore($db, ['civici' => ['fonte' => 'openaddresses', 'tipo' => 'geojsonl', 'file' => __DIR__ . '/fixtures/oa_civici.geojson']], $config->sezione('db'));
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
    verifica('search con abbreviazioni ed esponente', ['civico', '12/A'], [$r['livello'], $r['civico']]);

    [, $r] = $cerca(['q' => 'Via Garibaldi 22, Milano']);
    verifica('search senza nome di battesimo', ['civico', 'Via Giuseppe Garibaldi'], [$r['livello'], $r['via']]);
    verifica('search senza nome di battesimo: confidenza alta', true, $r['confidenza'] >= 0.8);

    [, $r, $xy] = $cerca(['q' => 'Via Giuseppe Garibaldi 31, Milano']);
    verifica('search civico che c\'è solo in OSM', ['civico', 'osm'], [$r['livello'], $r['fonte']]);
    [, $r, $xy] = $cerca(['q' => 'Via Giuseppe Garibaldi 33, Milano']);
    verifica('search civico mancante senza vicini dopo: il più vicino', 'civico_vicino', $r['livello']);
    [, $r] = $cerca(['q' => 'Via Brera 2, Milano']);
    verifica('search via che c\'è solo in OSM', ['civico', 'osm'], [$r['livello'], $r['fonte']]);
    [, $r] = $cerca(['q' => 'VIA GIUSEPPE GARIBALDI 50 MILANO']);
    verifica('search in maiuscolo, civico con interno', ['civico', '50'], [$r['livello'], $r['civico']]);
    [, $r] = $cerca(['q' => 'Via Roma, Voltri, Genova']);
    verifica('search con frazione (DISTRICT): sceglie la Via Roma giusta', '16158', $r['cap']);
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
    verifica('search fallback toponimo DBSN', ['toponimo', 'Pocol', 'dbsn'], [$r['livello'], $r['nome'], $r['fonte']]);

    [, $r] = $cerca(['q' => 'Chiaravalle']);
    verifica('search solo toponimo', ['toponimo', '015146'], [$r['livello'], $r['istat']]);

    [, $r] = $cerca(['q' => 'Via Inesistente 4, Milano']);
    verifica('search fallback comune', 'comune', $r['livello']);
    verifica('search fallback comune: confidenza bassa', true, $r['confidenza'] < 0.5);

    [, $r] = $cerca(['q' => 'Piazza Duomo Milano']);
    verifica('search comune in fondo senza virgola', ['via', 'P.zza del Duomo'], [$r['livello'], $r['via']]);

    [, $r] = $cerca(['q' => 'Milano']);
    verifica('search solo comune', ['comune', 1.0], [$r['livello'], $r['confidenza']]);

    // La funzione geocodifica(): lista di posizioni con probabilità.
    $candidati = \Topocodificatore\geocodifica('Via Roma, Genova', 5, $db);
    verifica('geocodifica: due candidati', 2, count($candidati));
    verifica('geocodifica: probabilità che sommano a 1', 1.0, round(array_sum(array_column($candidati, 'probabilita')), 2));
    verifica('geocodifica: coordinate', true, isset($candidati[0]['lat'], $candidati[0]['lon']));
    verifica('geocodifica: niente', [], \Topocodificatore\geocodifica('  ', 5, $db));

    // Lo script bin/geocodifica_csv.php sul database di prova.
    $cartella = sys_get_temp_dir() . '/topocodificatore_' . getmypid();
    @mkdir($cartella);
    file_put_contents("$cartella/ingresso.csv", "id;indirizzo\n1;Via Garibaldi 13, Milano\n2;\n");
    $comando = sprintf('%s %s %s %s --config=%s 2>&1', escapeshellarg(PHP_BINARY),
        escapeshellarg(dirname(__DIR__) . '/bin/geocodifica_csv.php'), escapeshellarg("$cartella/ingresso.csv"),
        escapeshellarg("$cartella/uscita.csv"), escapeshellarg(__DIR__ . '/config.test.php'));
    exec($comando, $righeComando, $stato);
    $righe = is_file("$cartella/uscita.csv") ? array_map(static function (string $r): array {
        return str_getcsv($r, ';', '"', '');
    }, file("$cartella/uscita.csv", FILE_IGNORE_NEW_LINES)) : [];
    verifica('geocodifica_csv: esito', 0, $stato);
    verifica('geocodifica_csv: intestazione', ['id', 'indirizzo', 'confidenza', 'latitudine', 'longitudine'], $righe[0] ?? null);
    verifica('geocodifica_csv: coordinate del civico', ['45.48065', '9.187'], [
        isset($righe[1][3]) ? (string) round((float) $righe[1][3], 5) : null,
        isset($righe[1][4]) ? (string) round((float) $righe[1][4], 3) : null,
    ]);
    verifica('geocodifica_csv: confidenza tra 0 e 1', true, isset($righe[1][2]) && (float) $righe[1][2] > 0 && (float) $righe[1][2] <= 1);
    verifica('geocodifica_csv: riga senza indirizzo', ['2', '', '', '', ''], $righe[2] ?? null);
    @unlink("$cartella/ingresso.csv");
    @unlink("$cartella/uscita.csv");
    @rmdir($cartella);

    [$s] = $cerca([]);
    verifica('search senza parametri: 400', 400, $s);

    [$s, $corpo] = $api->gestisci('GET', '/reverse', ['lat' => '45.4806', 'lon' => '9.1873', 'limit' => '2']);
    verifica('reverse: due civici', 2, count($corpo['features']));
    verifica('reverse: il più vicino', '12/A', $corpo['features'][0]['properties']['civico']);
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
