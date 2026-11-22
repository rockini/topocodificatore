<?php

declare(strict_types=1);

// Punto di ingresso HTTP. Con il server integrato di PHP:
//   php -S localhost:8080 -t public public/index.php
// Con Apache o nginx, instrada tutte le richieste a questo file.

require dirname(__DIR__) . '/src/bootstrap.php';

use Topocodificatore\Api;
use Topocodificatore\Configurazione;

header('Content-Type: application/geo+json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

$percorso = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// Se l'API è pubblicata in una sottocartella, togli il prefisso.
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
if ($base !== '' && str_starts_with($percorso, $base . '/')) {
    $percorso = substr($percorso, strlen($base));
}
$percorso = preg_replace('#^/index\.php#', '', $percorso) ?: '/';

try {
    $api = new Api(Configurazione::carica()->db());
    [$stato, $corpo] = $api->gestisci($_SERVER['REQUEST_METHOD'] ?? 'GET', $percorso, $_GET);
} catch (Throwable $e) {
    error_log('[topocodificatore] ' . $e);
    [$stato, $corpo] = [500, ['errore' => 'Errore interno']];
}

if (isset($corpo['errore'])) {
    header('Content-Type: application/json; charset=utf-8');
}
http_response_code($stato);
if ($stato !== 204) {
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}
