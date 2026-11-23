-- Funzioni di normalizzazione degli indirizzi italiani.
--
-- Queste funzioni sono l'unica implementazione della normalizzazione: le usano
-- sia gli script di import sia l'API PHP (che le chiama in SQL), così dati e
-- interrogazioni vengono normalizzati sempre nello stesso modo.
--
-- Sono dichiarate IMMUTABLE perché servono negli indici. Se le modifichi,
-- riesegui l'import (o almeno REINDEX e l'UPDATE delle colonne *_norm).

-- unaccent() non è IMMUTABLE; questo wrapper fissa il dizionario e lo schema
-- in cui è installata l'estensione, così la funzione si può usare negli indici.
DO $$
DECLARE
    sch text;
BEGIN
    SELECT n.nspname INTO sch
    FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace
    WHERE e.extname = 'unaccent';

    EXECUTE format($f$
        CREATE OR REPLACE FUNCTION geocoder.senza_accenti(text) RETURNS text
        LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
        AS $b$ SELECT %1$I.unaccent('%1$I.unaccent'::regdictionary, $1) $b$
    $f$, sch);
END
$$;

-- Numeri romani da I a XXXIX ("XX Settembre", "IV Novembre") in cifre arabe.
CREATE OR REPLACE FUNCTION geocoder.romano_a_intero(t text) RETURNS integer
LANGUAGE plpgsql IMMUTABLE STRICT PARALLEL SAFE AS $$
DECLARE
    valori constant jsonb := '{"i":1,"v":5,"x":10}';
    tot int := 0;
    prec int := 0;
    cur int;
    i int;
BEGIN
    IF t !~ '^x{0,3}(ix|iv|v?i{0,3})$' OR t = '' THEN
        RETURN NULL;
    END IF;
    FOR i IN REVERSE length(t)..1 LOOP
        cur := (valori ->> substr(t, i, 1))::int;
        IF cur < prec THEN
            tot := tot - cur;
        ELSE
            tot := tot + cur;
            prec := cur;
        END IF;
    END LOOP;
    RETURN tot;
END
$$;

-- Normalizza un nome (via, comune, toponimo):
--   minuscolo, senza accenti, senza punteggiatura, abbreviazioni espanse,
--   santi uniformati a "s", titoli rimossi, numeri romani convertiti.
-- Esempi:
--   'P.zza S. Maria Maggiore'   -> 'piazza s maria maggiore'
--   'V.le XX Settembre'          -> 'viale 20 settembre'
--   'Via Sant''Antonio'          -> 'via s antonio'
--   'Via Prof. Gaetano Salvemini'-> 'via gaetano salvemini'
CREATE OR REPLACE FUNCTION geocoder.normalizza(testo text) RETURNS text
LANGUAGE plpgsql IMMUTABLE STRICT PARALLEL SAFE AS $$
DECLARE
    x text;
    tok text;
    pos int := 0;
    out text[] := '{}';
    r int;
BEGIN
    x := lower(geocoder.senza_accenti(testo));
    x := translate(x, '’‘`´', '''''''''');

    -- Abbreviazioni puntate su più lettere, prima di togliere i punti.
    x := regexp_replace(x, '\mv\.\s*le\M\.?', ' viale ', 'g');
    x := regexp_replace(x, '\mp\.\s*(zza|za|zzale|le)\M\.?', ' p\1 ', 'g');
    x := regexp_replace(x, '\mc\.\s*so\M\.?', ' corso ', 'g');
    x := regexp_replace(x, '\ml\.\s*go\M\.?', ' largo ', 'g');
    x := regexp_replace(x, '\mv\.\s*lo\M\.?', ' vicolo ', 'g');
    x := regexp_replace(x, '\mf\.\s*lli\M\.?', ' fratelli ', 'g');
    -- Sigle puntate: S.S. 16, S.P., s.n.c. -> ss, sp, snc
    x := regexp_replace(x, '\m([a-z])\.([a-z])\.([a-z])\.', '\1\2\3 ', 'g');
    x := regexp_replace(x, '\m([a-z])\.([a-z])\.', '\1\2 ', 'g');

    -- Tutto ciò che non è lettera o cifra diventa spazio (apostrofi compresi).
    x := regexp_replace(x, '[^a-z0-9]+', ' ', 'g');
    x := btrim(x);
    IF x = '' THEN
        RETURN '';
    END IF;

    FOREACH tok IN ARRAY regexp_split_to_array(x, ' ') LOOP
        pos := pos + 1;
        tok := CASE
            WHEN tok = 'v' AND pos = 1 THEN 'via'
            WHEN tok IN ('vle') THEN 'viale'
            WHEN tok IN ('pza', 'pzza', 'pz') THEN 'piazza'
            WHEN tok IN ('ple', 'pzle', 'pzzale') THEN 'piazzale'
            WHEN tok IN ('pzta') THEN 'piazzetta'
            WHEN tok IN ('cso') THEN 'corso'
            WHEN tok IN ('lgo') THEN 'largo'
            WHEN tok IN ('vlo', 'vic') THEN 'vicolo'
            WHEN tok IN ('str') THEN 'strada'
            WHEN tok IN ('loc', 'locali', 'lc') THEN 'localita'
            WHEN tok IN ('fraz', 'fr', 'fz') THEN 'frazione'
            WHEN tok IN ('contr', 'cda') THEN 'contrada'
            WHEN tok IN ('bgo') THEN 'borgo'
            WHEN tok IN ('circ', 'circonv') THEN 'circonvallazione'
            WHEN tok IN ('lungom') THEN 'lungomare'
            WHEN tok IN ('trav') THEN 'traversa'
            WHEN tok IN ('flli') THEN 'fratelli'
            WHEN tok IN ('gen') THEN 'generale'
            WHEN tok IN ('naz') THEN 'nazionale'
            WHEN tok IN ('prov') THEN 'provinciale'
            WHEN tok IN ('com') THEN 'comunale'
            -- Santi: tutte le forme diventano "s".
            WHEN tok IN ('san', 'santo', 'santa', 'sant', 'st', 'sto', 'sta', 's') THEN 's'
            -- Titoli: rimossi, la gente li scrive in modo incoerente.
            WHEN tok IN ('dott', 'dottor', 'dottore', 'dr', 'prof', 'professor',
                         'professore', 'avv', 'avvocato', 'ing', 'ingegnere',
                         'on', 'onorevole', 'cav', 'cavaliere', 'rag', 'arch',
                         'mons', 'monsignor', 'monsignore', 'sen', 'senatore')
                 THEN NULL
            ELSE tok
        END;
        IF tok IS NULL THEN
            CONTINUE;
        END IF;
        -- Numeri romani (non la "v" iniziale, che è "via").
        r := geocoder.romano_a_intero(tok);
        IF r IS NOT NULL THEN
            tok := r::text;
        END IF;
        out := out || tok;
    END LOOP;

    RETURN array_to_string(out, ' ');
END
$$;

-- Denominazioni Urbanistiche Generiche (DUG) riconosciute in testa al nome.
-- Le DUG di due parole vanno prima, così "strada provinciale" vince su "strada".
CREATE OR REPLACE FUNCTION geocoder.dug_note() RETURNS text[]
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
    SELECT ARRAY[
        'strada statale', 'strada provinciale', 'strada regionale',
        'strada comunale', 'strada vicinale', 'via vicinale', 'case sparse',
        'rio terra', 'ss', 'sp', 'sr', 'sc',
        'via', 'viale', 'vialetto', 'piazza', 'piazzale', 'piazzetta', 'corso',
        'largo', 'vicolo', 'vico', 'strada', 'stradone', 'stradella',
        'stradello', 'contrada', 'localita', 'frazione', 'borgo', 'borgata',
        'lungomare', 'lungolago', 'lungofiume', 'lungotevere', 'lungarno',
        'lungadige', 'lungopo', 'salita', 'discesa', 'rampa', 'scalinata',
        'galleria', 'passaggio', 'traversa', 'calle', 'campo', 'campiello',
        'fondamenta', 'salizada', 'ruga', 'sestiere', 'rotonda', 'circonvallazione',
        'regione', 'rione', 'quartiere', 'villaggio', 'parco', 'viottolo',
        'cortile', 'corte', 'baluardo', 'molo', 'banchina', 'spalto',
        'sottopassaggio', 'cavalcavia', 'tangenziale', 'giardino', 'giardini',
        'traversa', 'vico', 'vietta', 'cupa', 'gradoni', 'scesa', 'scala',
        'supportico', 'trazzera', 'regia trazzera', 'masseria', 'podere', 'cascina'
    ]
$$;

-- DUG di un nome già normalizzato, o NULL se il nome non inizia con una DUG.
CREATE OR REPLACE FUNCTION geocoder.dug(norm text) RETURNS text
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
    SELECT d
    FROM unnest(geocoder.dug_note()) WITH ORDINALITY AS u(d, o)
    WHERE norm = d OR norm LIKE d || ' %'
    ORDER BY length(d) DESC, o
    LIMIT 1
$$;

-- Denominazione: il nome normalizzato senza DUG, articoli, preposizioni e iniziali.
-- È la chiave usata per il confronto fuzzy delle strade:
--   'via della repubblica' -> 'repubblica'
--   'piazza s maria'       -> 's maria'
--   'via g garibaldi'      -> 'garibaldi'
CREATE OR REPLACE FUNCTION geocoder.denominazione(norm text) RETURNS text
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
    WITH senza_dug AS (
        SELECT CASE
            WHEN geocoder.dug(norm) IS NULL THEN norm
            WHEN norm = geocoder.dug(norm) THEN norm  -- "Via" da sola: non svuotare
            ELSE substr(norm, length(geocoder.dug(norm)) + 2)
        END AS s
    )
    SELECT coalesce(nullif(array_to_string(ARRAY(
        SELECT w
        FROM unnest(regexp_split_to_array(s, ' ')) WITH ORDINALITY AS t(w, o)
        WHERE w NOT IN ('di', 'del', 'della', 'dello', 'dei', 'degli', 'delle',
                        'd', 'de', 'da', 'dal', 'dalla', 'e', 'ed', 'il', 'lo',
                        'la', 'i', 'gli', 'le', 'l', 'al', 'alla', 'allo', 'ai',
                        'agli', 'alle', 'in', 'a')
          -- Iniziali puntate ("G. Garibaldi", "F. Turati"): rumore per il confronto.
          AND (length(w) > 1 OR w = 's' OR w ~ '^\d$')
        ORDER BY o
    ), ' '), ''), s)
    FROM senza_dug
$$;

-- Civico scomposto.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                   WHERE n.nspname = 'geocoder' AND t.typname = 'civico_t') THEN
        CREATE TYPE geocoder.civico_t AS (
            numero    integer,  -- parte numerica, NULL se assente
            esponente text,     -- 'a', 'bis', '1' (per 12/1), 'r' (rosso, Genova)...
            snc       boolean,  -- "senza numero civico"
            norm      text      -- forma canonica: '12', '12/a', '12/bis', 'snc'
        );
    END IF;
END
$$;

-- Interpreta un civico italiano:
--   '12'      -> (12, NULL, f, '12')
--   '12/A'    -> (12, 'a', f, '12/a')      anche '12 A', '12a', '12-A'
--   '12 bis'  -> (12, 'bis', f, '12/bis')  anche '12/bis', '12bis'
--   '12/1'    -> (12, '1', f, '12/1')
--   '12r'     -> (12, 'r', f, '12/r')      civici rossi; anche '12 rosso'
--   'SNC'     -> (NULL, NULL, t, 'snc')    anche 's.n.c.', 'sn', 'senza numero'
--   'km 3,5'  -> (NULL, NULL, f, 'km 3,5') non interpretabile: resta il testo
CREATE OR REPLACE FUNCTION geocoder.civico(testo text) RETURNS geocoder.civico_t
LANGUAGE plpgsql IMMUTABLE STRICT PARALLEL SAFE AS $$
DECLARE
    x text;
    m text[];
    esp text;
BEGIN
    x := lower(geocoder.senza_accenti(btrim(testo)));
    x := regexp_replace(x, '\s+', ' ', 'g');
    IF x = '' THEN
        RETURN NULL;
    END IF;
    IF regexp_replace(x, '[^a-z]', '', 'g') IN ('snc', 'sn', 'senzanumero', 'senzanumerocivico') THEN
        RETURN ROW(NULL, NULL, true, 'snc')::geocoder.civico_t;
    END IF;

    x := regexp_replace(x, '\mrosso\M', 'r');
    x := regexp_replace(x, '\mnero\M', '');
    x := btrim(x);
    m := regexp_match(x, '^(?:n\.?\s*|nr\.?\s*)?(\d{1,5})\s*(?:[/\-\s]\s*)?(bis|ter|quater|quinquies|[a-z]|\d{1,3})?$');
    IF m IS NULL THEN
        RETURN ROW(NULL, NULL, false, x)::geocoder.civico_t;
    END IF;
    esp := m[2];
    RETURN ROW(
        m[1]::int,
        esp,
        false,
        m[1]::int::text || coalesce('/' || esp, '')
    )::geocoder.civico_t;
END
$$;

-- Geometria di una riga di staging, in EPSG:4326, a partire da:
--   geom_txt: WKT, EWKT, WKB/EWKB esadecimale o GeoJSON (sempre 4326);
--   oppure lon/lat (anche con la virgola decimale).
-- srid è il sistema di riferimento della fonte, usato quando la geometria non
-- lo dichiara. Restituisce NULL (riga scartata) se il dato non è leggibile.
CREATE OR REPLACE FUNCTION geocoder.geometria_sorgente(geom_txt text, lon text, lat text, srid integer)
RETURNS geometry LANGUAGE plpgsql STABLE PARALLEL SAFE AS $$
DECLARE
    g geometry;
    x float8;
    y float8;
BEGIN
    IF nullif(btrim(geom_txt), '') IS NOT NULL THEN
        IF left(ltrim(geom_txt), 1) = '{' THEN
            g := ST_GeomFromGeoJSON(geom_txt);
            IF ST_SRID(g) = 0 THEN
                g := ST_SetSRID(g, 4326);
            END IF;
        ELSE
            g := geom_txt::geometry;
            IF ST_SRID(g) = 0 THEN
                g := ST_SetSRID(g, srid);
            END IF;
        END IF;
    ELSIF nullif(btrim(lon), '') IS NOT NULL AND nullif(btrim(lat), '') IS NOT NULL THEN
        x := replace(btrim(lon), ',', '.')::float8;
        y := replace(btrim(lat), ',', '.')::float8;
        g := ST_SetSRID(ST_MakePoint(x, y), srid);
    ELSE
        RETURN NULL;
    END IF;
    IF ST_IsEmpty(g) THEN
        RETURN NULL;
    END IF;
    IF ST_SRID(g) <> 4326 THEN
        g := ST_Transform(g, 4326);
    END IF;
    RETURN g;
EXCEPTION WHEN others THEN
    RETURN NULL;
END
$$;
