<?php

declare(strict_types=1);

namespace Topocodificatore;

use PDO;

/**
 * Geocodifica un indirizzo italiano scritto a testo libero.
 *
 * Restituisce le posizioni candidate, dalla più probabile, ognuna con:
 *   - lat, lon:     coordinate WGS84;
 *   - probabilita:  peso del candidato rispetto agli altri restituiti (le probabilità sommano a 1);
 *   - confidenza:   quanto il candidato corrisponde all'indirizzo (0..1), indipendente dagli altri;
 *   - livello:      precisione del punto: civico, civico_interpolato, civico_vicino, via, toponimo, comune;
 *   - etichetta:    l'indirizzo trovato, es. "Via Roma 12, 50123 Firenze (FI)";
 *   - via, civico, cap, comune, provincia, istat, nome e tipo (toponimi), fonte.
 * Una lista vuota vuol dire che non è stato trovato nulla.
 *
 * Esempio:
 *   require 'src/bootstrap.php';
 *   $candidati = \Topocodificatore\geocodifica('Via Roma 12, Firenze');
 *   [$lat, $lon] = [$candidati[0]['lat'], $candidati[0]['lon']];
 *
 * @param PDO|null $db connessione al database del geocoder; se manca si usa config/config.php
 * @return list<array<string, mixed>>
 */
function geocodifica(string $indirizzo, int $massimo = 5, ?PDO $db = null): array
{
    static $connessione = null;
    $db ??= $connessione ??= Configurazione::carica()->db();

    $componenti = (new AnalizzatoreIndirizzo())->analizza($indirizzo);
    if ($componenti->comeArray() === []) {
        return [];
    }
    return (new Geocoder($db))->cerca($componenti, max(1, $massimo));
}
