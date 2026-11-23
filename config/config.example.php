<?php

// Copia questo file in config/config.php e adattalo. config/config.php non va
// nel repository (è in .gitignore). In alternativa la connessione si può dare
// con le variabili d'ambiente GEOCODER_DSN, GEOCODER_DB_UTENTE e GEOCODER_DB_PASSWORD.

return [
    // Database del geocoder (PostgreSQL con PostGIS, pg_trgm e unaccent).
    'db' => [
        'dsn' => 'pgsql:host=localhost;port=5432;dbname=geocoder',
        'utente' => 'geocoder',
        'password' => '',
    ],

    // Fonti per bin/importa.php. Per ogni dataset scegli un "tipo" e scrivi la
    // mappatura "colonne": colonna canonica => colonna o espressione della fonte.
    // Per la geometria indica 'geom' oppure 'lon' e 'lat', e con 'srid' il
    // sistema di riferimento della fonte quando la geometria non lo dichiara
    // (4326 WGS84, 3003/3004 Monte Mario, 6707/6708 RDN2008 UTM 32N/33N, 32632/32633 WGS84 UTM).
    'importazione' => [

        // Confini comunali ISTAT, per esempio lo shapefile "Com01012025_WGS84"
        // della pubblicazione ISTAT "Confini delle unità amministrative a fini statistici".
        // Con tipo "file" ogr2ogr carica il file in staging.comuni_sorgente e le
        // espressioni sotto si riferiscono a quella tabella con l'alias t
        // (i nomi delle colonne vengono resi minuscoli).
        'comuni' => [
            'tipo' => 'file',
            'file' => '/dati/istat/Com01012025_WGS84.shp',
            'colonne' => [
                'istat' => 't.pro_com_t',
                'nome' => 't.comune',
                // Lo shapefile dei comuni non ha la sigla: se hai caricato anche
                // quello delle province puoi ricavarla con una sottoquery, es.
                // "(SELECT p.sigla FROM public.province p WHERE p.cod_prov = t.cod_prov)".
                'provincia' => null,
                'regione' => null,
                'geom' => 't.geom',
            ],
        ],

        // Civici: più fonti, in ordine di priorità. Quando lo stesso civico (stesso
        // numero, via con nome simile, entro 30 m) compare in più fonti, si tiene
        // quello della prima. Con una fonte sola basta l'array della fonte.
        'civici' => [
            // 1. OpenAddresses Italia (colonne LON, LAT, NUMBER, STREET, UNIT, CITY,
            //    DISTRICT, REGION, POSTCODE, ID, HASH), già caricato in una tabella.
            //    Se le colonne sono state create in maiuscolo con le virgolette,
            //    scrivile così: 't."STREET"'.
            [
                'fonte' => 'openaddresses',
                'tipo' => 'tabella',
                'tabella' => 'public.openaddresses',  // oppure 'query' => 'SELECT ... FROM ...'
                'filtro' => null,                     // es. "t.region = 'TOSCANA'" per un import parziale
                'srid' => 4326,
                'colonne' => [
                    'fonte_id' => 't.hash',
                    'via' => 't.street',
                    'civico' => 't.number',           // comprende gli esponenti: 12/A
                    'interno' => 't.unit',            // scala, interno: "INT 4"
                    'frazione' => 't.district',
                    'comune' => 't.city',
                    'provincia' => 't.region',
                    'cap' => 't.postcode',
                    'lon' => 't.lon',
                    'lat' => 't.lat',
                ],
            ],
            // In alternativa, direttamente dai file di OpenAddresses (mappatura predefinita):
            //   ['fonte' => 'openaddresses', 'tipo' => 'csv', 'file' => '/dati/oa/it/*.csv']   (anche .csv.gz)
            //   ['fonte' => 'openaddresses', 'tipo' => 'geojsonl', 'file' => '/dati/oa/it/*.geojson']

            // 2. OpenStreetMap, tabella osm_civici creata da scripts/osm_civici.sql.
            [
                'fonte' => 'osm',
                'tipo' => 'tabella',
                'tabella' => 'public.osm_civici',
                'colonne' => [
                    'fonte_id' => "t.osm_type || t.osm_id",
                    'via' => 't.via',
                    'civico' => 't.civico',
                    'interno' => 't.interno',
                    'frazione' => 't.frazione',
                    'comune' => 't.comune',
                    'cap' => 't.cap',
                    'geom' => 't.geom',
                ],
            ],
            // Una fonte in un altro database:
            //   ['fonte' => ..., 'tipo' => 'pdo', 'dsn' => 'pgsql:host=...;dbname=...', 'utente' => ..., 'password' => ...,
            //    'tabella' => ..., 'colonne' => [... 'geom' => 'ST_AsEWKT(t.geom)']]
        ],

        // Toponimi: località significative del DBSN IGM (tabella loc_sg).
        // Se il DBSN è in un GeoPackage usa 'tipo' => 'file' (ogr2ogr legge il
        // sistema di riferimento dal file); se l'hai già caricato in PostGIS usa
        // 'tipo' => 'tabella' con 'tabella' => 'dbsn.loc_sg'.
        // Le funzioni geocoder.dbsn_* (sql/04_dbsn.sql) traducono il codice del tipo
        // e calcolano l'importanza da tipo e significatività.
        'toponimi' => [
            'fonte' => 'dbsn',
            'tipo' => 'file',
            'file' => '/dati/dbsn/dbsn.gpkg',
            'layer' => 'loc_sg',
            'colonne' => [
                'fonte_id' => 't.ogc_fid',
                'nome' => 't.loc_sg_top',
                'tipo' => 'geocoder.dbsn_tipo_localita(t.loc_sg_ty)',
                'importanza' => 'geocoder.dbsn_importanza(t.loc_sg_ty, t.loc_sg_sgn)',
                'attributi' => "jsonb_build_object('loc_sg_ty', t.loc_sg_ty, 'loc_sg_sgn', t.loc_sg_sgn)",
                'geom' => 't.geom',
            ],
        ],
    ],
];
