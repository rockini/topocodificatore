<?php

declare(strict_types=1);

namespace Topocodificatore;

use InvalidArgumentException;
use RuntimeException;

/**
 * Legge un CSV, geocodifica l'indirizzo di ogni riga e scrive lo stesso CSV con
 * tre colonne in più: confidenza, latitudine e longitudine del miglior candidato.
 *
 * L'indirizzo può stare in una colonna sola o in più colonne (via, civico, comune...),
 * che vengono unite con ", ". Le colonne si indicano per nome (senza distinguere
 * maiuscole e minuscole) o per posizione, contando da 1.
 * Senza colonne indicate si usa la prima colonna di nome indirizzo, address o via.
 * Se un indirizzo è vuoto o non viene trovato, le tre colonne restano vuote.
 */
final class GeocodificaCsv
{
    /** Nomi di colonna usati quando la colonna dell'indirizzo non è indicata. */
    private const COLONNE_NOTE = ['indirizzo', 'address', 'via'];

    /** @var callable(string): ?array<string, mixed> */
    private $geocoder;

    /** @var list<string> */
    private array $colonne;

    private ?string $separatore;

    private bool $intestazione;

    /** @var list<string> */
    private array $nuoveColonne;

    /** @var callable(string): void */
    private $avviso;

    /** @var array<string, ?array<string, mixed>> indirizzi già geocodificati */
    private array $cache = [];

    /**
     * @param callable(string): ?array<string, mixed> $geocoder dato un indirizzo restituisce il miglior
     *        candidato (con 'confidenza', 'lat', 'lon') oppure null se non trova nulla
     * @param list<string> $colonne nomi o posizioni (da 1) delle colonne con l'indirizzo; vuota per
     *        cercarla tra le colonne dal nome noto
     * @param string|null $separatore separatore dei campi; null per riconoscerlo dalla prima riga
     * @param list<string> $nuoveColonne intestazioni delle tre colonne aggiunte
     * @param callable(string): void|null $avviso riceve gli avvisi (righe con errori, avanzamento)
     */
    public function __construct(
        callable $geocoder,
        array $colonne,
        ?string $separatore = null,
        bool $intestazione = true,
        array $nuoveColonne = ['confidenza', 'latitudine', 'longitudine'],
        ?callable $avviso = null
    ) {
        if ($colonne === [] && !$intestazione) {
            throw new InvalidArgumentException('Senza intestazione indica la colonna dell\'indirizzo per posizione');
        }
        if ($separatore !== null && strlen($separatore) !== 1) {
            throw new InvalidArgumentException('Il separatore deve essere un solo carattere');
        }
        if (count($nuoveColonne) !== 3) {
            throw new InvalidArgumentException('Servono tre nomi per le colonne aggiunte');
        }
        $this->geocoder = $geocoder;
        $this->colonne = array_values($colonne);
        $this->separatore = $separatore;
        $this->intestazione = $intestazione;
        $this->nuoveColonne = array_values($nuoveColonne);
        $this->avviso = $avviso ?? static function (string $m): void {
        };
    }

    /**
     * Elabora il CSV da $ingresso a $uscita (stream aperti) e restituisce il conteggio
     * delle righe: ['righe' => ..., 'trovati' => ..., 'non_trovati' => ..., 'vuoti' => ...].
     *
     * @param resource $ingresso
     * @param resource $uscita
     * @return array<string, int>
     */
    public function elabora($ingresso, $uscita): array
    {
        $prima = fgets($ingresso);
        if ($prima === false) {
            throw new RuntimeException('Il file CSV è vuoto');
        }
        $bom = '';
        if (strncmp($prima, "\xEF\xBB\xBF", 3) === 0) {
            $bom = "\xEF\xBB\xBF";
            $prima = substr($prima, 3);
        }
        $sep = $this->separatore ?? self::riconosciSeparatore($prima);

        // La prima riga è già stata letta: la rimettiamo davanti al resto del file.
        $flusso = fopen('php://temp', 'w+b');
        fwrite($flusso, $prima);
        stream_copy_to_stream($ingresso, $flusso);
        rewind($flusso);

        $conteggio = ['righe' => 0, 'trovati' => 0, 'non_trovati' => 0, 'vuoti' => 0];
        $indici = null;
        $numero = 0;
        fwrite($uscita, $bom);
        while (($riga = fgetcsv($flusso, 0, $sep, '"', '')) !== false) {
            $numero++;
            if ($riga === [null]) {
                continue;   // riga vuota
            }
            /** @var list<string> $riga */
            if ($indici === null) {
                $indici = $this->indici($this->intestazione ? $riga : []);
                if ($this->intestazione) {
                    fputcsv($uscita, array_merge($riga, $this->nuoveColonne), $sep, '"', '');
                    continue;
                }
            }

            $parti = [];
            foreach ($indici as $i) {
                $v = trim((string) ($riga[$i] ?? ''));
                if ($v !== '') {
                    $parti[] = $v;
                }
            }
            $indirizzo = implode(', ', $parti);
            $conteggio['righe']++;

            $aggiunte = ['', '', ''];
            if ($indirizzo === '') {
                $conteggio['vuoti']++;
            } else {
                $c = $this->geocodifica($indirizzo, $numero);
                if ($c === null) {
                    $conteggio['non_trovati']++;
                } else {
                    $conteggio['trovati']++;
                    $aggiunte = [
                        self::numero((float) $c['confidenza'], 3),
                        self::numero((float) $c['lat'], 7),
                        self::numero((float) $c['lon'], 7),
                    ];
                }
            }
            fputcsv($uscita, array_merge($riga, $aggiunte), $sep, '"', '');

            if ($conteggio['righe'] % 500 === 0) {
                ($this->avviso)("{$conteggio['righe']} righe elaborate");
            }
        }
        fclose($flusso);
        return $conteggio;
    }

    /** @return ?array<string, mixed> */
    private function geocodifica(string $indirizzo, int $numero): ?array
    {
        $chiave = mb_strtolower($indirizzo);
        if (!array_key_exists($chiave, $this->cache)) {
            try {
                $this->cache[$chiave] = ($this->geocoder)($indirizzo);
            } catch (\Throwable $e) {
                // Un errore su una riga non ferma il file; non lo mettiamo in cache.
                ($this->avviso)("Riga $numero ($indirizzo): " . $e->getMessage());
                return null;
            }
        }
        return $this->cache[$chiave];
    }

    /**
     * Posizioni (da 0) delle colonne dell'indirizzo.
     *
     * @param list<string> $intestazione vuota se il file non ha intestazione
     * @return list<int>
     */
    private function indici(array $intestazione): array
    {
        $nomi = [];
        foreach ($intestazione as $i => $nome) {
            $nomi[mb_strtolower(trim((string) $nome))] = $i;
        }
        if ($this->colonne === []) {
            foreach (self::COLONNE_NOTE as $nota) {
                if (isset($nomi[$nota])) {
                    return [$nomi[$nota]];
                }
            }
            throw new RuntimeException('Non trovo la colonna dell\'indirizzo (' . implode(', ', self::COLONNE_NOTE)
                . '): indicala con --colonna; colonne del file: ' . implode(', ', $intestazione));
        }
        $indici = [];
        foreach ($this->colonne as $colonna) {
            $chiave = mb_strtolower(trim($colonna));
            if (isset($nomi[$chiave])) {
                $indici[] = $nomi[$chiave];
            } elseif (ctype_digit($chiave) && (int) $chiave >= 1) {
                $indici[] = (int) $chiave - 1;
            } else {
                throw new RuntimeException("Colonna \"$colonna\" non trovata"
                    . ($intestazione !== [] ? '; colonne del file: ' . implode(', ', $intestazione) : ''));
            }
        }
        return $indici;
    }

    /** Il separatore più frequente nella prima riga tra virgola, punto e virgola, tabulazione e barra verticale. */
    public static function riconosciSeparatore(string $riga): string
    {
        $migliore = ',';
        $max = 0;
        foreach ([',', ';', "\t", '|'] as $s) {
            $n = count(str_getcsv($riga, $s, '"', '')) - 1;
            if ($n > $max) {
                [$migliore, $max] = [$s, $n];
            }
        }
        return $migliore;
    }

    private static function numero(float $valore, int $decimali): string
    {
        // Sempre con il punto decimale, qualunque sia la localizzazione.
        $s = rtrim(rtrim(number_format($valore, $decimali, '.', ''), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }
}
