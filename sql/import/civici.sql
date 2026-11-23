-- Da staging.civici a geocoder.civici e geocoder.strade.
-- Eseguito in una transazione da bin/importa.php. Richiede i comuni già importati
-- (il comune di ogni civico si ricava dalla posizione; il nome del comune nella
-- fonte serve solo se il punto cade fuori da tutti i confini).
--
-- Con più fonti (es. OpenAddresses e OpenStreetMap) lo stesso civico può
-- comparire due volte: si tiene quello della fonte con priorità più alta (la
-- prima nella configurazione) e si scarta l'altro se ha lo stesso civico,
-- una via con nome simile ed è entro 30 metri.
TRUNCATE geocoder.civici, geocoder.strade RESTART IDENTITY;

CREATE TEMP TABLE civici_tmp ON COMMIT DROP AS
SELECT DISTINCT ON (priorita, istat, via_norm, coalesce((c).norm, ''), geom)
       t.*, (c).numero, (c).esponente, coalesce((c).snc, false) AS snc, (c).norm AS civico_norm
FROM (
    SELECT s.fonte, s.priorita, s.fonte_id,
           btrim(s.via)                                          AS via,
           geocoder.normalizza(s.via)                            AS via_norm,
           nullif(btrim(s.civico), '')                           AS civico,
           geocoder.civico(nullif(btrim(s.civico), ''))          AS c,
           nullif(btrim(s.interno), '')                          AS interno,
           nullif(btrim(s.frazione), '')                         AS frazione,
           CASE WHEN length(regexp_replace(s.cap, '\D', '', 'g')) = 5
                THEN regexp_replace(s.cap, '\D', '', 'g') END    AS cap,
           p.geom,
           coalesce(
               (SELECT k.istat FROM geocoder.comuni k WHERE ST_Intersects(k.geom, p.geom) LIMIT 1),
               (SELECT k.istat FROM geocoder.comuni k WHERE k.nome_norm = geocoder.normalizza(s.comune) LIMIT 1)
           ) AS istat
    FROM staging.civici s
    CROSS JOIN LATERAL (SELECT ST_PointOnSurface(s.geom) AS geom) p
    WHERE s.geom IS NOT NULL AND NOT ST_IsEmpty(s.geom)
      AND nullif(btrim(s.via), '') IS NOT NULL
) t
WHERE t.via_norm <> ''
ORDER BY priorita, istat, via_norm, coalesce((c).norm, ''), geom, fonte_id;

-- Doppioni tra fonti diverse.
CREATE INDEX ON civici_tmp USING gist (geom);
ALTER TABLE civici_tmp ADD COLUMN denominazione text;
UPDATE civici_tmp SET denominazione = geocoder.denominazione(via_norm);
DELETE FROM civici_tmp b
USING civici_tmp a
WHERE a.priorita < b.priorita
  AND a.civico_norm = b.civico_norm
  AND ST_DWithin(a.geom, b.geom, 0.0005)                       -- prefiltro in gradi, sull'indice
  AND ST_DWithin(a.geom::geography, b.geom::geography, 30)
  AND (a.via_norm = b.via_norm OR similarity(a.denominazione, b.denominazione) >= 0.5);

INSERT INTO geocoder.civici (istat, via, via_norm, civico, numero, esponente, snc, civico_norm,
                             interno, cap, frazione, geom, fonte, fonte_id)
SELECT istat, via, via_norm, civico, numero, esponente, snc, civico_norm,
       interno, cap, frazione, geom, coalesce(fonte, 'openaddresses'), fonte_id
FROM civici_tmp;

-- Strade: civici con lo stesso nome nello stesso comune, separati in gruppi
-- spaziali (DBSCAN, raggio ~1 km) per distinguere omonimie tra frazioni.
CREATE TEMP TABLE gruppi_tmp ON COMMIT DROP AS
SELECT id, istat, via_norm,
       ST_ClusterDBSCAN(geom, eps := 0.012, minpoints := 1)
           OVER (PARTITION BY istat, via_norm) AS gruppo
FROM geocoder.civici;

CREATE TEMP TABLE strade_tmp ON COMMIT DROP AS
SELECT g.istat, g.via_norm, g.gruppo,
       -- Grafia da mostrare: la più frequente, preferendo quelle non tutte
       -- maiuscole (OpenAddresses scrive spesso "VIA ROMA", OSM "Via Roma").
       coalesce(mode() WITHIN GROUP (ORDER BY c.via) FILTER (WHERE c.via <> upper(c.via)),
                mode() WITHIN GROUP (ORDER BY c.via)) AS nome,
       mode() WITHIN GROUP (ORDER BY c.cap)      AS cap,
       mode() WITHIN GROUP (ORDER BY c.frazione) AS frazione,
       count(*)::int                              AS n_civici,
       ST_Multi(ST_Collect(c.geom))               AS civici_geom
FROM gruppi_tmp g JOIN geocoder.civici c USING (id)
GROUP BY g.istat, g.via_norm, g.gruppo;

ALTER TABLE strade_tmp ADD COLUMN strada_id bigint;
UPDATE strade_tmp SET strada_id = nextval(pg_get_serial_sequence('geocoder.strade', 'id'));

INSERT INTO geocoder.strade (id, istat, nome, nome_norm, dug, denominazione, cap, frazione,
                             n_civici, civici_geom, centroide)
SELECT strada_id, istat, nome, via_norm, geocoder.dug(via_norm),
       geocoder.denominazione(via_norm), cap, frazione, n_civici, civici_geom,
       ST_ClosestPoint(civici_geom, ST_Centroid(civici_geom))
FROM strade_tmp;

UPDATE geocoder.civici c
SET strada_id = s.strada_id
FROM gruppi_tmp g
JOIN strade_tmp s ON s.istat IS NOT DISTINCT FROM g.istat
                 AND s.via_norm = g.via_norm
                 AND s.gruppo = g.gruppo
WHERE c.id = g.id;
