<?php

declare(strict_types=1);

namespace Topocodificatore;

/** Converte i risultati del geocoder in una FeatureCollection GeoJSON (RFC 7946). */
final class GeoJson
{
    /**
     * @param list<array<string, mixed>> $risultati
     * @param array<string, mixed> $query membro aggiuntivo con la richiesta interpretata
     * @return array<string, mixed>
     */
    public static function collezione(array $risultati, array $query = []): array
    {
        $features = [];
        foreach ($risultati as $r) {
            $lon = $r['lon'];
            $lat = $r['lat'];
            unset($r['lon'], $r['lat']);
            $features[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [round($lon, 7), round($lat, 7)]],
                'properties' => array_filter($r, static fn ($v) => $v !== null),
            ];
        }
        $out = ['type' => 'FeatureCollection', 'features' => $features];
        if ($query !== []) {
            $out['query'] = $query;
        }
        return $out;
    }
}
