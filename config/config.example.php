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

        // Civici OpenAddresses già caricati in una tabella dello stesso database.
        // Le colonne sono quelle dei CSV di OpenAddresses; adattale al tuo schema.
        'civici' => [
            'tipo' => 'tabella',
            'tabella' => 'public.openaddresses',     // oppure 'query' => 'SELECT ... FROM ...'
            'filtro' => null,                        // es. "t.region = 'Lombardia'" per un import parziale
            'srid' => 4326,
            'colonne' => [
                'fonte_id' => 't.hash',
                'via' => 't.street',
                'civico' => 't.number',
                'unita' => 't.unit',
                'comune' => 't.city',
                'provincia' => 't.region',
                'cap' => 't.postcode',
                'lon' => 't.lon',
                'lat' => 't.lat',
                // oppure, con una colonna geometry: 'geom' => 't.geom',
            ],
        ],
        // Altre fonti possibili per i civici:
        //   da un altro database:  ['tipo' => 'pdo', 'dsn' => 'pgsql:host=...;dbname=oa', 'utente' => ..., 'password' => ...,
        //                           'tabella' => 'public.openaddresses', 'colonne' => [... 'geom' => 'ST_AsEWKT(t.geom)']]
        //   dai file di OpenAddresses (mappatura predefinita, 'colonne' facoltativa):
        //                          ['tipo' => 'csv', 'file' => '/dati/oa/it/*.csv']   (anche .csv.gz)
        //                          ['tipo' => 'geojsonl', 'file' => '/dati/oa/it/*.geojson']

        // Toponimi IGM. Il formato esatto della fonte non è ancora noto: questo è
        // un esempio per uno shapefile; tipo 'tabella' o 'pdo' funzionano allo stesso modo.
        'toponimi' => [
            'tipo' => 'file',
            'file' => '/dati/igm/toponimi.shp',
            'srid' => 3003,          // solo se il file non ha il .prj
            'codifica' => 'UTF-8',   // codifica del .dbf, es. 'ISO-8859-1'
            'colonne' => [
                'fonte_id' => 't.ogc_fid',
                'nome' => 't.toponimo',
                'tipo' => 't.classe',
                // Campi della fonte da conservare in toponimi_igm.attributi (jsonb):
                'attributi' => "jsonb_build_object('quota', t.quota)",
                'geom' => 't.geom',
            ],
        ],
    ],
];
