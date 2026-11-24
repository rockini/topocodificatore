<?php

declare(strict_types=1);

namespace Topocodificatore;

use PDO;

/**
 * Geocoding diretto con ricerca a cascata:
 *   civico -> via -> frazione/toponimo IGM -> comune.
 *
 * Ogni risultato ha un "livello" (quanto è preciso il punto restituito) e una
 * "confidenza" tra 0 e 1 (quanto il risultato corrisponde a ciò che è stato
 * chiesto). Un risultato meno preciso di quanto richiesto, per esempio il
 * centro della via quando era stato chiesto un civico, perde confidenza.
 */
final class Geocoder
{
    /** Precisione di ciascun livello, dal più preciso. */
    private const RANGO = ['civico' => 4, 'civico_interpolato' => 4, 'civico_vicino' => 4, 'via' => 3, 'toponimo' => 2, 'comune' => 1];

    /** DUG che indicano un luogo più che una strada: si cerca anche tra i toponimi. */
    private const DUG_LUOGO = ['localita', 'frazione', 'contrada', 'borgo', 'borgata', 'case sparse', 'regione', 'villaggio', 'rione', 'quartiere'];

    private PDO $db;

    /** @var array<string, float> */
    private array $soglie;

    /** @param array<string, float> $soglie soglie di similarità (0..1) per via, toponimo e comune */
    public function __construct(PDO $db, array $soglie = [])
    {
        $this->db = $db;
        $this->soglie = $soglie + ['via' => 0.55, 'toponimo' => 0.5, 'comune' => 0.5];
        $this->db->exec('SET pg_trgm.similarity_threshold = 0.3');
        $this->db->exec('SET pg_trgm.strict_word_similarity_threshold = 0.6');
    }

    /** @return list<array<string, mixed>> risultati ordinati per confidenza */
    public function cerca(Componenti $c, int $limite = 5): array
    {
        $n = $this->normalizza($c);

        // 1. Comune: dal nome, dal CAP o dalla sola provincia.
        $comuni = null;          // null = nessun vincolo sul comune
        $fattoreComune = 0.75;   // senza comune la stessa via può stare in mille posti
        if ($n['comune'] !== null && $n['comune'] !== '') {
            $comuni = $this->trovaComuni($n['comune'], $c->provincia);
            if ($comuni === []) {
                // Non è un comune: forse una frazione o località ("Cervinia").
                $n['localita'] ??= $n['comune'];
                $comuni = $c->provincia ? $this->comuniDellaProvincia($c->provincia) : null;
            } else {
                $fattoreComune = max(array_column($comuni, 'punteggio'));
            }
        } elseif ($c->cap !== null) {
            $comuni = $this->comuniDelCap($c->cap) ?: null;
            $fattoreComune = $comuni ? 0.9 : 0.75;
        } elseif ($c->provincia !== null) {
            $comuni = $this->comuniDellaProvincia($c->provincia);
            $fattoreComune = 0.8;
        }

        // Un solo pezzo di testo senza comune: il comune può essere in fondo
        // ("Piazza Duomo Milano") o può essere l'intero testo ("Milano").
        $ambiguo = $c->comune === null && $c->civico === null && $c->localita === null;
        if ($comuni === null && $n['via'] !== null) {
            $diviso = $this->comuneInFondo($n['via'], $c->provincia);
            if ($diviso !== null) {
                [$n['via'], $comuni] = $diviso;
                $fattoreComune = 1.0;
                if ($n['via'] === null) {
                    $n['dug'] = $n['denominazione'] = null;
                } else {
                    $n = array_merge($n, $this->normalizzaVia($n['via']));
                }
            }
        }

        if ($c->civico !== null && $n['via'] !== null) {
            $richiesto = 'civico';
        } elseif ($n['via'] !== null) {
            $richiesto = 'via';
        } elseif ($n['localita'] !== null) {
            $richiesto = 'toponimo';
        } else {
            $richiesto = 'comune';
        }
        $istat = $comuni === null ? null : array_column($comuni, 'istat');
        $risultati = [];

        // Località: serve come risultato e per scegliere tra vie omonime.
        $toponimiLocalita = [];
        if ($n['localita'] !== null) {
            $toponimiLocalita = $this->cercaToponimi($n['localita'], $n['localita'], $istat, $limite);
        }

        // 2. Via e civico.
        if ($n['via'] !== null) {
            $vicino = $toponimiLocalita[0] ?? null;
            foreach ($this->cercaStrade($n, $istat, $c->cap, $vicino, $n['localita'], $limite) as $s) {
                $r = $c->civico !== null ? $this->risolviCivico($s, $n['civico']) : null;
                $r ??= $this->risultatoVia($s);
                $r['confidenza'] = $r['confidenza'] * $fattoreComune;
                $risultati[] = $r;
            }
            // 3. Toponimo IGM al posto della via ("Località Pian del Re", o nessuna via trovata).
            $cercaLuogo = $risultati === [] || in_array($n['dug'], self::DUG_LUOGO, true) || $ambiguo;
            if ($cercaLuogo) {
                foreach ($this->cercaToponimi($n['denominazione'] ?? $n['via'], $n['via'], $istat, $limite) as $t) {
                    $t['confidenza'] *= $fattoreComune;
                    $risultati[] = $t;
                }
            }
        }
        if ($n['via'] === null || $risultati === []) {
            foreach ($toponimiLocalita as $t) {
                $t['confidenza'] *= $fattoreComune;
                $risultati[] = $t;
            }
        }

        // 4. Comune.
        if ($risultati === [] && $comuni !== null && count($comuni) <= $limite) {
            foreach ($comuni as $k) {
                $risultati[] = $this->risultatoComune($k);
            }
        }
        if ($risultati === [] && $ambiguo && $n['via'] !== null) {
            foreach ($this->trovaComuni($n['via'], $c->provincia) as $k) {
                $risultati[] = $this->risultatoComune($k);
            }
        }

        // Penalità per i risultati meno precisi del richiesto.
        foreach ($risultati as &$r) {
            $mancanti = max(0, self::RANGO[$richiesto] - self::RANGO[$r['livello']]);
            if (!$ambiguo) {
                $r['confidenza'] *= 1 - 0.2 * $mancanti;
            }
            $r['confidenza'] = round(min(1.0, $r['confidenza']), 3);
        }
        unset($r);
        self::ordinaStabile($risultati, static fn ($a, $b) => $b['confidenza'] <=> $a['confidenza']);
        $risultati = array_slice($risultati, 0, $limite);

        // Probabilità: la confidenza di ciascun candidato rispetto agli altri
        // restituiti (somma 1). La confidenza resta il valore assoluto.
        $totale = array_sum(array_column($risultati, 'confidenza'));
        foreach ($risultati as &$r) {
            $r = ['livello' => $r['livello'], 'confidenza' => $r['confidenza'],
                  'probabilita' => $totale > 0 ? round($r['confidenza'] / $totale, 3) : 0.0] + $r;
        }
        unset($r);
        return $risultati;
    }

    /** @return array<string, ?string> */
    private function normalizza(Componenti $c): array
    {
        $st = $this->db->prepare(
            'SELECT geocoder.normalizza(:comune) AS comune, geocoder.normalizza(:localita) AS localita,
                    (geocoder.civico(:civico)).norm AS civico_norm,
                    (geocoder.civico(:civico2)).numero AS numero,
                    coalesce((geocoder.civico(:civico3)).esponente, \'\') AS esponente,
                    coalesce((geocoder.civico(:civico4)).snc, false) AS snc'
        );
        $st->execute([
            'comune' => $c->comune, 'localita' => $c->localita,
            'civico' => $c->civico, 'civico2' => $c->civico, 'civico3' => $c->civico, 'civico4' => $c->civico,
        ]);
        $r = $st->fetch();
        $n = [
            'comune' => $r['comune'] ?: null,
            'localita' => $r['localita'] ?: null,
            'civico' => ['norm' => $r['civico_norm'], 'numero' => $r['numero'], 'esponente' => $r['esponente'], 'snc' => (bool) $r['snc']],
            'via' => null, 'dug' => null, 'denominazione' => null,
        ];
        if ($c->via !== null && trim($c->via) !== '') {
            $st = $this->db->prepare('SELECT geocoder.normalizza(:via)');
            $st->execute(['via' => $c->via]);
            $via = (string) $st->fetchColumn();
            if ($via !== '') {
                $n = array_merge($n, ['via' => $via], $this->normalizzaVia($via));
            }
        }
        return $n;
    }

    /** @return array{dug: ?string, denominazione: string} */
    private function normalizzaVia(string $viaNorm): array
    {
        $st = $this->db->prepare('SELECT geocoder.dug(v) AS dug, geocoder.denominazione(v) AS denominazione FROM (SELECT :v::text AS v) p');
        $st->execute(['v' => $viaNorm]);
        return $st->fetch();
    }

    /** @return list<array<string, mixed>> */
    private function trovaComuni(string $testo, ?string $provincia): array
    {
        $st = $this->db->prepare(
            'WITH p AS (SELECT geocoder.normalizza(:testo) AS n, :prov::text AS prov)
             SELECT k.istat, k.nome, k.provincia, ST_X(k.centroide) AS lon, ST_Y(k.centroide) AS lat,
                    CASE WHEN k.nome_norm = p.n THEN 1.0
                         -- "Cortina" per "Cortina d Ampezzo": vale poco meno del nome intero.
                         ELSE greatest(similarity(k.nome_norm, p.n), 0.9 * strict_word_similarity(p.n, k.nome_norm)) END AS punteggio
             FROM geocoder.comuni k, p
             WHERE (k.nome_norm = p.n OR k.nome_norm % p.n OR p.n <<% k.nome_norm)
               AND (p.prov IS NULL OR k.provincia IS NULL OR k.provincia = p.prov)
             ORDER BY punteggio DESC, k.nome
             LIMIT 10'
        );
        $st->execute(['testo' => $testo, 'prov' => $provincia]);
        $tutti = array_values(array_filter($st->fetchAll(), fn ($k) => (float) $k['punteggio'] >= $this->soglie['comune']));
        $esatti = array_values(array_filter($tutti, static fn ($k) => (float) $k['punteggio'] >= 1.0));
        if ($esatti !== []) {
            return $esatti;
        }
        // Solo i migliori: "Milan" deve dare Milano, non anche Mila(z)zo.
        $migliore = (float) ($tutti[0]['punteggio'] ?? 0);
        return array_values(array_filter($tutti, static fn ($k) => (float) $k['punteggio'] >= $migliore - 0.1));
    }

    /** @return list<array<string, mixed>> */
    private function comuniDellaProvincia(string $provincia): array
    {
        $st = $this->db->prepare(
            'SELECT istat, nome, provincia, ST_X(centroide) AS lon, ST_Y(centroide) AS lat, 0.8 AS punteggio
             FROM geocoder.comuni WHERE provincia = :p'
        );
        $st->execute(['p' => strtoupper($provincia)]);
        return $st->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    private function comuniDelCap(string $cap): array
    {
        $st = $this->db->prepare(
            'SELECT k.istat, k.nome, k.provincia, ST_X(k.centroide) AS lon, ST_Y(k.centroide) AS lat, 0.9 AS punteggio
             FROM geocoder.comuni k
             WHERE k.istat IN (SELECT DISTINCT istat FROM geocoder.civici WHERE cap = :cap)'
        );
        $st->execute(['cap' => $cap]);
        return $st->fetchAll();
    }

    /**
     * "piazza duomo milano" -> ["piazza duomo", [Milano]]; "milano" -> [null, [Milano]].
     *
     * @return array{0: ?string, 1: list<array<string, mixed>>}|null
     */
    private function comuneInFondo(string $viaNorm, ?string $provincia): ?array
    {
        $parole = explode(' ', $viaNorm);
        $st = $this->db->prepare(
            'SELECT istat, nome, provincia, ST_X(centroide) AS lon, ST_Y(centroide) AS lat, 1.0 AS punteggio
             FROM geocoder.comuni WHERE nome_norm = :n AND (:prov::text IS NULL OR provincia = :prov2)'
        );
        for ($k = min(5, count($parole)); $k >= 1; $k--) {
            $prima = array_slice($parole, 0, count($parole) - $k);
            // Non lasciare una via fatta solo di DUG: "via roma" resta una via.
            if (count($prima) === 1 && in_array($prima[0], ['via', 'viale', 'piazza', 'corso', 'largo', 'vicolo', 'strada', 'localita', 'frazione'], true)) {
                continue;
            }
            $st->execute(['n' => implode(' ', array_slice($parole, -$k)), 'prov' => $provincia, 'prov2' => $provincia]);
            $comuni = $st->fetchAll();
            if ($comuni !== []) {
                return [$prima === [] ? null : implode(' ', $prima), $comuni];
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $n
     * @param list<string>|null $istat
     * @param array<string, mixed>|null $vicino toponimo della località indicata, per scegliere tra vie omonime
     * @return list<array<string, mixed>>
     */
    private function cercaStrade(array $n, ?array $istat, ?string $cap, ?array $vicino, ?string $localita, int $limite): array
    {
        $st = $this->db->prepare(
            'WITH p AS (SELECT :den::text AS den, :via::text AS via, :istat::text[] AS istat, :loc::text AS loc,
                               ST_SetSRID(ST_MakePoint(:lon::float8, :lat::float8), 4326) AS vicino)
             SELECT s.id, s.istat, s.nome, s.dug, s.cap, s.n_civici, s.frazione,
                    CASE WHEN p.loc IS NULL OR s.frazione IS NULL THEN NULL
                         ELSE greatest(similarity(geocoder.normalizza(s.frazione), p.loc),
                                       strict_word_similarity(geocoder.normalizza(s.frazione), p.loc)) END AS sim_frazione,
                    ST_X(s.centroide) AS lon, ST_Y(s.centroide) AS lat,
                    -- "Garibaldi" per "Giuseppe Garibaldi" (e viceversa) vale quasi quanto il nome esatto.
                    greatest(similarity(s.denominazione, p.den),
                             0.9 * strict_word_similarity(p.den, s.denominazione),
                             0.9 * strict_word_similarity(s.denominazione, p.den),
                             -- Refusi nei nomi corti ("Via Rome"), solo dentro i comuni scelti.
                             CASE WHEN p.istat IS NOT NULL
                                  THEN 1.0 - levenshtein(left(s.denominazione, 255), left(p.den, 255))::float8
                                             / greatest(length(s.denominazione), length(p.den), 1)
                                  ELSE 0 END) AS sim_den,
                    similarity(s.nome_norm, p.via) AS sim_nome,
                    CASE WHEN p.vicino IS NULL THEN NULL
                         ELSE ST_Distance(s.centroide::geography, p.vicino::geography) END AS dist_localita,
                    k.nome AS comune, k.provincia
             FROM geocoder.strade s
             CROSS JOIN p
             LEFT JOIN geocoder.comuni k ON k.istat = s.istat
             WHERE (s.denominazione % p.den OR s.denominazione = p.den
                    OR p.den <<% s.denominazione OR s.denominazione <<% p.den
                    OR (p.istat IS NOT NULL
                        AND levenshtein(left(s.denominazione, 255), left(p.den, 255)) <= greatest(1, length(p.den) / 5)))
               AND (p.istat IS NULL OR s.istat = ANY (p.istat))
             ORDER BY sim_den DESC, sim_nome DESC, s.n_civici DESC
             LIMIT 50'
        );
        $st->execute([
            'den' => $n['denominazione'], 'via' => $n['via'],
            'istat' => $istat === null ? null : '{' . implode(',', array_map(static fn ($i) => preg_replace('/\D/', '', (string) $i), $istat)) . '}',
            'lon' => $vicino['lon'] ?? null, 'lat' => $vicino['lat'] ?? null, 'loc' => $localita,
        ]);
        $strade = [];
        foreach ($st->fetchAll() as $s) {
            $p = 0.75 * (float) $s['sim_den'] + 0.25 * (float) $s['sim_nome'];
            if ($n['dug'] !== null && $s['dug'] !== null && $n['dug'] !== $s['dug']) {
                $p *= 0.85;    // "Via Roma" non è "Piazza Roma"
            }
            if ($cap !== null && $s['cap'] !== null) {
                $p *= $cap === $s['cap'] ? 1.0 : 0.9;
            }
            if ($s['dist_localita'] !== null && (float) $s['dist_localita'] > 3000) {
                $p *= 0.9;     // lontana dalla frazione indicata
            }
            if ($s['sim_frazione'] !== null && (float) $s['sim_frazione'] < 0.6) {
                $p *= 0.92;    // i civici della via dicono un'altra frazione
            }
            if ($p >= $this->soglie['via']) {
                $s['punteggio'] = $p;
                $strade[] = $s;
            }
        }
        self::ordinaStabile($strade, static fn ($a, $b) => [$b['punteggio'], $b['n_civici']] <=> [$a['punteggio'], $a['n_civici']]);
        return array_slice($strade, 0, $limite);
    }

    /**
     * Cerca il civico sulla strada: esatto, stesso numero con altro esponente,
     * interpolato tra i civici vicini dello stesso lato, oppure il più vicino.
     *
     * @param array<string, mixed> $s
     * @param array<string, mixed> $civico
     * @return array<string, mixed>|null
     */
    private function risolviCivico(array $s, array $civico): ?array
    {
        if ($civico['snc']) {
            $st = $this->db->prepare('SELECT id, civico, cap, fonte, ST_X(geom) AS lon, ST_Y(geom) AS lat
                                      FROM geocoder.civici WHERE strada_id = :s AND snc LIMIT 1');
            $st->execute(['s' => $s['id']]);
            $c = $st->fetch();
            return $c ? $this->risultatoCivico($s, $c, 'civico', (float) $s['punteggio'], 'snc') : null;
        }
        if ($civico['numero'] === null) {
            return null;
        }
        $num = (int) $civico['numero'];
        $esp = (string) $civico['esponente'];

        $st = $this->db->prepare(
            'WITH p AS (SELECT :s::bigint AS s, :n::int AS n, :e::text AS e)
             SELECT c.id, c.civico, c.numero, c.esponente, c.cap, c.fonte, ST_X(c.geom) AS lon, ST_Y(c.geom) AS lat
             FROM geocoder.civici c, p
             WHERE c.strada_id = p.s AND c.numero = p.n
             ORDER BY (coalesce(c.esponente, \'\') = p.e) DESC, c.esponente NULLS FIRST
             LIMIT 1'
        );
        $st->execute(['s' => $s['id'], 'n' => $num, 'e' => $esp]);
        if ($c = $st->fetch()) {
            $esatto = (string) ($c['esponente'] ?? '') === $esp;
            return $this->risultatoCivico($s, $c, 'civico', (float) $s['punteggio'] * ($esatto ? 1.0 : 0.9), $civico['norm']);
        }

        // Interpolazione tra il civico precedente e il successivo dello stesso lato (stessa parità).
        $st = $this->db->prepare(
            'WITH p AS (SELECT :s::bigint AS s, :n::int AS n),
             prec AS (SELECT c.numero, c.geom FROM geocoder.civici c, p
                      WHERE c.strada_id = p.s AND c.numero < p.n AND (p.n - c.numero) % 2 = 0
                      ORDER BY c.numero DESC LIMIT 1),
             succ AS (SELECT c.numero, c.geom FROM geocoder.civici c, p
                      WHERE c.strada_id = p.s AND c.numero > p.n AND (c.numero - p.n) % 2 = 0
                      ORDER BY c.numero LIMIT 1)
             SELECT ST_X(g) AS lon, ST_Y(g) AS lat, prec_n, succ_n FROM (
                 SELECT ST_LineInterpolatePoint(ST_MakeLine(prec.geom, succ.geom),
                            (p.n - prec.numero)::float8 / (succ.numero - prec.numero)) AS g,
                        prec.numero AS prec_n, succ.numero AS succ_n
                 FROM prec, succ, p
                 WHERE NOT ST_Equals(prec.geom, succ.geom)
             ) i'
        );
        $st->execute(['s' => $s['id'], 'n' => $num]);
        if ($i = $st->fetch()) {
            $distanza = (int) $i['succ_n'] - (int) $i['prec_n'];
            $fattore = max(0.6, 0.85 - 0.005 * $distanza);
            $c = ['id' => null, 'civico' => $civico['norm'], 'cap' => $s['cap'], 'lon' => $i['lon'], 'lat' => $i['lat']];
            return $this->risultatoCivico($s, $c, 'civico_interpolato', (float) $s['punteggio'] * $fattore, $civico['norm']);
        }

        // Il civico più vicino come numero, preferendo lo stesso lato.
        $st = $this->db->prepare(
            'WITH p AS (SELECT :s::bigint AS s, :n::int AS n)
             SELECT c.id, c.civico, c.numero, c.cap, c.fonte, ST_X(c.geom) AS lon, ST_Y(c.geom) AS lat
             FROM geocoder.civici c, p
             WHERE c.strada_id = p.s AND c.numero IS NOT NULL
             ORDER BY ((c.numero - p.n) % 2 <> 0), abs(c.numero - p.n)
             LIMIT 1'
        );
        $st->execute(['s' => $s['id'], 'n' => $num]);
        if ($c = $st->fetch()) {
            $fattore = max(0.5, 0.75 - 0.01 * abs((int) $c['numero'] - $num));
            return $this->risultatoCivico($s, $c, 'civico_vicino', (float) $s['punteggio'] * $fattore, $c['civico']);
        }
        return null;
    }

    /**
     * @param list<string>|null $istat
     * @return list<array<string, mixed>>
     */
    private function cercaToponimi(string $testo, string $testoIntero, ?array $istat, int $limite): array
    {
        $st = $this->db->prepare(
            'WITH p AS (SELECT :t::text AS t, :ti::text AS ti, :istat::text[] AS istat)
             SELECT x.*, x.sim * (0.85 + 0.15 * x.importanza) AS punteggio FROM (
             SELECT x.id, x.nome, x.tipo, x.istat, x.fonte, x.importanza, ST_X(x.punto) AS lon, ST_Y(x.punto) AS lat,
                    greatest(similarity(x.nome_norm, p.t), similarity(x.nome_norm, p.ti),
                             0.9 * strict_word_similarity(p.t, x.nome_norm)) AS sim,
                    k.nome AS comune, k.provincia
             FROM geocoder.toponimi_igm x
             CROSS JOIN p
             LEFT JOIN geocoder.comuni k ON k.istat = x.istat
             WHERE (x.nome_norm % p.t OR x.nome_norm % p.ti OR x.nome_norm = p.t OR p.t <<% x.nome_norm)
               AND (p.istat IS NULL OR x.istat = ANY (p.istat))
             ) x
             -- A parità di nome vince il luogo più importante (capoluogo, centro abitato...).
             ORDER BY punteggio DESC
             LIMIT :lim'
        );
        $st->execute([
            't' => $testo, 'ti' => $testoIntero,
            'istat' => $istat === null ? null : '{' . implode(',', array_map(static fn ($i) => preg_replace('/\D/', '', (string) $i), $istat)) . '}',
            'lim' => $limite,
        ]);
        $out = [];
        foreach ($st->fetchAll() as $t) {
            if ((float) $t['sim'] < $this->soglie['toponimo']) {
                continue;
            }
            $out[] = [
                'livello' => 'toponimo',
                'confidenza' => (float) $t['punteggio'],
                'lon' => (float) $t['lon'], 'lat' => (float) $t['lat'],
                'etichetta' => self::etichetta($t['nome'], null, null, $t['comune'], $t['provincia']),
                'nome' => $t['nome'], 'tipo' => $t['tipo'],
                'comune' => $t['comune'], 'provincia' => $t['provincia'], 'istat' => $t['istat'],
                'fonte' => $t['fonte'], 'id' => (int) $t['id'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $s
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function risultatoCivico(array $s, array $c, string $livello, float $confidenza, ?string $civico): array
    {
        $civicoMostrato = $livello === 'civico' && $c['civico'] !== null ? $c['civico'] : $civico;
        $cap = $c['cap'] ?? $s['cap'];
        return [
            'livello' => $livello,
            'confidenza' => $confidenza,
            'lon' => (float) $c['lon'], 'lat' => (float) $c['lat'],
            'etichetta' => self::etichetta($s['nome'], $civicoMostrato, $cap, $s['comune'], $s['provincia']),
            'via' => $s['nome'], 'civico' => $civicoMostrato, 'cap' => $cap,
            'comune' => $s['comune'], 'provincia' => $s['provincia'], 'istat' => $s['istat'],
            'fonte' => $c['fonte'] ?? 'interpolazione', 'id' => $c['id'] === null ? null : (int) $c['id'],
            'strada_id' => (int) $s['id'],
        ];
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private function risultatoVia(array $s): array
    {
        return [
            'livello' => 'via',
            'confidenza' => (float) $s['punteggio'],
            'lon' => (float) $s['lon'], 'lat' => (float) $s['lat'],
            'etichetta' => self::etichetta($s['nome'], null, $s['cap'], $s['comune'], $s['provincia']),
            'via' => $s['nome'], 'cap' => $s['cap'],
            'comune' => $s['comune'], 'provincia' => $s['provincia'], 'istat' => $s['istat'],
            'fonte' => 'strade', 'strada_id' => (int) $s['id'],
        ];
    }

    /**
     * @param array<string, mixed> $k
     * @return array<string, mixed>
     */
    private function risultatoComune(array $k): array
    {
        return [
            'livello' => 'comune',
            'confidenza' => (float) $k['punteggio'],
            'lon' => (float) $k['lon'], 'lat' => (float) $k['lat'],
            'etichetta' => self::etichetta(null, null, null, $k['nome'], $k['provincia']),
            'comune' => $k['nome'], 'provincia' => $k['provincia'], 'istat' => $k['istat'],
            'fonte' => 'istat',
        ];
    }

    /**
     * usort() stabile: a parità resta l'ordine di partenza, come in PHP >= 8.0
     * (in PHP 7.4 l'ordine degli elementi uguali non è garantito).
     *
     * @param list<array<string, mixed>> $a
     * @param callable(mixed, mixed): int $confronto
     */
    private static function ordinaStabile(array &$a, callable $confronto): void
    {
        $i = 0;
        foreach ($a as &$v) {
            $v = [$i++, $v];
        }
        unset($v);
        usort($a, static fn ($x, $y) => $confronto($x[1], $y[1]) ?: $x[0] <=> $y[0]);
        $a = array_column($a, 1);
    }

    public static function etichetta(?string $via, ?string $civico, ?string $cap, ?string $comune, ?string $provincia): string
    {
        $prima = trim(($via ?? '') . ' ' . ($civico ?? ''));
        $dopo = trim(($cap ?? '') . ' ' . ($comune ?? '') . ($provincia ? " ($provincia)" : ''));
        return implode(', ', array_filter([$prima, $dopo], static fn ($x) => $x !== ''));
    }
}
