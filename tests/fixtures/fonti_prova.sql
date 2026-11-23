-- Fonti di prova "nel formato originale", lette dall'import tramite le mappature
-- di tests/config.test.php. Dati sintetici.

-- Civici OpenStreetMap come li produce scripts/osm_civici.sql (colonne principali).
DROP SCHEMA IF EXISTS prova_osm CASCADE;
CREATE SCHEMA prova_osm;
CREATE TABLE prova_osm.osm_civici (
    osm_type text, osm_id bigint, civico text, via text, cap text, comune text,
    frazione text, interno text, cod_istat text, geom geometry(Point, 4326)
);
INSERT INTO prova_osm.osm_civici VALUES
    -- doppione del civico OpenAddresses Via Giuseppe Garibaldi 13 (a ~4 m): va scartato
    ('N', 101, '13', 'Via Giuseppe Garibaldi', '20121', 'Milano', NULL, NULL, '015146',
     ST_SetSRID(ST_MakePoint(9.18703, 45.48067), 4326)),
    -- stesso numero ma a 400 m: è un altro civico e resta
    ('N', 102, '15', 'Via Giuseppe Garibaldi', '20121', 'Milano', NULL, NULL, '015146',
     ST_SetSRID(ST_MakePoint(9.1870, 45.4846), 4326)),
    -- civico che OpenAddresses non ha
    ('W', 103, '31', 'Via Giuseppe Garibaldi', '20121', 'Milano', NULL, NULL, '015146',
     ST_SetSRID(ST_MakePoint(9.1870, 45.48155), 4326)),
    -- via che c'è solo in OSM
    ('N', 104, '2', 'Via Brera', '20121', 'Milano', NULL, NULL, '015146',
     ST_SetSRID(ST_MakePoint(9.1880, 45.4720), 4326));

-- Località significative DBSN (tabella loc_sg) in WGS84 / UTM 32N.
DROP SCHEMA IF EXISTS prova_dbsn CASCADE;
CREATE SCHEMA prova_dbsn;
CREATE TABLE prova_dbsn.loc_sg (
    fid serial PRIMARY KEY,
    loc_sg_top text,
    loc_sg_ty text,
    loc_sg_sgn text,
    geom geometry(MultiPolygon, 32632)
);
INSERT INTO prova_dbsn.loc_sg (loc_sg_top, loc_sg_ty, loc_sg_sgn, geom)
SELECT top, ty, sgn,
       ST_Multi(ST_Transform(ST_Buffer(ST_SetSRID(ST_MakePoint(x, y), 4326)::geography, 300)::geometry, 32632))
FROM (VALUES
    ('Pocol', '010202', '03', 12.0970, 46.5290),
    ('Chiaravalle', '010201', '02', 9.2380, 45.4180),
    -- omonimo meno importante (un colle) a Cortina: "Chiaravalle" da solo deve dare Milano
    ('Chiaravalle', '0204', '05', 12.2500, 46.6000),
    ('Monte Faloria', '0201', '04', 12.1700, 46.5300),
    ('', '0104', '06', 12.1000, 46.5000)
) v(top, ty, sgn, x, y);
