<?php

// Configurazione usata da tests/esegui.php con i dati di prova in tests/fixtures.
$fixture = __DIR__ . '/fixtures';

return [
    'db' => [
        'dsn' => getenv('GEOCODER_TEST_DSN') ?: '',
        'utente' => getenv('GEOCODER_TEST_UTENTE') ?: null,
        'password' => getenv('GEOCODER_TEST_PASSWORD') ?: null,
    ],
    'importazione' => [
        'comuni' => [
            'tipo' => 'file',
            'file' => "$fixture/comuni.geojson",
            'colonne' => ['istat' => 't.pro_com_t', 'nome' => 't.comune', 'provincia' => 't.sigla',
                          'regione' => 't.regione', 'geom' => 't.geom'],
        ],
        // Due fonti di civici: OpenAddresses vince sui doppioni di OSM.
        'civici' => [
            ['fonte' => 'openaddresses', 'tipo' => 'csv', 'file' => "$fixture/oa_civici.csv"],
            [
                'fonte' => 'osm',
                'tipo' => 'tabella',
                'tabella' => 'prova_osm.osm_civici',
                'colonne' => ['fonte_id' => "t.osm_type || t.osm_id", 'via' => 't.via', 'civico' => 't.civico',
                              'interno' => 't.interno', 'frazione' => 't.frazione', 'comune' => 't.comune',
                              'cap' => 't.cap', 'geom' => 't.geom'],
            ],
        ],
        'toponimi' => [
            'fonte' => 'dbsn',
            'tipo' => 'tabella',
            'tabella' => 'prova_dbsn.loc_sg',
            'colonne' => [
                'fonte_id' => 't.fid',
                'nome' => 't.loc_sg_top',
                'tipo' => 'geocoder.dbsn_tipo_localita(t.loc_sg_ty)',
                'importanza' => 'geocoder.dbsn_importanza(t.loc_sg_ty, t.loc_sg_sgn)',
                'attributi' => "jsonb_build_object('loc_sg_ty', t.loc_sg_ty, 'loc_sg_sgn', t.loc_sg_sgn)",
                'geom' => 't.geom',
            ],
        ],
    ],
];
