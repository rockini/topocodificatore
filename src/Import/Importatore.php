<?php

declare(strict_types=1);

namespace Topocodificatore\Import;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Topocodificatore\Configurazione;

/**
 * Import ripetibile dei dataset (comuni ISTAT, civici OpenAddresses, toponimi IGM).
 *
 * Ogni import ha due fasi:
 *  1. la fonte, qualunque sia il suo formato, viene copiata in staging.<dataset>
 *     con colonne canoniche, seguendo la mappatura scritta nella configurazione;
 *  2. sql/import/<dataset>.sql trasforma lo staging nelle tabelle definitive,
 *     dentro una transazione: se qualcosa va storto i dati precedenti restano.
 *
 * Tipi di fonte:
 *  - tabella:  una tabella o una query nello stesso database PostgreSQL;
 *              la mappatura contiene espressioni SQL sull'alias "t";
 *  - file:     qualunque formato letto da ogr2ogr (Shapefile, GeoPackage,
 *              GeoJSON, CSV...); il file finisce in staging.<dataset>_sorgente
 *              e poi si procede come per "tabella";
 *  - pdo:      una tabella o una query in un altro database (qualunque driver
 *              PDO); la geometria va restituita come testo (WKT/EWKT/WKB
 *              esadecimale) oppure come lon/lat;
 *  - csv:      file CSV di OpenAddresses (anche .gz, anche più file con un glob);
 *              la mappatura contiene nomi di colonna;
 *  - geojsonl: file GeoJSON a una feature per riga, il formato attuale di
 *              OpenAddresses; la mappatura contiene nomi di proprietà.
 */
final class Importatore
{
    /** Colonne canoniche di ogni dataset (oltre a geom oppure lon/lat). */
    public const DATASET = [
        'comuni' => ['istat', 'nome', 'provincia', 'regione'],
        'civici' => ['fonte_id', 'via', 'civico', 'interno', 'frazione', 'comune', 'provincia', 'cap'],
        'toponimi' => ['fonte_id', 'nome', 'tipo', 'importanza', 'attributi'],
    ];

    /** Colonne obbligatorie nella mappatura. */
    private const OBBLIGATORIE = [
        'comuni' => ['istat', 'nome'],
        'civici' => ['via'],
        'toponimi' => ['nome'],
    ];

    /** Mappatura predefinita per i file di OpenAddresses. */
    private const OPENADDRESSES = [
        'csv' => [
            'fonte_id' => 'hash', 'via' => 'street', 'civico' => 'number', 'interno' => 'unit',
            'frazione' => 'district', 'comune' => 'city', 'provincia' => 'region', 'cap' => 'postcode',
            'lon' => 'lon', 'lat' => 'lat',
        ],
        'geojsonl' => [
            'fonte_id' => 'hash', 'via' => 'street', 'civico' => 'number', 'interno' => 'unit',
            'frazione' => 'district', 'comune' => 'city', 'provincia' => 'region', 'cap' => 'postcode',
        ],
    ];

    private PDO $db;

    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, mixed> */
    private array $configDb;

    /** @var callable(string): void */
    private $log;

    /**
     * @param array<string, mixed> $config la sezione "importazione" della configurazione
     * @param array<string, mixed> $configDb la sezione "db", serve a ogr2ogr
     */
    public function __construct(PDO $db, array $config, array $configDb, ?callable $log = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->configDb = $configDb;
        $this->log = $log ?? static function (string $m): void {
        };
    }

    /** @return array{righe: int, scartate: int, importate: int} */
    public function importa(string $dataset): array
    {
        if (!isset(self::DATASET[$dataset])) {
            throw new InvalidArgumentException("Dataset sconosciuto: $dataset");
        }
        $specs = $this->config[$dataset] ?? null;
        if (!is_array($specs) || $specs === []) {
            throw new InvalidArgumentException("Nella configurazione manca importazione.$dataset");
        }
        // Una fonte sola oppure un elenco di fonti, in ordine di priorità.
        if (!self::eLista($specs)) {
            $specs = [$specs];
        }
        $iniziata = date('c');
        $this->db->exec("TRUNCATE staging.$dataset");
        $sorgenti = [];
        foreach ($specs as $priorita => $spec) {
            $sorgenti[] = $this->caricaFonte($dataset, $spec, $priorita);
        }
        $sorgente = implode(' + ', $sorgenti);
        $righe = (int) $this->db->query("SELECT count(*) FROM staging.$dataset")->fetchColumn();
        $senzaGeom = (int) $this->db->query("SELECT count(*) FROM staging.$dataset WHERE geom IS NULL")->fetchColumn();

        ($this->log)("[$dataset] trasformazione ($righe righe, $senzaGeom senza geometria)");
        $tabella = $dataset === 'toponimi' ? 'toponimi_igm' : $dataset;
        $this->db->beginTransaction();
        try {
            $this->db->exec((string) file_get_contents(dirname(__DIR__, 2) . "/sql/import/$dataset.sql"));
            $importate = (int) $this->db->query("SELECT count(*) FROM geocoder.$tabella")->fetchColumn();
            $this->db->prepare('INSERT INTO geocoder.importazioni (dataset, sorgente, righe, scartate, iniziata)
                                VALUES (?, ?, ?, ?, ?)')
                ->execute([$dataset, $sorgente, $righe, $righe - $importate, $iniziata]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->db->exec("ANALYZE geocoder.$tabella");
        if ($dataset === 'civici') {
            $this->db->exec('ANALYZE geocoder.strade');
        }
        $this->db->exec("TRUNCATE staging.$dataset");
        ($this->log)("[$dataset] fatto: $importate importate, " . ($righe - $importate) . ' scartate o duplicate');

        return ['righe' => $righe, 'scartate' => $righe - $importate, 'importate' => $importate];
    }

    /**
     * Carica una fonte in staging e ne calcola le geometrie.
     *
     * @param array<string, mixed> $spec
     * @return string descrizione della fonte, per il registro
     */
    private function caricaFonte(string $dataset, array $spec, int $priorita): string
    {
        $tipo = $spec['tipo'] ?? '';
        $fontePredefinita = ['civici' => 'openaddresses', 'toponimi' => 'igm'][$dataset] ?? 'istat';
        $fonte = (string) ($spec['fonte'] ?? $fontePredefinita);
        ($this->log)("[$dataset] caricamento in staging di '$fonte' (tipo $tipo)");
        $srid = (int) ($spec['srid'] ?? 4326);
        // match() di PHP 8 confronta con ===: lo switch non deve accettare 0 o true.
        switch (is_string($tipo) ? $tipo : null) {
            case 'tabella':
                $descrizione = $this->daTabella($dataset, $spec);
                break;
            case 'file':
                $descrizione = $this->daFile($dataset, $spec, $srid);
                break;
            case 'pdo':
                $descrizione = $this->daPdo($dataset, $spec);
                break;
            case 'csv':
                $descrizione = $this->daCsv($dataset, $spec);
                break;
            case 'geojsonl':
                $descrizione = $this->daGeojsonl($dataset, $spec);
                break;
            default:
                throw new InvalidArgumentException("importazione.$dataset.tipo non valido: '$tipo'");
        }
        $this->db->prepare("UPDATE staging.$dataset SET fonte = :f, priorita = :p WHERE priorita IS NULL")
            ->execute(['f' => $fonte, 'p' => $priorita]);

        ($this->log)("[$dataset] calcolo delle geometrie di '$fonte' (SRID fonte $srid)");
        $st = $this->db->prepare("UPDATE staging.$dataset SET geom = geocoder.geometria_sorgente(geom_txt, lon, lat, :srid)
                                  WHERE priorita = :p");
        $st->execute(['srid' => $srid, 'p' => $priorita]);
        $st = $this->db->prepare("SELECT count(*), count(geom) FROM staging.$dataset WHERE priorita = :p");
        $st->execute(['p' => $priorita]);
        [$righe, $conGeom] = array_map('intval', $st->fetch(PDO::FETCH_NUM));
        if ($righe > 0 && $conGeom === 0) {
            throw new RuntimeException("[$dataset] '$fonte': nessuna geometria leggibile su $righe righe: "
                . 'controlla la mappatura di geom o lon/lat e lo srid');
        }
        return "$fonte: $descrizione";
    }

    /**
     * Mappatura validata: colonna canonica => espressione/nome nella fonte.
     *
     * @param array<string, mixed> $spec
     * @param array<string, string> $predefinita
     * @return array<string, string>
     */
    private function mappatura(string $dataset, array $spec, array $predefinita = []): array
    {
        $colonne = ($spec['colonne'] ?? []) + $predefinita;
        $ammesse = [...self::DATASET[$dataset], 'geom', 'lon', 'lat'];
        $mappa = [];
        foreach ($colonne as $canonica => $sorgente) {
            if (!in_array($canonica, $ammesse, true)) {
                throw new InvalidArgumentException("importazione.$dataset.colonne: '$canonica' non è una colonna valida (ammesse: "
                    . implode(', ', $ammesse) . ')');
            }
            if ($sorgente !== null && $sorgente !== '') {
                $mappa[$canonica] = (string) $sorgente;
            }
        }
        foreach (self::OBBLIGATORIE[$dataset] as $c) {
            if (!isset($mappa[$c])) {
                throw new InvalidArgumentException("importazione.$dataset.colonne: manca '$c'");
            }
        }
        if (!isset($mappa['geom']) && !(isset($mappa['lon'], $mappa['lat']))) {
            throw new InvalidArgumentException("importazione.$dataset.colonne: serve 'geom' oppure 'lon' e 'lat'");
        }
        return $mappa;
    }

    /**
     * Equivalente di array_is_list() (PHP 8.1).
     *
     * @param array<mixed> $a
     */
    private static function eLista(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    private static function colonnaStaging(string $canonica): string
    {
        return $canonica === 'geom' ? 'geom_txt' : $canonica;
    }

    /** @param array<string, mixed> $spec */
    private static function from(array $spec, string $dataset): string
    {
        if (!empty($spec['query'])) {
            return '(' . $spec['query'] . ') AS t';
        }
        $tabella = (string) ($spec['tabella'] ?? '');
        if (!preg_match('/^("[^"]+"|[A-Za-z_]\w*)(\.("[^"]+"|[A-Za-z_]\w*))?$/', $tabella)) {
            throw new InvalidArgumentException("importazione.$dataset: indica 'tabella' (es. public.indirizzi) oppure 'query'");
        }
        return "$tabella AS t";
    }

    /** @param array<string, mixed> $spec */
    private function daTabella(string $dataset, array $spec): string
    {
        $mappa = $this->mappatura($dataset, $spec);
        $from = self::from($spec, $dataset);
        $dest = [];
        $espr = [];
        foreach ($mappa as $canonica => $e) {
            $dest[] = self::colonnaStaging($canonica);
            $espr[] = "($e)::text";
        }
        $sql = sprintf(
            'INSERT INTO staging.%s (%s) SELECT %s FROM %s%s',
            $dataset,
            implode(', ', $dest),
            implode(', ', $espr),
            $from,
            empty($spec['filtro']) ? '' : ' WHERE ' . $spec['filtro'],
        );
        $n = $this->db->exec($sql);
        ($this->log)("[$dataset] $n righe copiate da $from");
        return $from;
    }

    /** @param array<string, mixed> $spec */
    private function daFile(string $dataset, array $spec, int &$srid): string
    {
        $file = (string) ($spec['file'] ?? '');
        if ($file === '' || !file_exists($file)) {
            throw new InvalidArgumentException("importazione.$dataset.file: file non trovato: '$file'");
        }
        $dsn = (string) $this->configDb['dsn'];
        $parti = [];
        foreach (explode(';', preg_replace('/^pgsql:/', '', $dsn)) as $coppia) {
            if (strpos($coppia, '=') !== false) {
                [$k, $v] = explode('=', $coppia, 2);
                $parti[] = trim($k) . '=' . trim($v);
            }
        }
        if (!empty($this->configDb['utente'])) {
            $parti[] = 'user=' . $this->configDb['utente'];
        }
        $destinazione = "staging.{$dataset}_sorgente";
        $cmd = [
            $spec['ogr2ogr'] ?? 'ogr2ogr', '-f', 'PostgreSQL', 'PG:' . implode(' ', $parti), $file,
            '-nln', $destinazione, '-overwrite', '-lco', 'GEOMETRY_NAME=geom', '-lco', 'SPATIAL_INDEX=NONE',
            '-lco', 'FID=ogc_fid', '-t_srs', 'EPSG:4326', '--config', 'PG_USE_COPY', 'YES',
        ];
        if (isset($spec['srid'])) {
            $cmd = [...$cmd, '-s_srs', 'EPSG:' . (int) $spec['srid']];
        }
        if (!empty($spec['codifica'])) {
            $cmd = [...$cmd, '--config', 'SHAPE_ENCODING', (string) $spec['codifica']];
        }
        foreach ($spec['opzioni_ogr'] ?? [] as $o) {
            $cmd[] = (string) $o;
        }
        if (!empty($spec['layer'])) {
            $cmd[] = (string) $spec['layer'];
        }
        ($this->log)("[$dataset] ogr2ogr $file -> $destinazione");
        $env = getenv();
        if (!empty($this->configDb['password'])) {
            $env['PGPASSWORD'] = (string) $this->configDb['password'];
        }
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, null, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('Impossibile avviare ogr2ogr (è installato? pacchetto gdal-bin)');
        }
        $out = stream_get_contents($pipe[1]) . stream_get_contents($pipe[2]);
        if (proc_close($proc) !== 0) {
            throw new RuntimeException("ogr2ogr non è riuscito a caricare $file:\n$out");
        }
        // ogr2ogr ha già riproiettato le geometrie in 4326; lon/lat di un CSV
        // restano invece nel sistema dichiarato in srid.
        if (isset(($spec['colonne'] ?? [])['geom'])) {
            $srid = 4326;
        }
        $this->daTabella($dataset, ['tabella' => $destinazione] + $spec);
        return $file;
    }

    /** @param array<string, mixed> $spec */
    private function daPdo(string $dataset, array $spec): string
    {
        $mappa = $this->mappatura($dataset, $spec);
        $sorgente = Configurazione::connetti((string) ($spec['dsn'] ?? ''), $spec['utente'] ?? null, $spec['password'] ?? null);
        $sorgente->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        $espr = [];
        foreach ($mappa as $canonica => $e) {
            $espr[] = "$e AS " . ($canonica === 'geom' ? 'geom_txt' : $canonica);
        }
        $from = self::from($spec, $dataset);
        $sql = 'SELECT ' . implode(', ', $espr) . " FROM $from" . (empty($spec['filtro']) ? '' : ' WHERE ' . $spec['filtro']);
        if ($sorgente->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sorgente->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
        $scrittore = new ScrittoreCopy($this->db, "staging.$dataset", array_map([self::class, 'colonnaStaging'], array_keys($mappa)));
        foreach ($sorgente->query($sql, PDO::FETCH_ASSOC) as $riga) {
            $scrittore->aggiungi(array_map(static fn ($v) => $v === null ? null : (string) $v, $riga));
            if ($scrittore->righe() % 100000 === 0) {
                ($this->log)("[$dataset] " . $scrittore->righe() . ' righe...');
            }
        }
        $scrittore->svuota();
        ($this->log)("[$dataset] {$scrittore->righe()} righe lette dal database " . $spec['dsn']);
        return (string) $spec['dsn'];
    }

    /**
     * @param array<string, mixed> $spec
     * @return list<string>
     */
    private static function file(string $dataset, array $spec): array
    {
        $file = glob((string) ($spec['file'] ?? '')) ?: [];
        if ($file === []) {
            throw new InvalidArgumentException("importazione.$dataset.file: nessun file corrisponde a '" . ($spec['file'] ?? '') . "'");
        }
        return $file;
    }

    /** @return resource */
    private static function apri(string $file)
    {
        $h = fopen(substr($file, -3) === '.gz' ? "compress.zlib://$file" : $file, 'rb');
        if ($h === false) {
            throw new RuntimeException("Impossibile aprire $file");
        }
        return $h;
    }

    /** @param array<string, mixed> $spec */
    private function daCsv(string $dataset, array $spec): string
    {
        $mappa = $this->mappatura($dataset, $spec, $dataset === 'civici' ? self::OPENADDRESSES['csv'] : []);
        $sep = (string) ($spec['separatore'] ?? ',');
        $scrittore = new ScrittoreCopy($this->db, "staging.$dataset", array_map([self::class, 'colonnaStaging'], array_keys($mappa)));
        $elenco = self::file($dataset, $spec);
        foreach ($elenco as $file) {
            $h = self::apri($file);
            $intestazione = fgetcsv($h, 0, $sep, '"', '');
            if (!$intestazione) {
                fclose($h);
                continue;
            }
            $intestazione[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $intestazione[0]);
            $indice = array_flip(array_map(static fn ($c) => strtolower(trim((string) $c)), $intestazione));
            $pos = [];
            foreach ($mappa as $canonica => $nome) {
                if (!isset($indice[strtolower($nome)])) {
                    throw new InvalidArgumentException("$file: manca la colonna '$nome' (mappata su '$canonica')");
                }
                $pos[self::colonnaStaging($canonica)] = $indice[strtolower($nome)];
            }
            while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) {
                if ($r === [null]) {
                    continue;
                }
                $riga = [];
                foreach ($pos as $col => $i) {
                    $v = $r[$i] ?? null;
                    $riga[$col] = $v === '' ? null : $v;
                }
                $scrittore->aggiungi($riga);
            }
            fclose($h);
            ($this->log)("[$dataset] letto $file ({$scrittore->righe()} righe in totale)");
        }
        $scrittore->svuota();
        return implode(', ', $elenco);
    }

    /** @param array<string, mixed> $spec */
    private function daGeojsonl(string $dataset, array $spec): string
    {
        $mappa = $this->mappatura($dataset, $spec + ['colonne' => []], ['geom' => '(geometria della feature)']
            + ($dataset === 'civici' ? self::OPENADDRESSES['geojsonl'] : []));
        unset($mappa['lon'], $mappa['lat']);
        $scrittore = new ScrittoreCopy($this->db, "staging.$dataset", array_map([self::class, 'colonnaStaging'], array_keys($mappa)));
        $elenco = self::file($dataset, $spec);
        foreach ($elenco as $file) {
            $h = self::apri($file);
            while (($linea = fgets($h)) !== false) {
                $linea = trim($linea, " \t\n\r\x1e");
                if ($linea === '') {
                    continue;
                }
                $f = json_decode($linea, true);
                if (!is_array($f)) {
                    continue;
                }
                $prop = array_change_key_case((array) ($f['properties'] ?? []), CASE_LOWER);
                $riga = [];
                foreach ($mappa as $canonica => $nome) {
                    if ($canonica === 'geom') {
                        $riga['geom_txt'] = isset($f['geometry']) ? json_encode($f['geometry']) : null;
                        continue;
                    }
                    $v = $prop[strtolower($nome)] ?? null;
                    $riga[$canonica] = ($v === '' || $v === null) ? null : (is_scalar($v) ? (string) $v : json_encode($v));
                }
                $scrittore->aggiungi($riga);
            }
            fclose($h);
            ($this->log)("[$dataset] letto $file ({$scrittore->righe()} righe in totale)");
        }
        $scrittore->svuota();
        return implode(', ', $elenco);
    }
}
