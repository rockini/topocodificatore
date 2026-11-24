<?php

declare(strict_types=1);

namespace Topocodificatore;

use PDO;

/**
 * Geocoding inverso: dato un punto, i civici più vicini (KNN PostGIS con
 * l'operatore <-> sull'indice GiST). Se non ci sono civici entro il raggio,
 * il toponimo IGM più vicino e infine il comune che contiene il punto.
 */
final class GeocoderInverso
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return list<array<string, mixed>> */
    public function cerca(float $lat, float $lon, float $raggio = 100.0, int $limite = 1, float $raggioToponimi = 2000.0): array
    {
        // Il KNN in gradi dà i candidati in ordine approssimato; la distanza vera
        // (in metri, sul geoide) li riordina.
        $st = $this->db->prepare(
            'WITH p AS (SELECT ST_SetSRID(ST_MakePoint(:lon::float8, :lat::float8), 4326) AS g, :r::float8 AS r)
             SELECT c.id, c.civico, c.cap, c.fonte, ST_X(c.geom) AS lon, ST_Y(c.geom) AS lat, c.dist,
                    coalesce(s.nome, c.via) AS via, s.id AS strada_id, c.istat, k.nome AS comune, k.provincia
             FROM (
                 SELECT c.*, ST_Distance(c.geom::geography, p.g::geography) AS dist
                 FROM (SELECT * FROM geocoder.civici ORDER BY geom <-> (SELECT g FROM p) LIMIT 50) c, p
             ) c
             CROSS JOIN p
             LEFT JOIN geocoder.strade s ON s.id = c.strada_id
             LEFT JOIN geocoder.comuni k ON k.istat = c.istat
             WHERE c.dist <= p.r
             ORDER BY c.dist
             LIMIT :lim'
        );
        $st->execute(['lon' => $lon, 'lat' => $lat, 'r' => $raggio, 'lim' => $limite]);
        $risultati = [];
        foreach ($st->fetchAll() as $c) {
            $risultati[] = [
                'livello' => 'civico',
                'distanza_m' => round((float) $c['dist'], 1),
                'lon' => (float) $c['lon'], 'lat' => (float) $c['lat'],
                'etichetta' => Geocoder::etichetta($c['via'], $c['civico'], $c['cap'], $c['comune'], $c['provincia']),
                'via' => $c['via'], 'civico' => $c['civico'], 'cap' => $c['cap'],
                'comune' => $c['comune'], 'provincia' => $c['provincia'], 'istat' => $c['istat'],
                'fonte' => $c['fonte'], 'id' => (int) $c['id'],
                'strada_id' => $c['strada_id'] === null ? null : (int) $c['strada_id'],
            ];
        }
        if ($risultati !== []) {
            return $risultati;
        }

        $st = $this->db->prepare(
            'WITH p AS (SELECT ST_SetSRID(ST_MakePoint(:lon::float8, :lat::float8), 4326) AS g, :r::float8 AS r)
             SELECT t.id, t.nome, t.tipo, t.istat, t.fonte, ST_X(t.punto) AS lon, ST_Y(t.punto) AS lat, t.dist,
                    k.nome AS comune, k.provincia
             FROM (
                 SELECT t.*, ST_Distance(t.punto::geography, p.g::geography) AS dist
                 FROM (SELECT * FROM geocoder.toponimi_igm ORDER BY punto <-> (SELECT g FROM p) LIMIT 20) t, p
             ) t
             CROSS JOIN p
             LEFT JOIN geocoder.comuni k ON k.istat = t.istat
             WHERE t.dist <= p.r
             ORDER BY t.dist
             LIMIT :lim'
        );
        $st->execute(['lon' => $lon, 'lat' => $lat, 'r' => $raggioToponimi, 'lim' => $limite]);
        foreach ($st->fetchAll() as $t) {
            $risultati[] = [
                'livello' => 'toponimo',
                'distanza_m' => round((float) $t['dist'], 1),
                'lon' => (float) $t['lon'], 'lat' => (float) $t['lat'],
                'etichetta' => Geocoder::etichetta($t['nome'], null, null, $t['comune'], $t['provincia']),
                'nome' => $t['nome'], 'tipo' => $t['tipo'],
                'comune' => $t['comune'], 'provincia' => $t['provincia'], 'istat' => $t['istat'],
                'fonte' => $t['fonte'], 'id' => (int) $t['id'],
            ];
        }
        if ($risultati !== []) {
            return $risultati;
        }

        $st = $this->db->prepare(
            'SELECT istat, nome, provincia, ST_X(centroide) AS lon, ST_Y(centroide) AS lat
             FROM geocoder.comuni
             WHERE ST_Intersects(geom, ST_SetSRID(ST_MakePoint(:lon::float8, :lat::float8), 4326))
             LIMIT 1'
        );
        $st->execute(['lon' => $lon, 'lat' => $lat]);
        if ($k = $st->fetch()) {
            $risultati[] = [
                'livello' => 'comune',
                'distanza_m' => 0.0,
                'lon' => (float) $k['lon'], 'lat' => (float) $k['lat'],
                'etichetta' => Geocoder::etichetta(null, null, null, $k['nome'], $k['provincia']),
                'comune' => $k['nome'], 'provincia' => $k['provincia'], 'istat' => $k['istat'],
                'fonte' => 'istat',
            ];
        }
        return $risultati;
    }
}
