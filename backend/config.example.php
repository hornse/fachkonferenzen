<?php
// ============================================================
// config.example.php – Vorlage. Kopieren nach config.php und
// ausfüllen. config.php liegt NICHT in git (.gitignore)!
//   cp config.example.php config.php
// DB-Passwort steht auf dem Server in ~/.my.cnf
// ============================================================
return [

    'db' => [
        'host' => 'localhost',
        'name' => 'hornse_fachkonferenzen',
        'user' => 'hornse',
        'pass' => 'HIER_DB_PASSWORT_AUS_MY_CNF',
    ],

    'webuntis' => [
        'enabled'              => true,
        'base_url'             => 'https://frg-dusseldorf.webuntis.com',
        'school'               => 'frg-dusseldorf',
        'client'               => 'FachkonferenzenFRG',
        // personType 2 = Lehrkraft, 16 = WebUntis-Admin (personId = -1)
        'allowed_person_types' => [2, 16],
        // Kürzel, die automatisch Admin-Rechte erhalten
        'admin_kuerzel'        => ['Hor'],
    ],

    'app' => [
        'name'     => 'Fachkonferenzen FRG',
        'base_url' => 'https://fachkonferenzen.hornse.de',
    ],
];
