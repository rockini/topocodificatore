-- Toponimi di prova nel formato "sconosciuto" di una fonte IGM: tabella con
-- coordinate in Monte Mario / Italy zone 2 (EPSG:3004), per provare la
-- riproiezione e la mappatura configurabile.
DROP SCHEMA IF EXISTS prova_igm CASCADE;
CREATE SCHEMA prova_igm;
CREATE TABLE prova_igm.toponimi (
    gid serial PRIMARY KEY,
    toponimo text,
    classe text,
    quota integer,
    the_geom geometry(Point, 3004)
);
INSERT INTO prova_igm.toponimi (toponimo, classe, quota, the_geom) VALUES
    ('Pocol', 'Località abitata', 1530, ST_Transform(ST_SetSRID(ST_MakePoint(12.0970, 46.5290), 4326), 3004)),
    ('Chiaravalle', 'Località abitata', 105, ST_Transform(ST_SetSRID(ST_MakePoint(9.2380, 45.4180), 4326), 3004)),
    ('Monte Faloria', 'Cima', 2343, ST_Transform(ST_SetSRID(ST_MakePoint(12.1700, 46.5300), 4326), 3004)),
    ('', 'Senza nome', 0, ST_Transform(ST_SetSRID(ST_MakePoint(12.1, 46.5), 4326), 3004));
