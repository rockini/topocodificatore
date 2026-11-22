-- Da staging.toponimi a geocoder.toponimi_igm. Eseguito in una transazione da
-- bin/importa.php. Il comune si ricava dalla posizione (servono i comuni).
TRUNCATE geocoder.toponimi_igm RESTART IDENTITY;

INSERT INTO geocoder.toponimi_igm (nome, nome_norm, tipo, istat, geom, punto, attributi, fonte_id)
SELECT t.nome, geocoder.normalizza(t.nome), nullif(btrim(t.tipo), ''),
       (SELECT k.istat FROM geocoder.comuni k WHERE ST_Intersects(k.geom, t.punto) LIMIT 1),
       t.geom, t.punto, nullif(t.attributi, '')::jsonb, t.fonte_id
FROM (
    SELECT btrim(nome) AS nome, tipo, attributi, fonte_id, ST_MakeValid(geom) AS geom,
           ST_PointOnSurface(ST_MakeValid(geom)) AS punto
    FROM staging.toponimi
    WHERE geom IS NOT NULL AND NOT ST_IsEmpty(geom) AND nullif(btrim(nome), '') IS NOT NULL
) t
WHERE geocoder.normalizza(t.nome) <> '';
