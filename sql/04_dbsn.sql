-- Località significative del DBSN (Database di Sintesi Nazionale IGM), tabella loc_sg:
--   loc_sg_top  toponimo
--   loc_sg_ty   tipo (codice gerarchico: 01 località, 0101 capoluogo, 010104 di comune...)
--   loc_sg_sgn  significatività (01 primo ordine ... 06 oltre il quinto, 91 non conosciuta)
-- Queste funzioni si usano nella mappatura dell'import dei toponimi
-- (vedi config/config.example.php).

CREATE TABLE IF NOT EXISTS geocoder.dbsn_loc_sg_tipi (
    codice      text PRIMARY KEY,
    descrizione text NOT NULL
);
INSERT INTO geocoder.dbsn_loc_sg_tipi (codice, descrizione) VALUES
    ('01', 'località'),
    ('0101', 'capoluogo'),
    ('010101', 'capitale di stato'),
    ('010102', 'capoluogo di regione'),
    ('010103', 'capoluogo di provincia'),
    ('010104', 'capoluogo di comune'),
    ('01010401', 'capoluogo di comune con almeno 50000 abitanti'),
    ('01010402', 'capoluogo di comune da 10000 a 49999 abitanti'),
    ('01010403', 'capoluogo di comune sotto i 10000 abitanti'),
    ('0102', 'centro abitato'),
    ('010201', 'centro abitato con più di 1000 abitanti'),
    ('010202', 'centro abitato con meno di 1000 abitanti'),
    ('0103', 'nucleo abitato'),
    ('0104', 'case sparse'),
    ('02', 'area geografica'),
    ('0201', 'monti, massicci, murge, vulcani, contrafforti'),
    ('0202', 'altopiani'),
    ('0203', 'valli, vallate'),
    ('0204', 'colli'),
    ('0205', 'passi, selle, valichi'),
    ('0206', 'creste'),
    ('0207', 'gole, crateri, doline, depressioni'),
    ('0208', 'promontori'),
    ('0209', 'scogli, secche'),
    ('0210', 'foci, bocche'),
    ('0211', 'coste, cale, golfi, stretti di mare'),
    ('0212', 'isole'),
    ('0213', 'aree umide'),
    ('021301', 'palude'),
    ('021302', 'torbiera'),
    ('021303', 'valle'),
    ('0301', 'antichità'),
    ('93', 'non definito'),
    ('95', 'altro')
ON CONFLICT (codice) DO UPDATE SET descrizione = EXCLUDED.descrizione;

-- Il codice può arrivare come testo o come numero senza lo zero iniziale
-- (10104 per '010104'): i codici del dominio hanno sempre un numero pari di cifre.
CREATE OR REPLACE FUNCTION geocoder.dbsn_codice(codice text) RETURNS text
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
    SELECT CASE WHEN length(c) % 2 = 1 THEN '0' || c ELSE c END
    FROM (SELECT regexp_replace(btrim(codice), '\.0+$', '') AS c) x
$$;

-- Descrizione del tipo di località; se il codice non è nel dominio, il codice stesso.
CREATE OR REPLACE FUNCTION geocoder.dbsn_tipo_localita(codice text) RETURNS text
LANGUAGE sql STABLE STRICT PARALLEL SAFE AS $$
    SELECT coalesce(
        (SELECT descrizione FROM geocoder.dbsn_loc_sg_tipi WHERE codice = geocoder.dbsn_codice($1)),
        geocoder.dbsn_codice($1))
$$;

-- Importanza (0..1) di una località, usata per ordinare i toponimi omonimi:
-- conta la significatività e, a parità, i luoghi abitati vincono sulle aree
-- geografiche (per un indirizzo "Pieve" è più probabilmente una frazione che un colle).
CREATE OR REPLACE FUNCTION geocoder.dbsn_importanza(tipo text, significativita text) RETURNS real
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
    SELECT (
        CASE geocoder.dbsn_codice(significativita)
            WHEN '01' THEN 1.0 WHEN '02' THEN 0.85 WHEN '03' THEN 0.7
            WHEN '04' THEN 0.6 WHEN '05' THEN 0.5 WHEN '06' THEN 0.4
            ELSE 0.5
        END
        *
        CASE
            WHEN geocoder.dbsn_codice(tipo) LIKE '0101%' THEN 1.0    -- capoluoghi
            WHEN geocoder.dbsn_codice(tipo) LIKE '0102%' THEN 0.95   -- centri abitati
            WHEN geocoder.dbsn_codice(tipo) LIKE '0103%' THEN 0.9    -- nuclei
            WHEN geocoder.dbsn_codice(tipo) LIKE '01%'   THEN 0.85   -- case sparse, località
            WHEN geocoder.dbsn_codice(tipo) LIKE '02%'   THEN 0.7    -- aree geografiche
            ELSE 0.6
        END
    )::real
$$;
