<?php
// Hindura aya makuru ukurikije seriveri yawe (cyangwa ukoreshe environment variables).
$cfg = [
    'db' => [
        'dsn'  => getenv('DB_DSN')  ?: 'mysql:host=localhost;dbname=mushishiro;charset=utf8mb4',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ],
    'district'     => 'Muhanga',
    'district_db'  => getenv('DB_DISTRICT') ?: 'muhanga_district',   // database y'abayobozi b'Akarere (admin na staff b'Akarere, matangazo, chat)
    'sectors'      => [                                         // buri murenge ufite database yawo (amakuru y'imirenge ntavangwa)
        'mushishiro' => ['name' => 'Mushishiro', 'db' => getenv('DB_MUSHISHIRO') ?: 'mushishiro'],
        'nyarusange' => ['name' => 'Nyarusange', 'db' => 'muhanga_nyarusange'],
        'nyamabuye'  => ['name' => 'Nyamabuye',  'db' => 'muhanga_nyamabuye'],
        'shyogwe'    => ['name' => 'Shyogwe',    'db' => 'muhanga_shyogwe'],
        'rongi'      => ['name' => 'Rongi',      'db' => 'muhanga_rongi'],
    ],
    'admin_pass'   => getenv('ADMIN_PASS') ?: '',              // ubusa = install.php ikora iry'amahirwe ikaryereka rimwe
    'admin_phone'  => getenv('ADMIN_PHONE') ?: '',             // telefone ya admin wa mbere (yo kwakira kode ya SMS)
    'admin_email'  => getenv('ADMIN_EMAIL') ?: '',             // email ya admin wa mbere (yo kwakira kode yo kwinjira)
    'smtp' => [                                                // SMTP yo kohereza email (urugero Gmail/hosting yawe); ubusa = mail() ya PHP
        'host' => getenv('SMTP_HOST') ?: '', 'port' => (int)(getenv('SMTP_PORT') ?: 465), 'secure' => getenv('SMTP_SECURE') ?: 'ssl',   // ssl (465) | tls (587)
        'user' => getenv('SMTP_USER') ?: '', 'pass' => getenv('SMTP_PASS') ?: '', 'from' => getenv('SMTP_FROM') ?: '',
    ],
    'otp_debug'    => false,                                   // true = kode igaragara kuri ecran (ITERAMBERE GUSA, ntukayikoreshe kuri internet)
    'at' => [                                                  // Africa's Talking (SMS)
        'user'   => getenv('AT_USERNAME') ?: '',
        'key'    => getenv('AT_API_KEY') ?: '',
        'sender' => getenv('AT_SENDER') ?: '',
    ],
    'cors_origin'  => getenv('CORS_ORIGIN') ?: '',               // urugero 'http://localhost:3000' iyo frontend iri ahandi; ubusa niba ari domain imwe
    'jitsi'        => getenv('JITSI_DOMAIN') ?: 'meet.jit.si', // ibyiza: Jitsi yawe bwite cyangwa JaaS
    'jitsi_secret' => getenv('JITSI_SECRET') ?: 'HINDURA-IYI-NYAMUBANO',
];
// Ibanga ryihariye rya seriveri riri muri config.local.php (rikorwa na install.php, ntirishyirwa ahagaragara)
$local = __DIR__ . '/config.local.php';
return is_file($local) ? array_replace_recursive($cfg, require $local) : $cfg;
