<?php

declare(strict_types=1);

namespace Topocodificatore;

/**
 * Scompone un indirizzo italiano scritto a testo libero in via, civico,
 * località, CAP, comune e provincia.
 *
 * Qui si decide solo quale pezzo di testo è cosa; la normalizzazione dei nomi
 * e l'interpretazione del civico le fa il database (geocoder.normalizza e
 * geocoder.civico), le stesse funzioni usate nell'import.
 *
 * Esempi:
 *   "Via Roma 12/A, 20121 Milano (MI)"     -> via "Via Roma", civico "12/A", cap, comune "Milano", provincia "MI"
 *   "via XX Settembre 5 Genova"             -> via "via XX Settembre", civico "5", comune "Genova"
 *   "Corso 22 Marzo, Milano"                -> via "Corso 22 Marzo", comune "Milano" (22 è parte del nome)
 *   "Piazza Duomo, 1, Milano"               -> via "Piazza Duomo", civico "1", comune "Milano"
 *   "Via Roma 3, fraz. Pieve, Cortina"      -> località "fraz. Pieve", comune "Cortina"
 */
final class AnalizzatoreIndirizzo
{
    /** Sigle automobilistiche delle province italiane. */
    public const PROVINCE = [
        'AG', 'AL', 'AN', 'AO', 'AP', 'AQ', 'AR', 'AT', 'AV', 'BA', 'BG', 'BI', 'BL', 'BN', 'BO', 'BR', 'BS', 'BT', 'BZ',
        'CA', 'CB', 'CE', 'CH', 'CL', 'CN', 'CO', 'CR', 'CS', 'CT', 'CZ', 'EN', 'FC', 'FE', 'FG', 'FI', 'FM', 'FR', 'GE',
        'GO', 'GR', 'IM', 'IS', 'KR', 'LC', 'LE', 'LI', 'LO', 'LT', 'LU', 'MB', 'MC', 'ME', 'MI', 'MN', 'MO', 'MS', 'MT',
        'NA', 'NO', 'NU', 'OR', 'PA', 'PC', 'PD', 'PE', 'PG', 'PI', 'PN', 'PO', 'PR', 'PT', 'PU', 'PV', 'PZ', 'RA', 'RC',
        'RE', 'RG', 'RI', 'RM', 'RN', 'RO', 'SA', 'SI', 'SO', 'SP', 'SR', 'SS', 'SU', 'SV', 'TA', 'TE', 'TN', 'TO', 'TP',
        'TR', 'TS', 'TV', 'UD', 'VA', 'VB', 'VC', 'VE', 'VI', 'VR', 'VT', 'VV',
    ];

    /** Parole che, dopo un numero, indicano che il numero fa parte del nome della via. */
    private const DOPO_NUMERO_NOME = [
        'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto',
        'settembre', 'ottobre', 'novembre', 'dicembre', 'martiri', 'giornate', 'febbraio',
    ];

    /** Parole che, prima di un numero, indicano un numero di strada e non un civico. */
    private const PRIMA_NUMERO_NOME = [
        'ss', 'sp', 'sr', 'sc', 'statale', 'provinciale', 'regionale', 'comunale', 'km', 'n', 'nr',
    ];

    private const CIVICO = '(?:\d{1,5}(?:\s*[\/\-]\s*(?:bis|ter|quater|[a-z]|\d{1,3})\b|\s*(?:bis|ter|quater|rosso)\b|[a-z]\b|\s+[a-z](?=\s*(?:,|$)))?|s\.?\s*n\.?\s*c\.?|snc)';

    public function analizza(string $testo): Componenti
    {
        $c = new Componenti($testo);
        $t = trim((string) preg_replace('/\s+/u', ' ', $testo));

        // CAP: l'ultimo gruppo di 5 cifre.
        if (preg_match_all('/(?<![\d\/])\d{5}(?![\d\/])/', $t, $m, PREG_OFFSET_CAPTURE)) {
            [$cap, $pos] = end($m[0]);
            $c->cap = $cap;
            $t = substr($t, 0, $pos) . ' ' . substr($t, $pos + 5);
        }

        // Provincia: "(MI)" ovunque, oppure una sigla maiuscola in fondo ("Milano MI", "Milano, MI").
        if (preg_match('/\(\s*([A-Za-z]{2})\s*\)/', $t, $m, PREG_OFFSET_CAPTURE)
            && in_array(strtoupper($m[1][0]), self::PROVINCE, true)) {
            $c->provincia = strtoupper($m[1][0]);
            $t = substr($t, 0, $m[0][1]) . ' ' . substr($t, $m[0][1] + strlen($m[0][0]));
        } elseif (preg_match('/(?:,|\s)\s*([A-Z]{2})\s*$/', $t, $m, PREG_OFFSET_CAPTURE)
            && in_array($m[1][0], self::PROVINCE, true)
            && trim(substr($t, 0, $m[0][1])) !== '') {
            $c->provincia = $m[1][0];
            $t = substr($t, 0, $m[0][1]);
        }

        $segmenti = array_values(array_filter(
            array_map(static fn ($s) => trim($s, " \t-"), explode(',', $t)),
            static fn ($s) => $s !== '',
        ));

        // Civico in un segmento a sé: "Piazza Duomo, 1, Milano".
        foreach ($segmenti as $i => $s) {
            if ($i > 0 && preg_match('/^(?:n\.?\s*|nr\.?\s*|civico\s+)?(' . self::CIVICO . ')$/iu', $s, $m)) {
                $c->civico = $m[1];
                array_splice($segmenti, $i, 1);
                break;
            }
        }

        // Civico dentro un segmento: "Via Roma 12 Milano" -> "Via Roma" | "12" | "Milano".
        if ($c->civico === null) {
            foreach ($segmenti as $i => $s) {
                $trovato = $this->civicoNelSegmento($s);
                if ($trovato !== null) {
                    [$prima, $civico, $dopo] = $trovato;
                    $c->civico = $civico;
                    $nuovi = [$prima];
                    if ($dopo !== '') {
                        $nuovi[] = $dopo;
                    }
                    array_splice($segmenti, $i, 1, $nuovi);
                    break;
                }
            }
        }

        if ($segmenti === []) {
            return $c;
        }
        if (count($segmenti) === 1) {
            // Un solo pezzo: è la via se c'è un civico; altrimenti non si sa
            // (via, toponimo o comune) e decide il geocoder.
            $c->via = $segmenti[0];
            return $c;
        }
        $c->via = array_shift($segmenti);
        $c->comune = array_pop($segmenti);
        if ($segmenti !== []) {
            $c->localita = implode(', ', $segmenti);
        }
        return $c;
    }

    /** @return array{0: string, 1: string, 2: string}|null prima, civico, dopo */
    private function civicoNelSegmento(string $s): ?array
    {
        if (!preg_match_all('/(?<![\p{L}\d\/])(?:n\.?\s*|nr\.?\s*|civ\.?\s*)?(' . self::CIVICO . ')(?![\p{L}\d])/iu', $s, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return null;
        }
        // Si prende l'ultima occorrenza plausibile: i numeri all'inizio
        // ("Via 20 Settembre") o seguiti da un mese fanno parte del nome.
        foreach (array_reverse($m) as $occ) {
            [$tutto, $pos] = $occ[0];
            $civico = $occ[1][0];
            $prima = trim(substr($s, 0, $pos));
            $dopo = trim(substr($s, $pos + strlen($tutto)));
            if ($prima === '') {
                continue;
            }
            $parolePrima = preg_split('/[\s.]+/', mb_strtolower($prima), -1, PREG_SPLIT_NO_EMPTY);
            $ultimaPrima = end($parolePrima) ?: '';
            $primaDopo = mb_strtolower(preg_split('/\s+/', $dopo)[0] ?? '');
            if (in_array($primaDopo, self::DOPO_NUMERO_NOME, true)) {
                continue;
            }
            if (in_array($ultimaPrima, self::PRIMA_NUMERO_NOME, true) && !preg_match('/^(n|nr)\b/i', $tutto)) {
                continue;
            }
            // Una via fatta solo di DUG ("Via 12") non ha senso: il numero è il nome.
            if (count($parolePrima) === 1 && preg_match('/^(via|viale|piazza|corso|largo|vicolo|strada|v)$/', $ultimaPrima)) {
                continue;
            }
            return [$prima, $civico, $dopo];
        }
        return null;
    }
}
