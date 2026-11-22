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

    /** @param list<string> $colonne */
    public function __construct(
        private readonly PDO $db,
        private readonly string $tabella,
        private readonly array $colonne,
        private readonly int $blocco = 10000,
    ) {
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
