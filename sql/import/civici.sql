-- Da staging.civici a geocoder.civici e geocoder.strade.
-- Eseguito in una transazione da bin/importa.php. Richiede i comuni già importati
-- (il comune di ogni civico si ricava dalla posizione; il nome del comune nella
-- fonte serve solo se il punto cade fuori da tutti i confini).
TRUNCATE geocoder.civici, geocoder.strade RESTART IDENTITY;

CREATE TEMP TABLE civici_tmp ON COMMIT DROP AS
SELECT s.fonte_id,
       btrim(s.via)                                    AS via,
       geocoder.normalizza(s.via)                      AS via_norm,
       nullif(btrim(concat_ws(' ', s.civico, s.unita)), '') AS civico,
       nullif(regexp_replace(s.cap, '\D', '', 'g'), '') AS cap,
       s.comune,
       ST_PointOnSurface(s.geom)                       AS geom
FROM staging.civici s
WHERE s.geom IS NOT NULL AND NOT ST_IsEmpty(s.geom)
  AND nullif(btrim(s.via), '') IS NOT NULL;

INSERT INTO geocoder.civici (istat, via, via_norm, civico, numero, esponente, snc,
                             civico_norm, cap, geom, fonte_id)
SELECT DISTINCT ON (istat, t.via_norm, coalesce((c).norm, ''), t.geom)
       coalesce(
           (SELECT k.istat FROM geocoder.comuni k WHERE ST_Intersects(k.geom, t.geom) LIMIT 1),
           (SELECT k.istat FROM geocoder.comuni k WHERE k.nome_norm = geocoder.normalizza(t.comune) LIMIT 1)
       ) AS istat,
       t.via, t.via_norm, t.civico,
       (c).numero, (c).esponente, coalesce((c).snc, false), (c).norm,
       CASE WHEN length(t.cap) = 5 THEN t.cap END,
       t.geom, t.fonte_id
FROM (SELECT *, geocoder.civico(civico) AS c FROM civici_tmp) t
WHERE t.via_norm <> '';

-- Strade: civici con lo stesso nome nello stesso comune, separati in gruppi
-- spaziali (DBSCAN, raggio ~1 km) per distinguere omonimie tra frazioni.
CREATE TEMP TABLE gruppi_tmp ON COMMIT DROP AS
SELECT id, istat, via_norm,
       ST_ClusterDBSCAN(geom, eps := 0.012, minpoints := 1)
           OVER (PARTITION BY istat, via_norm) AS gruppo
FROM geocoder.civici;

CREATE TEMP TABLE strade_tmp ON COMMIT DROP AS
SELECT g.istat, g.via_norm, g.gruppo,
       mode() WITHIN GROUP (ORDER BY c.via)  AS nome,
       mode() WITHIN GROUP (ORDER BY c.cap)  AS cap,
       count(*)::int                          AS n_civici,
       ST_Multi(ST_Collect(c.geom))           AS civici_geom
FROM gruppi_tmp g JOIN geocoder.civici c USING (id)
GROUP BY g.istat, g.via_norm, g.gruppo;

ALTER TABLE strade_tmp ADD COLUMN strada_id bigint;
UPDATE strade_tmp SET strada_id = nextval(pg_get_serial_sequence('geocoder.strade', 'id'));

INSERT INTO geocoder.strade (id, istat, nome, nome_norm, dug, denominazione, cap,
                             n_civici, civici_geom, centroide)
SELECT strada_id, istat, nome, via_norm, geocoder.dug(via_norm),
       geocoder.denominazione(via_norm), cap, n_civici, civici_geom,
       ST_ClosestPoint(civici_geom, ST_Centroid(civici_geom))
FROM strade_tmp;

UPDATE geocoder.civici c
SET strada_id = s.strada_id
FROM gruppi_tmp g
JOIN strade_tmp s ON s.istat IS NOT DISTINCT FROM g.istat
                 AND s.via_norm = g.via_norm
                 AND s.gruppo = g.gruppo
WHERE c.id = g.id;
