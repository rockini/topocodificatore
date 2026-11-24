<?php

declare(strict_types=1);

namespace Topocodificatore\Import;

use PDO;

/** Scrive righe in una tabella PostgreSQL con COPY, a blocchi. */
final class ScrittoreCopy
{
    /** @var list<string> */
    private array $buffer = [];
    private int $righe = 0;

    private PDO $db;
    private string $tabella;
    /** @var list<string> */
    private array $colonne;
    private int $blocco;

    /** @param list<string> $colonne */
    public function __construct(PDO $db, string $tabella, array $colonne, int $blocco = 10000)
    {
        $this->db = $db;
        $this->tabella = $tabella;
        $this->colonne = $colonne;
        $this->blocco = $blocco;
    }

    /** @param array<string, ?string> $riga valori per nome di colonna; quelli mancanti sono NULL */
    public function aggiungi(array $riga): void
    {
        $campi = [];
        foreach ($this->colonne as $c) {
            $v = $riga[$c] ?? null;
            $campi[] = $v === null ? '\\N' : strtr((string) $v, ["\\" => "\\\\", "\t" => "\\t", "\n" => "\\n", "\r" => "\\r"]);
        }
        $this->buffer[] = implode("\t", $campi);
        if (count($this->buffer) >= $this->blocco) {
            $this->svuota();
        }
    }

    public function svuota(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $this->db->pgsqlCopyFromArray($this->tabella, $this->buffer, "\t", "\\\\N", implode(',', $this->colonne));
        $this->righe += count($this->buffer);
        $this->buffer = [];
    }

    public function righe(): int
    {
        return $this->righe + count($this->buffer);
    }
}
