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
        'civici' => ['tipo' => 'csv', 'file' => "$fixture/oa_civici.csv"],
        'toponimi' => [
            'tipo' => 'tabella',
            'tabella' => 'prova_igm.toponimi',
            'srid' => 3004,
            'colonne' => ['fonte_id' => 't.gid', 'nome' => 't.toponimo', 'tipo' => 't.classe',
                          'attributi' => "jsonb_build_object('quota', t.quota)", 'geom' => 't.the_geom'],
        ],
    ],
];
