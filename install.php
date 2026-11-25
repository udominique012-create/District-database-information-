<?php
// Koresha rimwe gusa: php database/install.php
if (PHP_SAPI !== 'cli') exit('CLI only');
require __DIR__ . '/../src/core.php';
$only = $argv[1] ?? '';                                   // urugero: php database/install.php rongi  (umurenge umwe gusa)
$seed = ['mushishiro' => ['Munazi', 'Nyagasozi', 'Rwigerero', 'Rwasare', 'Rukaragata', 'Matyazo']];   // utundi tugari buri murenge utwongeramo muri admin
foreach ($C['sectors'] + ['district' => ['name' => 'Akarere ka Muhanga', 'db' => $C['district_db']]] as $slug => $sec) {
    if ($only !== '' && $only !== $slug) continue;
    echo "\n=== {$sec['name']} (database: {$sec['db']}) ===\n";
    try { (new PDO(rtrim(preg_replace('/;?dbname=[^;]*/', '', $C['db']['dsn']), ';'), $C['db']['user'], $C['db']['pass']))->exec("CREATE DATABASE IF NOT EXISTS `{$sec['db']}` CHARACTER SET utf8mb4"); }
    catch (Throwable $e) { echo "  (database ntiyakozwe n'iyi script: {$e->getMessage()} — yikore ubwawe)\n"; }
    $GLOBALS['SECTOR_OVERRIDE'] = $slug;
foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/schema.sql')))) as $sql) db()->exec($sql);
// Kuvugurura database ya kera (niba yari ifite imbonerahamwe ya 'news' idafite ibi bice)
foreach (["ALTER TABLE news ADD COLUMN aud VARCHAR(40) NOT NULL DEFAULT 'staff,exec,citizen'", "ALTER TABLE news ADD COLUMN comments_on TINYINT NOT NULL DEFAULT 0",
    "ALTER TABLE news ADD COLUMN file VARCHAR(200) DEFAULT ''", "ALTER TABLE users ADD COLUMN must_change TINYINT NOT NULL DEFAULT 0", "ALTER TABLE users ADD COLUMN email VARCHAR(120) NULL", "ALTER TABLE meets ADD COLUMN starts DATETIME NULL", "ALTER TABLE news ADD COLUMN sectors VARCHAR(200) NOT NULL DEFAULT ''", "ALTER TABLE chat ADD COLUMN sec VARCHAR(30) NOT NULL DEFAULT ''", "ALTER TABLE chat ADD COLUMN room VARCHAR(10) NOT NULL DEFAULT 'staff'", "ALTER TABLE chat ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'text'", "ALTER TABLE chat ADD COLUMN file VARCHAR(200) DEFAULT ''", "ALTER TABLE chat ADD COLUMN edited TINYINT NOT NULL DEFAULT 0", "ALTER TABLE chat ADD COLUMN deleted TINYINT NOT NULL DEFAULT 0", "ALTER TABLE chat ADD COLUMN upd TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", "ALTER TABLE fb ADD COLUMN reply_at TIMESTAMP NULL DEFAULT NULL", "ALTER TABLE users ADD COLUMN duty VARCHAR(120) DEFAULT ''", "ALTER TABLE users ADD COLUMN sex VARCHAR(10) DEFAULT ''", "ALTER TABLE users ADD COLUMN village VARCHAR(80) DEFAULT ''",
    "ALTER TABLE users ADD COLUMN isibo VARCHAR(80) DEFAULT ''", "ALTER TABLE users ADD COLUMN photo VARCHAR(200) DEFAULT ''"] as $sql) {
    try { db()->exec($sql); } catch (PDOException $e) { /* birahari */ }
}
// Ibanga ryihariye rya seriveri (rikorwa rimwe)
$local = __DIR__ . '/../src/config.local.php';
if (!is_file($local)) { file_put_contents($local, "<?php\nreturn ['jitsi_secret' => '" . bin2hex(random_bytes(24)) . "'];\n"); @chmod($local, 0600); echo "Byakozwe: src/config.local.php (ibanga rya seriveri)\n"; }
// Admin wa mbere: ijambobanga ry'amahirwe, rigomba guhindurwa akimara kwinjira
if (!q("select 1 from users where role='admin'")->fetch()) {
    $pw = $C['admin_pass'] ?: substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 12) . 'a7';
    q("insert into users(name,role,phone,email,`user`,pass,must_change) values('Admin','admin',?,?,'admin',?,1)", [norm_phone((string)$C['admin_phone']), ($C['admin_email'] ?: null), password_hash($pw, PASSWORD_DEFAULT)]);
    echo "\n  ADMIN: username = admin   ijambobanga ry'agateganyo = $pw\n  (rigaragara rimwe gusa. Uzahatirwa kuryihindura winjiye.)\n\n";
}
// Konti za admin zikoresha ijambobanga ryo mu bisanzwe 'admin123' zihatirwa kurihindura
foreach (q("select id,pass from users where role='admin'")->fetchAll() as $a)
    if (password_verify('admin123', $a['pass'])) q('update users set must_change=1 where id=?', [$a['id']]);
if (!filter_var((string)$C['admin_email'], FILTER_VALIDATE_EMAIL)) echo "Icyitonderwa: ADMIN_EMAIL ntishyizweho; admin azasabwa kongeramo email yinjiye bwa mbere.\n";
// Utugari twa Mushishiro
$cl = $seed[$slug] ?? [];
q("delete from cells where name like 'Akagari ka %' and not exists (select 1 from users u where u.cell=cells.name)");
foreach ($cl as $c) q('insert ignore into cells values(?)', [$c]);
// Imidugudu n'amasibo by'agateganyo (admin abihindura muri tab "Utugari")
if (!q('select 1 from villages')->fetch())
    foreach ($cl as $c) for ($i = 1; $i <= 3; $i++) {
        q('insert into villages(cell,name) values(?,?)', [$c, "Umudugudu wa $i"]); $vid = db()->lastInsertId();
        for ($j = 1; $j <= 3; $j++) q('insert into isibo(village_id,name) values(?,?)', [$vid, "Isibo ya $j"]);
    }
}
echo "\nByarangiye.\n";
