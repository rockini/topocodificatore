-- Civici da OpenStreetMap: crea la tabella osm_civici (EPSG:4326) con le stesse
-- colonne logiche dei civici OpenAddresses, a partire dalle tabelle standard di
-- osm2pgsql importate con --hstore.
--
-- Presupposti:
--   - SRID di import 3857 (predefinito di osm2pgsql); se hai importato con -l,
--     togli i ST_Transform;
--   - la colonna tags contiene tutti i tag: se addr:* non finisce lì, importa
--     con --hstore-all;
--   - con lo stile flex al posto delle tabelle planet_osm_* cambiano i nomi delle tabelle.
--
-- Per unirla a OpenAddresses: vista UNION ALL con una colonna fonte_dataset
-- ('osm' / 'oa'), scartando i civici OSM che hanno un civico OA con lo stesso
-- numero entro circa 30 m.

CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

DROP TABLE IF EXISTS osm_civici;
CREATE TABLE osm_civici AS
WITH src AS (
  SELECT 'N' AS osm_type, osm_id, tags, way AS geom, 1 AS prio
  FROM planet_osm_point
  WHERE tags ? 'addr:housenumber'
  UNION ALL
  SELECT CASE WHEN osm_id < 0 THEN 'R' ELSE 'W' END, abs(osm_id), tags,
         ST_PointOnSurface(way), 2
  FROM planet_osm_polygon
  WHERE tags ? 'addr:housenumber'
)
SELECT
  osm_type, osm_id,
  trim(tags->'addr:housenumber')                      AS civico,
  coalesce(tags->'addr:street', tags->'addr:place')   AS via,
  (tags ? 'addr:place' AND NOT tags ? 'addr:street')  AS solo_localita,
  tags->'addr:street:de'                              AS via_de,
  tags->'addr:postcode'                               AS cap,
  tags->'addr:city'                                   AS comune_osm,
  coalesce(tags->'addr:hamlet', tags->'addr:suburb')  AS frazione,
  tags->'addr:unit'                                   AS interno,
  (tags->'entrance') IS NOT NULL                      AS is_ingresso,
  coalesce(tags->'source:addr', tags->'source')       AS fonte,
  prio,
  ST_Transform(geom, 4326)::geometry(Point,4326)      AS geom
FROM src
WHERE coalesce(tags->'addr:street', tags->'addr:place') IS NOT NULL;

-- Comune ISTAT dai confini OSM (admin_level 8)
ALTER TABLE osm_civici ADD COLUMN cod_istat text, ADD COLUMN comune text;
UPDATE osm_civici c SET cod_istat = b.tags->'ref:ISTAT', comune = b.name
FROM planet_osm_polygon b
WHERE b.boundary = 'administrative' AND b.admin_level = '8'
  AND ST_Contains(b.way, ST_Transform(c.geom, 3857));

-- Campi normalizzati per il matching
ALTER TABLE osm_civici ADD COLUMN via_norm text, ADD COLUMN civico_norm text;
UPDATE osm_civici SET
  via_norm    = lower(unaccent(regexp_replace(via, '\s+', ' ', 'g'))),
  civico_norm = upper(regexp_replace(civico, '\s+', '', 'g'));

-- Doppioni: si tiene l'ingresso o il nodo rispetto al centroide dell'edificio
DELETE FROM osm_civici a USING osm_civici b
WHERE a.cod_istat = b.cod_istat AND a.via_norm = b.via_norm
  AND a.civico_norm = b.civico_norm
  AND (a.prio, a.osm_id) > (b.prio, b.osm_id);

CREATE INDEX ON osm_civici USING gist (geom);
CREATE INDEX ON osm_civici USING gin (via_norm gin_trgm_ops);
CREATE INDEX ON osm_civici (cod_istat, via_norm, civico_norm);
ANALYZE osm_civici;
