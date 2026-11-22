<?php

declare(strict_types=1);

namespace Topocodificatore;

use PDO;
use RuntimeException;

/**
 * Carica config/config.php (o il file indicato da GEOCODER_CONFIG) e apre la
 * connessione al database. Le variabili d'ambiente GEOCODER_DSN,
 * GEOCODER_DB_UTENTE e GEOCODER_DB_PASSWORD hanno la precedenza sul file.
 */
final class Configurazione
{
    /** @param array<string, mixed> $valori */
    public function __construct(public readonly array $valori)
    {
    }

    public static function carica(?string $file = null): self
    {
        $file ??= getenv('GEOCODER_CONFIG') ?: dirname(__DIR__) . '/config/config.php';
        $valori = [];
        if (is_file($file)) {
            $valori = require $file;
            if (!is_array($valori)) {
                throw new RuntimeException("Il file di configurazione $file deve restituire un array");
            }
        }
        foreach (['dsn' => 'GEOCODER_DSN', 'utente' => 'GEOCODER_DB_UTENTE', 'password' => 'GEOCODER_DB_PASSWORD'] as $chiave => $env) {
            $v = getenv($env);
            if ($v !== false) {
                $valori['db'][$chiave] = $v;
            }
        }
        if (empty($valori['db']['dsn'])) {
            throw new RuntimeException('Manca la connessione al database: crea config/config.php '
                . '(vedi config/config.example.php) oppure imposta GEOCODER_DSN');
        }
        return new self($valori);
    }

    public function db(): PDO
    {
        $db = $this->valori['db'];
        return self::connetti($db['dsn'], $db['utente'] ?? null, $db['password'] ?? null);
    }

    public static function connetti(string $dsn, ?string $utente, ?string $password): PDO
    {
        return new PDO($dsn, $utente, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /** @return array<string, mixed> */
    public function sezione(string $nome): array
    {
        return $this->valori[$nome] ?? [];
    }
}
