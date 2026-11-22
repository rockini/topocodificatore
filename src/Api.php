<?php

declare(strict_types=1);

namespace Topocodificatore;

use InvalidArgumentException;
use PDO;

/**
 * Le rotte HTTP dell'API.
 *
 *   GET /search?q=Via Roma 12, Milano
 *   GET /search?via=Via Roma&civico=12&comune=Milano&cap=20121&provincia=MI
 *   GET /reverse?lat=45.46&lon=9.19[&raggio=100][&limit=1]
 *   GET /health
 */
final class Api
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<string, mixed> $parametri
     * @return array{0: int, 1: array<string, mixed>} stato HTTP e corpo
     */
    public function gestisci(string $metodo, string $percorso, array $parametri): array
    {
        if ($metodo === 'OPTIONS') {
            return [204, []];
        }
        if ($metodo !== 'GET') {
            return [405, ['errore' => 'Metodo non consentito: usa GET']];
        }
        try {
            return match (rtrim($percorso, '/') ?: '/') {
                '/search' => [200, $this->search($parametri)],
                '/reverse' => [200, $this->reverse($parametri)],
                '/health' => [200, $this->health()],
                default => [404, ['errore' => "Percorso sconosciuto: $percorso", 'percorsi' => ['/search', '/reverse', '/health']]],
            };
        } catch (InvalidArgumentException $e) {
            return [400, ['errore' => $e->getMessage()]];
        }
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function search(array $p): array
    {
        $testo = self::stringa($p, 'q');
        $c = $testo !== null ? (new AnalizzatoreIndirizzo())->analizza($testo) : new Componenti();
        // I parametri strutturati prevalgono su ciò che si ricava dal testo libero.
        foreach (['via', 'civico', 'localita', 'cap', 'comune', 'provincia'] as $campo) {
            $v = self::stringa($p, $campo);
            if ($v !== null) {
                $c->$campo = $v;
            }
        }
        if ($c->provincia !== null) {
            $c->provincia = strtoupper($c->provincia);
        }
        if ($c->comeArray() === []) {
            throw new InvalidArgumentException('Indica q (testo libero) oppure almeno uno tra via, comune, localita, cap');
        }
        $limite = self::intero($p, 'limit', 5, 1, 50);
        $risultati = (new Geocoder($this->db))->cerca($c, $limite);
        return GeoJson::collezione($risultati, ['testo' => $testo, 'componenti' => $c->comeArray()]);
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function reverse(array $p): array
    {
        $lat = self::decimale($p, 'lat', -90, 90);
        $lon = self::decimale($p, 'lon', -180, 180);
        $raggio = isset($p['raggio']) ? self::decimale($p, 'raggio', 1, 5000) : 100.0;
        $limite = self::intero($p, 'limit', 1, 1, 50);
        $risultati = (new GeocoderInverso($this->db))->cerca($lat, $lon, $raggio, $limite);
        return GeoJson::collezione($risultati, ['lat' => $lat, 'lon' => $lon, 'raggio' => $raggio]);
    }

    /** @return array<string, mixed> */
    private function health(): array
    {
        $conteggi = $this->db->query(
            'SELECT (SELECT count(*) FROM geocoder.comuni) AS comuni,
                    (SELECT count(*) FROM geocoder.strade) AS strade,
                    (SELECT count(*) FROM geocoder.civici) AS civici,
                    (SELECT count(*) FROM geocoder.toponimi_igm) AS toponimi_igm'
        )->fetch();
        return ['stato' => 'ok', 'righe' => array_map('intval', $conteggi)];
    }

    /** @param array<string, mixed> $p */
    private static function stringa(array $p, string $nome): ?string
    {
        $v = $p[$nome] ?? null;
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        if (mb_strlen($v) > 300) {
            throw new InvalidArgumentException("$nome è troppo lungo (massimo 300 caratteri)");
        }
        return $v === '' ? null : $v;
    }

    /** @param array<string, mixed> $p */
    private static function decimale(array $p, string $nome, float $min, float $max): float
    {
        $v = $p[$nome] ?? null;
        if (!is_string($v) || !is_numeric($v) || (float) $v < $min || (float) $v > $max) {
            throw new InvalidArgumentException("$nome deve essere un numero tra $min e $max");
        }
        return (float) $v;
    }

    /** @param array<string, mixed> $p */
    private static function intero(array $p, string $nome, int $predefinito, int $min, int $max): int
    {
        if (!isset($p[$nome])) {
            return $predefinito;
        }
        $v = filter_var($p[$nome], FILTER_VALIDATE_INT);
        if ($v === false || $v < $min || $v > $max) {
            throw new InvalidArgumentException("$nome deve essere un intero tra $min e $max");
        }
        return $v;
    }
}
