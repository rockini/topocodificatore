-- Tabelle del geocoder. Tutte le geometrie sono in WGS84 (EPSG:4326).
-- Lo script è idempotente: si può rieseguire senza perdere dati.

-- Comuni ISTAT (confini amministrativi).
CREATE TABLE IF NOT EXISTS geocoder.comuni (
    istat      text PRIMARY KEY,                 -- codice ISTAT a 6 cifre, es. '015146'
    nome       text NOT NULL,
    nome_norm  text NOT NULL,
    provincia  text,                             -- sigla, es. 'MI'
    regione    text,
    geom       geometry(MultiPolygon, 4326) NOT NULL,
    centroide  geometry(Point, 4326) NOT NULL    -- punto interno (ST_PointOnSurface)
);
CREATE INDEX IF NOT EXISTS comuni_geom_gist      ON geocoder.comuni USING gist (geom);
CREATE INDEX IF NOT EXISTS comuni_nome_norm_trgm ON geocoder.comuni USING gin (nome_norm gin_trgm_ops);
CREATE INDEX IF NOT EXISTS comuni_nome_norm      ON geocoder.comuni (nome_norm);

-- Strade, aggregate dai civici OpenAddresses: una riga per nome di strada,
-- per comune e per gruppo di civici vicini (due "Via Roma" in frazioni
-- distanti dello stesso comune restano due strade diverse).
CREATE TABLE IF NOT EXISTS geocoder.strade (
    id             bigserial PRIMARY KEY,
    istat          text,
    nome           text NOT NULL,                -- grafia più frequente nei dati
    nome_norm      text NOT NULL,
    dug            text,                         -- 'via', 'piazza', ...
    denominazione  text NOT NULL,                -- nome senza DUG e articoli
    cap            text,                         -- CAP più frequente tra i civici
    n_civici       integer NOT NULL,
    civici_geom    geometry(MultiPoint, 4326) NOT NULL,
    centroide      geometry(Point, 4326) NOT NULL  -- il civico più vicino al baricentro
);
CREATE INDEX IF NOT EXISTS strade_centroide_gist      ON geocoder.strade USING gist (centroide);
CREATE INDEX IF NOT EXISTS strade_denominazione_trgm  ON geocoder.strade USING gin (denominazione gin_trgm_ops);
CREATE INDEX IF NOT EXISTS strade_istat_denominazione ON geocoder.strade (istat, denominazione);

-- Civici OpenAddresses.
CREATE TABLE IF NOT EXISTS geocoder.civici (
    id          bigserial PRIMARY KEY,
    strada_id   bigint,
    istat       text,
    via         text NOT NULL,                   -- come nella fonte
    via_norm    text NOT NULL,
    civico      text,                            -- come nella fonte
    numero      integer,
    esponente   text,
    snc         boolean NOT NULL DEFAULT false,
    civico_norm text,
    cap         text,
    geom        geometry(Point, 4326) NOT NULL,
    fonte       text NOT NULL DEFAULT 'openaddresses',
    fonte_id    text
);
CREATE INDEX IF NOT EXISTS civici_geom_gist     ON geocoder.civici USING gist (geom);
CREATE INDEX IF NOT EXISTS civici_strada_numero ON geocoder.civici (strada_id, numero, esponente);
CREATE INDEX IF NOT EXISTS civici_cap           ON geocoder.civici (cap) WHERE cap IS NOT NULL;

-- Toponimi IGM. La geometria originale (punto, linea o area) resta in geom;
-- punto è un punto sulla geometria, usato per la risposta e per il KNN.
CREATE TABLE IF NOT EXISTS geocoder.toponimi_igm (
    id         bigserial PRIMARY KEY,
    nome       text NOT NULL,
    nome_norm  text NOT NULL,
    tipo       text,                             -- classe IGM: centro abitato, località, monte...
    istat      text,
    geom       geometry(Geometry, 4326) NOT NULL,
    punto      geometry(Point, 4326) NOT NULL,
    attributi  jsonb,                            -- altri campi della fonte, a scelta
    fonte_id   text
);
CREATE INDEX IF NOT EXISTS toponimi_punto_gist     ON geocoder.toponimi_igm USING gist (punto);
CREATE INDEX IF NOT EXISTS toponimi_nome_norm_trgm ON geocoder.toponimi_igm USING gin (nome_norm gin_trgm_ops);
CREATE INDEX IF NOT EXISTS toponimi_istat          ON geocoder.toponimi_igm (istat);

-- Registro delle importazioni.
CREATE TABLE IF NOT EXISTS geocoder.importazioni (
    id          bigserial PRIMARY KEY,
    dataset     text NOT NULL,
    sorgente    text,
    righe       bigint,
    scartate    bigint,
    iniziata    timestamptz NOT NULL,
    terminata   timestamptz NOT NULL DEFAULT now()
);

-- Tabelle di appoggio per l'import: colonne canoniche, tutte testuali, più la
-- geometria calcolata. Gli script le svuotano e le riempiono a ogni import.
CREATE UNLOGGED TABLE IF NOT EXISTS staging.comuni (
    istat text, nome text, provincia text, regione text,
    geom_txt text, lon text, lat text,
    geom geometry
);
CREATE UNLOGGED TABLE IF NOT EXISTS staging.civici (
    fonte_id text, via text, civico text, unita text, comune text,
    provincia text, cap text,
    geom_txt text, lon text, lat text,
    geom geometry
);
CREATE UNLOGGED TABLE IF NOT EXISTS staging.toponimi (
    fonte_id text, nome text, tipo text, attributi text,
    geom_txt text, lon text, lat text,
    geom geometry
);
