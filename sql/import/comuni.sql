-- Da staging.comuni a geocoder.comuni. Eseguito in una transazione da bin/importa.php.
TRUNCATE geocoder.comuni;

INSERT INTO geocoder.comuni (istat, nome, nome_norm, provincia, regione, geom, centroide)
SELECT DISTINCT ON (istat)
       istat, nome, geocoder.normalizza(nome), upper(nullif(btrim(provincia), '')),
       nullif(btrim(regione), ''), g, ST_PointOnSurface(g)
FROM (
    SELECT lpad(btrim(istat), 6, '0') AS istat, btrim(nome) AS nome, provincia, regione,
           ST_Multi(ST_CollectionExtract(ST_MakeValid(geom), 3)) AS g
    FROM staging.comuni
    WHERE geom IS NOT NULL AND nullif(btrim(istat), '') IS NOT NULL AND nullif(btrim(nome), '') IS NOT NULL
) s
WHERE NOT ST_IsEmpty(g)
ORDER BY istat;
