<?php
declare(strict_types=1);
require __DIR__ . '/../src/core.php';

[$res, $id, $act] = array_pad(explode('/', trim($_GET['route'] ?? '', '/')), 3, '');
$id = (int)$id;
$m = $_SERVER['REQUEST_METHOD'];
$cells = fn() => array_column(q('select name from cells order by name')->fetchAll(), 'name');
$canMeet = fn(array $u, array $x) => $u['role'] === 'admin' || $u['role'] === 'staff' || $x['a'] === 'all' || $x['a'] === $u['role'];
$text = fn(string $k) => trim((string)(body()[$k] ?? ''));
// Abakozi b'akagari babarirwa mu itsinda rya "exec" ku bijyanye n'aho amakuru yoherezwa.
$grp = fn(string $role) => $role === 'worker' ? 'exec' : $role;
// Ese uyu mukoresha yemerewe kubona aya makuru?
$sees = fn(array $u, array $n) => $u['role'] === 'admin' || (int)$n['uid'] === $u['id'] ||
    (in_array($grp($u['role']), explode(',', $n['aud']), true) && ($u['role'] === 'staff' || $n['cell'] === '' || $n['cell'] === $u['cell']));
// Abantu bose amakuru agomba kugeraho (kugira ngo tubare "yarebwe na bangahe kuri bangahe").
$target = function (array $n): int {
    $a = explode(',', $n['aud']); $c = (string)$n['cell'];
    $sql = "select count(*) from users where id<>? and ((role='staff' and ?) or (role in ('exec','worker') and ? and (?='' or cell=?)) or (role='citizen' and ? and (?='' or cell=?)))";
    return (int)q($sql, [(int)$n['uid'], (int)in_array('staff', $a), (int)in_array('exec', $a), $c, $c, (int)in_array('citizen', $a), $c, $c])->fetchColumn();
};

$geo = function (): array {
    $out = [];
    foreach (q('select name from cells order by name')->fetchAll() as $c) {
        $vs = [];
        foreach (q('select id,name from villages where cell=? order by name', [$c['name']])->fetchAll() as $v) {
            $v['isibo'] = q('select id,name from isibo where village_id=? order by name', [$v['id']])->fetchAll(); $vs[] = $v;
        }
        $out[] = ['name' => $c['name'], 'villages' => $vs];
    }
    return $out;
};
/** Genzura ko akagari, umudugudu na isibo bihuye; isubiza id y'umudugudu. */
$checkGeo = function (string $cell, string $vil, string $isi): void {
    $v = q('select id from villages where cell=? and name=?', [$cell, $vil])->fetch();
    if (!$v) fail('Hitamo akagari n’umudugudu bibaho');
    if (!q('select 1 from isibo where village_id=? and name=?', [$v['id'], $isi])->fetch()) fail('Hitamo isibo ibaho');
};
/** Ibyumba bya chat: 'staff' (Admin+Staff), 'exec' (Admin+Ba Executive bose) */
$chatRoom = function (array $u, string $room): string {
    $room = $room ?: ($u['role'] === 'exec' ? 'exec' : 'staff');
    $dist = cur_sector() === 'district';
    $ok = ($room === 'staff' && in_array($u['role'], ['admin', 'staff'], true))
        || ($room === 'exec' && !$dist && in_array($u['role'], ['admin', 'exec'], true))
        || ($room === 'leaders' && ($u['role'] === 'admin' || ($dist && $u['role'] === 'staff')));   // abayobozi b'imirenge (admin) + abayobozi b'Akarere
    if (!$ok) fail('Ntiwemerewe iki cyumba', 403);
    return $room;
};
$cq = fn(string $room, string $sql, array $p = []) => qs($room === 'leaders' ? 'district' : cur_sector(), $sql, $p);   // icyumba cy'abayobozi kibikwa muri database y'Akarere
$csec = fn(string $room) => $room === 'leaders' ? cur_sector() : '';
$needDist = function (): array { $s = need('admin', 'staff'); if (cur_sector() !== 'district') fail('Ntiwemerewe', 403); return $s; };
$sexes = ['Umugabo', 'Umugore']; $cats = ['Urubyiruko', 'Umuntu mukuru'];

try {
    switch ("$m $res") {
        // ---------- Kwinjira ----------
        case 'GET sectors':
            out(array_map(fn($k, $v) => ['slug' => $k, 'name' => $v['name']], array_keys($C['sectors']), $C['sectors']));
        case 'POST login':
            $k = $text('user'); $ip = $_SERVER['REMOTE_ADDR'] ?? ''; $ident = mb_strtolower(norm_phone($k) ?: $k);
            q('delete from login_attempts where at < now() - interval 1 day');
            $f1 = (int)q('select count(*) from login_attempts where ident=? and at > now() - interval 15 minute', [$ident])->fetchColumn();
            $f2 = (int)q("select count(*) from login_attempts where ip=? and ident<>'reg' and at > now() - interval 15 minute", [$ip])->fetchColumn();
            if ($f1 >= 5 || $f2 >= 30) fail('Kwinjira kwafunzwe by’agateganyo kubera ibigeragezo byinshi. Ongera ugerageze nyuma y’iminota 15.', 429);
            $u = q('select * from users where `user` in (?,?) limit 1', [$k, norm_phone($k)])->fetch();   // username cyangwa telefone
            if (!$u || !password_verify((string)(body()['pass'] ?? ''), $u['pass'])) {
                q('insert into login_attempts(ident,ip) values(?,?)', [$ident, $ip]); usleep(400000); fail('Amakuru siyo');
            }
            q('delete from login_attempts where ident=?', [$ident]);
            if (priv($u['role']) && must_state($u) !== 'email') {          // 2FA: kode ya SMS
                session_regenerate_id(true);
                $dbg = send_otp($u); lg($u['name'], 'Yatanze ijambobanga neza, itegereje kode ya email');
                out(['need_otp' => true, 'to' => mask_email((string)$u['email'])] + $dbg);
            }
            lg($u['name'], 'Yinjiye'); out(login_as($u));
        case 'POST otp':
            $o = $_SESSION['otp'] ?? null; if (!$o) fail('Ongera utangire kwinjira', 401);
            $GLOBALS['SECTOR_OVERRIDE'] = $o['sec'] ?? cur_sector();
            $u = q('select * from users where id=?', [$o['uid']])->fetch(); if (!$u) fail('Ongera utangire kwinjira', 401);
            if (!empty(body()['resend'])) {
                if (time() - $o['sent'] < 60) fail('Tegereza amasegonda 60 mbere yo gusaba indi kode', 429);
                out(['sent' => true] + send_otp($u));
            }
            if (time() > $o['exp'] || $o['tries'] >= 5) { unset($_SESSION['otp']); fail('Kode yarangiye cyangwa wagerageje cyane. Ongera winjire.', 401); }
            $_SESSION['otp']['tries']++;
            if (!password_verify(preg_replace('/\D/', '', $text('code')), $o['h'])) fail('Kode si yo');
            unset($_SESSION['otp']); lg($u['name'], 'Yinjiye (kode ya SMS)'); out(login_as($u));
        case 'POST logout':
            session_destroy(); out([]);
        case 'POST register':
            if (cur_sector() === 'district') fail('Abaturage biyandikisha mu murenge wabo', 400);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';        // kwirinda gusuka SMS nyinshi
            if ((int)q("select count(*) from login_attempts where ident='reg' and ip=? and at > now() - interval 1 hour", [$ip])->fetchColumn() >= 5) fail('Ibigeragezo byinshi cyane. Ongera ugerageze nyuma y’isaha.', 429);
            $name = preg_replace('/\s+/', ' ', $text('name')); $phone = norm_phone($text('phone'));
            [$sex, $cat, $cell, $vil, $isi] = [$text('sex'), $text('status'), $text('cell'), $text('village'), $text('isibo')];
            $pass = (string)(body()['pass'] ?? '');
            if (count(explode(' ', $name)) < 2) fail('Andika amazina yombi');
            if (!preg_match('/^07[2389]\d{7}$/', $phone)) fail('Nimero ya telefone si yo (urugero 0788123456)');
            if (!in_array($sex, $sexes, true)) fail('Hitamo umugabo cyangwa umugore');
            if (!in_array($cat, $cats, true)) fail('Hitamo icyiciro');
            $checkGeo($cell, $vil, $isi);
            if (!pw_ok($pass)) fail(PW_MSG);
            if ($pass !== (string)(body()['pass2'] ?? '')) fail('Amajambobanga ntabwo ahuye');
            if (q('select 1 from users where `user`=?', [$phone])->fetch()) fail('Iyi nimero isanzwe ifite konti');
            q("insert into login_attempts(ident,ip) values('reg',?)", [$ip]);
            q("insert into users(name,phone,role,cell,`user`,pass,status,sex,village,isibo) values(?,?,'citizen',?,?,?,?,?,?,?)",
              [$name, $phone, $cell, $phone, password_hash($pass, PASSWORD_DEFAULT), $cat, $sex, $vil, $isi]);
            $u = q('select * from users where id=?', [db()->lastInsertId()])->fetch();
            lg($name, "Yiyandikishije: $cell / $vil / $isi");
            $sms = sms_send($phone, "Muraho $name, mwiyandikishije neza muri $cell, Umurenge wa " . sector_name() . ", Akarere ka Muhanga. Injira ukoresheje nimero yawe ($phone) n'ijambobanga mwahisemo. Murakoze.");
            out(login_as($u) + ['sms' => $sms]);
        case 'POST password':
            $s = need(); $u = q('select * from users where id=?', [$s['id']])->fetch();
            $new = (string)(body()['new'] ?? '');
            if (!password_verify((string)(body()['old'] ?? ''), $u['pass']) || !pw_ok($new) || $new === (string)(body()['old'] ?? '')) fail('Irya kera siryo, cyangwa irishya ntiryujuje ibisabwa (nibura 8, inyuguti n’imibare, ritandukanye n’irya kera)');
            q('update users set pass=?, must_change=0 where id=?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            lg($u['name'], 'Yahinduye ijambobanga'); $_SESSION['must'] = must_state(array_merge($u, ['must_change' => 0])); out(['must' => $_SESSION['must']]);
        case 'PUT profile':
            $s = need(); $name = preg_replace('/\s+/', ' ', $text('name'));
            if (!$name) fail('Andika amazina');
            if ($s['role'] === 'citizen') {
                $phone = norm_phone($text('phone'));
                [$sex, $cat, $cell, $vil, $isi] = [$text('sex'), $text('status'), $text('cell'), $text('village'), $text('isibo')];
                if (count(explode(' ', $name)) < 2) fail('Andika amazina yombi');
                if (!preg_match('/^07[2389]\d{7}$/', $phone)) fail('Nimero ya telefone si yo');
                if (!in_array($sex, $sexes, true) || !in_array($cat, $cats, true)) fail('Hitamo igitsina n’icyiciro');
                $checkGeo($cell, $vil, $isi);
                if (q('select 1 from users where `user`=? and id<>?', [$phone, $s['id']])->fetch()) fail('Iyi nimero ikoreshwa n’undi muntu');
                q('update users set name=?,phone=?,`user`=?,sex=?,status=?,cell=?,village=?,isibo=? where id=?', [$name, $phone, $phone, $sex, $cat, $cell, $vil, $isi, $s['id']]);
            } else {
                $ph = norm_phone($text('phone')); $em = mb_strtolower($text('email'));
                $cur = q('select email,pass from users where id=?', [$s['id']])->fetch();
                if (priv($s['role']) && !filter_var($em, FILTER_VALIDATE_EMAIL)) fail('Shyiramo email nyayo (yo kwakira kode yo kwinjira)');
                if ($em !== '' && q('select 1 from users where email=? and id<>?', [$em, $s['id']])->fetch()) fail('Iyi email ikoreshwa n’undi muntu');
                if (!empty($cur['email']) && $em !== mb_strtolower($cur['email']) && !password_verify((string)(body()['pass'] ?? ''), $cur['pass'])) fail('Shyiramo ijambobanga ryawe kugira ngo uhindure email');
                if ($ph !== '' && q('select 1 from users where (phone=? or `user`=?) and id<>?', [$ph, $ph, $s['id']])->fetch()) fail('Iyi nimero ikoreshwa n’undi muntu');
                q('update users set name=?,phone=?,email=? where id=?', [$name, $ph, ($em ?: null), $s['id']]);
            }
            $u = q('select * from users where id=?', [$s['id']])->fetch();
            $_SESSION['u'] = meOf($u); $_SESSION['must'] = must_state($u); lg($u['name'], 'Yahinduye profile');
            out(['csrf' => $_SESSION['csrf'], 'me' => $_SESSION['u'], 'must' => $_SESSION['must']]);
        case 'POST profile':                                   // ifoto y'umuturage
            $s = need(); $f = upload('photo');
            if (!$f || !preg_match('/\.(jpg|png|webp|gif)$/', $f)) { rmfile($f); fail('Hitamo ifoto (jpg, png cyangwa webp)'); }
            rmfile(q('select photo from users where id=?', [$s['id']])->fetchColumn() ?: null);
            q('update users set photo=? where id=?', [$f, $s['id']]);
            $u = q('select * from users where id=?', [$s['id']])->fetch(); $_SESSION['u'] = meOf($u); lg($u['name'], 'Yashyizeho ifoto');
            out(['csrf' => $_SESSION['csrf'], 'me' => $_SESSION['u']]);
        case 'GET geo': out($geo());
        case 'POST geo':
            $s = need('admin'); [$cell, $vil, $isi] = [$text('cell'), $text('village'), $text('isibo')];
            if (!in_array($cell, $cells(), true) || !$vil) fail('Uzuza byose');
            if ($isi === '') q('insert ignore into villages(cell,name) values(?,?)', [$cell, $vil]);
            else {
                $v = q('select id from villages where cell=? and name=?', [$cell, $vil])->fetch(); if (!$v) fail('Umudugudu ntubaho');
                q('insert ignore into isibo(village_id,name) values(?,?)', [$v['id'], $isi]);
            }
            lg($s['name'], "Yongeyeho: $cell / $vil" . ($isi !== '' ? " / $isi" : '')); out([]);
        case 'DELETE villages':
            $s = need('admin'); q('delete from isibo where village_id=?', [$id]); q('delete from villages where id=?', [$id]); lg($s['name'], "Yasibye umudugudu #$id"); out([]);
        case 'DELETE isibo':
            $s = need('admin'); q('delete from isibo where id=?', [$id]); lg($s['name'], "Yasibye isibo #$id"); out([]);

        // ---------- Utugari, abakoresha, ibigaragara ----------
        case 'GET cells': out($cells());
        case 'POST cells':
            $s = need('admin'); if (!$text('name')) fail('Andika izina');
            q('insert ignore into cells values(?)', [$text('name')]); lg($s['name'], 'Yongeyeho akagari ' . $text('name')); out([]);
        case 'GET stats':
            need('admin');
            out(['roles' => q('select role k,count(*) n from users group by role')->fetchAll(),
                 'cells' => q("select c.name k,(select count(*) from users where role='citizen' and cell=c.name) n from cells c")->fetchAll(),
                 'fb' => (int)q('select count(*) from fb')->fetchColumn(), 'reports' => (int)q('select count(*) from reports')->fetchColumn()]);
        case 'GET users':
            $s = need('admin', 'exec', 'worker'); $cols = 'id,name,duty,phone,email,role,cell,`user`,age,status,sex,village,isibo,photo,at';
            out($s['role'] === 'admin' ? q("select $cols from users where role=?", [$_GET['role'] ?? ''])->fetchAll()
                                       : q("select $cols from users where role=? and cell=?", [$_GET['role'] ?? '', $s['cell']])->fetchAll());
        case 'POST users':
            $s = need('admin', 'exec');
            if ($id && $act === 'reset') {
                if ($s['role'] !== 'admin' || !pw_ok((string)(body()['new'] ?? ''))) fail(PW_MSG);
                q('update users set pass=?, must_change=1 where id=?', [password_hash(body()['new'], PASSWORD_DEFAULT), $id]);   // umukoresha ahatirwa kurihindura
                lg($s['name'], "Yahinduriye ijambobanga umukoresha #$id"); out([]);
            }
            $role = $s['role'] === 'exec' ? 'worker' : $text('role');
            if (cur_sector() === 'district' && !in_array($role, ['admin', 'staff'], true)) fail('Mu Karere hashobora admin na staff gusa');
            if ($role === 'admin' && (int)q("select count(*) from users where role='admin'")->fetchColumn() >= 6) fail('Admin ntibarenze 6: umwe wa mbere n’abandi batanu');
            $cell = $role === 'worker' ? $s['cell'] : ($role === 'exec' ? $text('cell') : '');
            if (!in_array($role, $s['role'] === 'admin' ? ['admin', 'staff', 'exec', 'worker'] : ['staff', 'exec', 'worker'], true) || !$text('name') || !$text('duty') || !$text('user') || !pw_ok((string)(body()['pass'] ?? ''))) fail('Uzuza byose: amazina, icyo ashinzwe, username n’ijambobanga (nibura 6)');
            if ($role === 'exec' && !in_array($cell, $cells(), true)) fail('Hitamo akagari');
            $ph = norm_phone($text('phone')); $em = mb_strtolower($text('email'));   // email isabwa abayobozi bose (kode yo kwinjira); telefone ntibigombwa
            if (priv($role) && !filter_var($em, FILTER_VALIDATE_EMAIL)) fail('Shyiramo email nyayo y’uyu muntu (yo kwakira kode yo kwinjira)');
            if ($em !== '' && q('select 1 from users where email=?', [$em])->fetch()) fail('Iyi email isanzwe ikoreshwa');
            if ($ph !== '' && q('select 1 from users where phone=? or `user`=?', [$ph, $ph])->fetch()) fail('Iyi telefone isanzwe ikoreshwa');
            if (q('select 1 from users where `user`=?', [$text('user')])->fetch()) fail('Izina ryafashwe');
            q('insert into users(name,duty,phone,email,role,cell,`user`,pass,must_change) values(?,?,?,?,?,?,?,?,1)', [$text('name'), $text('duty'), $ph, ($em ?: null), $role, $cell, $text('user'), password_hash(body()['pass'], PASSWORD_DEFAULT)]);
            lg($s['name'], "Yongeyeho $role: " . $text('name')); out([]);
        case 'DELETE users':
            $s = need('admin', 'exec'); $t = q('select * from users where id=?', [$id])->fetch();
            $first = (int)q("select min(id) from users where role='admin'")->fetchColumn();   // admin wa mbere ntasibwa; nta n'umuntu wisiba
            if (!$t || ($t['role'] === 'admin' && ($s['role'] !== 'admin' || (int)$t['id'] === $s['id'] || (int)$t['id'] === $first)) || ($s['role'] === 'exec' && !($t['role'] === 'worker' && $t['cell'] === $s['cell']))) fail('Ntiwemerewe', 403);
            q('delete from users where id=?', [$id]); lg($s['name'], 'Yakuyeho ' . $t['name']); out([]);

        // ---------- Amakuru ----------
        case 'GET news':
            $s = need();
            if ($id && $act === 'views') {                       // abayibonye (nyirayo na admin gusa)
                mine('news', $s, $id);
                out(q('select u.name,u.role,u.cell,v.at from news_views v join users u on u.id=v.uid where v.news_id=? order by v.at desc', [$id])->fetchAll());
            }
            if ($id && $act === 'comments') {
                $x = q('select * from news where id=?', [$id])->fetch();
                if (!$x || !$sees($s, $x)) fail('Ntiwemerewe', 403);
                out(q('select * from news_comments where news_id=? order by id', [$id])->fetchAll());
            }
            $views = array_column(q('select news_id k,count(*) n from news_views group by news_id')->fetchAll(), 'n', 'k');
            $coms  = array_column(q('select news_id k,count(*) n from news_comments group by news_id')->fetchAll(), 'n', 'k');
            $seen  = array_column(q('select news_id k,1 n from news_views where uid=?', [$s['id']])->fetchAll(), 'n', 'k');
            $list = [];
            foreach (q('select * from news order by id desc')->fetchAll() as $n) {
                if (!$sees($s, $n)) continue;
                $own = $s['role'] === 'admin' || (int)$n['uid'] === $s['id'];
                $list[] = $n + ['views' => (int)($views[$n['id']] ?? 0), 'comments' => (int)($coms[$n['id']] ?? 0),
                                'seen' => isset($seen[$n['id']]), 'target' => $own ? $target($n) : 0];
            }
            if (cur_sector() !== 'district') {                           // matangazo y'Akarere agaragara hamwe n'ay'umurenge
                $g = $s['role'] === 'admin' ? null : ($s['role'] === 'worker' ? 'exec' : $s['role']);
                try {
                    foreach (qs('district', 'select * from news order by id desc limit 50')->fetchAll() as $n) {
                        $secs = array_filter(explode(',', (string)($n['sectors'] ?? '')));
                        if ($secs && !in_array(cur_sector(), $secs, true)) continue;
                        if ($g !== null && !in_array($g, explode(',', $n['aud']), true)) continue;
                        $list[] = ['id' => 'd' . $n['id'], 'src' => 'district', 't' => $n['t'], 'b' => $n['b'], 'file' => $n['file'], 'at' => $n['at'], 'cell' => '', 'aud' => $n['aud'],
                                   'uid' => 0, 'views' => 0, 'comments' => 0, 'comments_on' => 0, 'seen' => true, 'target' => 0];
                    }
                } catch (Throwable $e) { /* database y'Akarere ntiboneka */ }
                usort($list, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
            }
            out($list);
        case 'POST news':
            $s = need();
            if ($id && $act === 'view') {                        // andika ko umuntu yabonye amakuru (umwe rimwe gusa)
                $x = q('select * from news where id=?', [$id])->fetch();
                if ($x && $s['role'] !== 'admin' && (int)$x['uid'] !== $s['id'] && $sees($s, $x))
                    q('insert ignore into news_views(news_id,uid) values(?,?)', [$id, $s['id']]);
                out([]);
            }
            if ($id && $act === 'comments') {
                $x = q('select * from news where id=?', [$id])->fetch();
                if (!$x || !$sees($s, $x)) fail('Ntiwemerewe', 403);
                if (!$x['comments_on']) fail('Ibitekerezo kuri aya makuru byarafunzwe');
                if (!$text('b')) fail('Andika igitekerezo');
                q('insert into news_comments(news_id,uid,u,role,b) values(?,?,?,?,?)', [$id, $s['id'], $s['name'], $s['role'], $text('b')]);
                out([]);
            }
            if ($id && $act === 'lock') {
                $s = need('admin', 'staff', 'exec'); mine('news', $s, $id);
                q('update news set comments_on=1-comments_on where id=?', [$id]); out([]);
            }
            $s = need('admin', 'staff', 'exec'); if (!$text('t')) fail('Andika umutwe');
            if ($s['role'] === 'exec') { $aud = 'citizen,exec'; $cell = $s['cell']; }     // abaturage n'abakozi b'akagari ke
            else {
                $aud = implode(',', array_intersect(['staff', 'exec', 'citizen'], explode(',', $text('aud'))));
                if (!$aud) fail('Hitamo aho amakuru yoherezwa');
                $cell = $text('cell');                                   // admin na staff bahitamo aho yoherezwa
                if ($cell !== '' && !in_array($cell, $cells(), true)) fail('Akagari ntikabaho');
            }
            $secs = '';
            if (cur_sector() === 'district') {                           // itangazo ry'Akarere rigera ku mirenge wahisemo
                $secs = implode(',', array_intersect(array_keys($C['sectors']), explode(',', $text('sectors'))));
                if ($secs === '') fail('Hitamo nibura umurenge umwe');
            }
            $file = upload('file');                                      // ifoto cyangwa video (ntibigombwa)
            q('insert into news(t,b,cell,uid,aud,comments_on,file,sectors) values(?,?,?,?,?,?,?,?)', [$text('t'), $text('b'), $cell, $s['id'], $aud, $text('comments_on') === '1' && cur_sector() !== 'district' ? 1 : 0, $file, $secs]);
            lg($s['name'], 'Yatangaje amakuru: ' . $text('t') . " ($aud)"); out([]);
        case 'PUT news':
            $s = need('admin', 'staff', 'exec'); $x = mine('news', $s, $id);
            q('update news set t=?,b=? where id=?', [$text('t'), $text('b'), $id]); lg($s['name'], 'Yahinduye amakuru: ' . $x['t']); out([]);
        case 'DELETE news':
            $s = need('admin', 'staff', 'exec'); $x = mine('news', $s, $id); rmfile($x['file'] ?? null);
            q('delete from news_views where news_id=?', [$id]); q('delete from news_comments where news_id=?', [$id]);
            q('delete from news where id=?', [$id]); lg($s['name'], 'Yasibye amakuru: ' . $x['t']); out([]);
        case 'DELETE comments':
            $s = need();
            $c = q('select c.*,n.uid nuid from news_comments c join news n on n.id=c.news_id where c.id=?', [$id])->fetch();
            if (!$c || !($s['role'] === 'admin' || (int)$c['uid'] === $s['id'] || (int)$c['nuid'] === $s['id'])) fail('Ntiwemerewe', 403);
            q('delete from news_comments where id=?', [$id]); out([]);

        // ---------- Serivisi ----------
        case 'GET services':
            $s = need();
            out($s['role'] === 'admin' ? q('select * from services order by id desc')->fetchAll()
                                       : q("select * from services where cell='' or cell=? order by id desc", [$s['cell']])->fetchAll());
        case 'POST services':
            $s = need('admin', 'exec'); if (!$text('name')) fail('Andika izina rya serivisi');
            q('insert into services(cell,name,d,req,fee,hours,uid) values(?,?,?,?,?,?,?)',
              [$s['role'] === 'exec' ? $s['cell'] : $text('cell'), $text('name'), $text('d'), $text('req'), $text('fee'), $text('hours'), $s['id']]);
            lg($s['name'], 'Yongeyeho serivisi: ' . $text('name')); out([]);
        case 'PUT services':
            $s = need('admin', 'exec'); $x = mine('services', $s, $id);
            q('update services set name=?,d=?,req=?,fee=?,hours=? where id=?', [$text('name'), $text('d'), $text('req'), $text('fee'), $text('hours'), $id]);
            lg($s['name'], 'Yahinduye serivisi: ' . $x['name']); out([]);
        case 'DELETE services':
            $s = need('admin', 'exec'); $x = mine('services', $s, $id);
            q('delete from services where id=?', [$id]); lg($s['name'], 'Yasibye serivisi: ' . $x['name']); out([]);

        // ---------- Ibitekerezo ----------
        case 'GET fb':      // staff na admin babona byose hamwe n'amakuru y'uwabitanze (telefone, umudugudu, isibo)
            $s = need('admin', 'staff', 'exec', 'worker', 'citizen');
            $sel = 'select f.*,u.phone,u.village,u.isibo from fb f left join users u on u.id=f.uid';
            out(in_array($s['role'], ['admin', 'staff'], true) ? q("$sel order by f.id desc")->fetchAll()
                : ($s['role'] === 'citizen' ? q("$sel where f.uid=? order by f.id desc", [$s['id']])->fetchAll()
                                            : q("$sel where f.cell=? order by f.id desc", [$s['cell']])->fetchAll()));
        case 'POST fb':
            if ($id && $act === 'reply') {
                $s = need('admin', 'staff', 'exec', 'worker'); $x = q('select * from fb where id=?', [$id])->fetch();   // admin na staff basubiza byose; executive n'umukozi ibyo mu kagari kabo
                if (!$x || (!in_array($s['role'], ['admin', 'staff'], true) && $x['cell'] !== $s['cell'])) fail('Ntiwemerewe', 403);
                q('update fb set reply=?,rby=?,reply_at=NOW() where id=?', [$text('reply'), $s['name'], $id]); lg($s['name'], 'Yasubije ' . $x['u']); out([]);
            }
            $s = need('citizen'); if (!$text('b')) fail('Andika ubutumwa');
            $k = in_array($text('k'), ['Igitekerezo', 'Icyifuzo', 'Ikibazo'], true) ? $text('k') : 'Igitekerezo';
            q('insert into fb(uid,u,cell,k,b,file) values(?,?,?,?,?,?)', [$s['id'], $s['name'], $s['cell'], $k, $text('b'), upload('file')]);
            lg($s['name'], "Yatanze $k"); out([]);
        case 'PUT fb':
            $s = need('citizen'); mine('fb', $s, $id);
            q('update fb set b=? where id=?', [$text('b'), $id]); lg($s['name'], 'Yahinduye igitekerezo'); out([]);
        case 'DELETE fb':
            $s = need('citizen', 'admin'); $x = mine('fb', $s, $id);
            q('delete from fb where id=?', [$id]); rmfile($x['file']); lg($s['name'], 'Yasibye ' . $x['k']); out([]);

        // ---------- Raporo ----------
        case 'GET reports':
            $s = need('admin', 'staff');
            out($s['role'] === 'admin' ? q('select * from reports order by id desc')->fetchAll()
                                       : q('select * from reports where uid=? or (uid is null and u=?) order by id desc', [$s['id'], $s['name']])->fetchAll());
        case 'POST reports':
            $s = need('staff'); if (!$text('t')) fail('Andika umutwe');
            q('insert into reports(uid,u,t,b,file) values(?,?,?,?,?)', [$s['id'], $s['name'], $text('t'), $text('b'), upload('file')]);
            lg($s['name'], 'Yatanze raporo: ' . $text('t')); out([]);
        case 'PUT reports':
            $s = need('admin', 'staff'); $x = mine('reports', $s, $id);
            q('update reports set t=?,b=? where id=?', [$text('t'), $text('b'), $id]); lg($s['name'], 'Yahinduye raporo: ' . $x['t']); out([]);
        case 'DELETE reports':
            $s = need('admin', 'staff'); $x = mine('reports', $s, $id);
            q('delete from reports where id=?', [$id]); rmfile($x['file']); lg($s['name'], 'Yasibye raporo: ' . $x['t']); out([]);

        // ---------- Inama (video ikorwa na Jitsi) ----------
        case 'GET dmeets':                                     // inama zateguwe n'Akarere: staff y'Akarere + admin b'imirenge yose
            $s = need('admin', 'staff'); $dist = cur_sector() === 'district';
            if (!$dist && $s['role'] !== 'admin') fail('Ntiwemerewe', 403);
            if ($id && $act === 'room') {
                $x = qs('district', 'select * from meets where id=?', [$id])->fetch();
                if (!$x || !$x['live'] || (!$dist && $x['a'] !== 'leaders')) fail('Inama ntiriho cyangwa ntiwemerewe', 403);
                out(['domain' => $C['jitsi'], 'name' => $s['name'] . ' (' . ($dist ? 'Akarere' : sector_name()) . ')', 'room' => 'muhanga-karere-' . substr(hash_hmac('sha256', "dmeet$id", $C['jitsi_secret']), 0, 20)]);
            }
            out(array_values(array_filter(qs('district', 'select * from meets order by id desc limit 100')->fetchAll(), fn($x) => $dist || $x['a'] === 'leaders')));
        case 'GET meets':
            $s = need('admin', 'staff', 'exec');
            if ($id && $act === 'room') {
                $x = q('select * from meets where id=?', [$id])->fetch();
                if (!$x || !$x['live'] || !$canMeet($s, $x)) fail('Inama ntiriho cyangwa ntiwemerewe', 403);
                out(['domain' => $C['jitsi'], 'name' => $s['name'], 'room' => 'muhanga-' . substr(hash_hmac('sha256', "meet" . cur_sector() . "-$id", $C['jitsi_secret']), 0, 20)]);
            }
            out(array_values(array_filter(q('select * from meets order by id desc')->fetchAll(), fn($x) => $canMeet($s, $x))));
        case 'POST meets':
            $s = need('admin');
            if ($id && $act === 'toggle') { q('update meets set live=1-live where id=?', [$id]); lg($s['name'], "Yahinduye live y'inama #$id"); out([]); }
            if (!$text('t') || !in_array($text('a'), ['staff', 'exec', 'all', 'leaders'], true)) fail('Uzuza byose');
            if ($text('a') === 'leaders' && cur_sector() !== 'district') fail('Inama y’abayobozi iteguwe n’Akarere gusa');
            $st = $text('start'); $starts = ($st !== '' && strtotime($st)) ? gmdate('Y-m-d H:i:s', strtotime($st)) : null;   // itariki n'isaha (UTC)
            q('insert into meets(t,a,starts) values(?,?,?)', [$text('t'), $text('a'), $starts]); lg($s['name'], 'Yateguye inama: ' . $text('t')); out([]);
        case 'DELETE meets':
            $s = need('admin'); q('delete from meets where id=?', [$id]); lg($s['name'], "Yasibye inama #$id"); out([]);

        // ---------- Chat: Staff (Admin+Staff), Ba Executive (Admin+Executive) n'Abayobozi (Admin z'imirenge yose + Akarere) ----------
        case 'GET chat':
            $s = need('admin', 'staff', 'exec'); $room = $chatRoom($s, (string)($_GET['room'] ?? '')); $after = (int)($_GET['after'] ?? 0); $upd = (string)($_GET['upd'] ?? '');
            out($after > 0 && $upd !== '' ? $cq($room, 'select * from chat where room=? and (id>? or upd>=?) order by id limit 200', [$room, $after, $upd])->fetchAll()
                : ($after > 0 ? $cq($room, 'select * from chat where room=? and id>? order by id limit 200', [$room, $after])->fetchAll()
                              : $cq($room, 'select * from (select * from chat where room=? order by id desc limit 100) t order by id', [$room])->fetchAll()));
        case 'POST chat':
            $s = need('admin', 'staff', 'exec'); $room = $chatRoom($s, $text('room'));
            $kind = $text('kind') === 'call' ? 'call' : 'text';
            $file = $kind === 'text' ? upload('file') : '';
            $b = $kind === 'call' ? ($text('b') === 'audio' ? 'audio' : 'video') : mb_substr($text('b'), 0, 1000);
            if ($b === '' && !$file) fail('Andika ubutumwa cyangwa uhitemo ifoto/video');
            $label = $room === 'leaders' ? $s['name'] . ' · ' . (cur_sector() === 'district' ? 'Akarere' : sector_name()) : $s['name'];
            $cq($room, 'insert into chat(room,uid,u,role,kind,b,file,sec) values(?,?,?,?,?,?,?,?)', [$room, $s['id'], $label, $s['role'], $kind, $b, $file, $csec($room)]); out([]);
        case 'PUT chat':                                       // guhindura ubutumwa bwawe (mu minota 15)
            $s = need('admin', 'staff', 'exec'); $room = $chatRoom($s, (string)($_GET['room'] ?? $text('room')));
            $x = $cq($room, 'select * from chat where id=? and room=?', [$id, $room])->fetch();
            if (!$x || (int)$x['uid'] !== $s['id'] || $x['sec'] !== $csec($room) || $x['deleted'] || $x['kind'] !== 'text') fail('Ntiwemerewe', 403);
            if (time() - strtotime($x['at'] . ' UTC') > 900) fail('Guhindura bishoboka mu minota 15 nyuma yo kohereza');
            $b = mb_substr($text('b'), 0, 1000); if ($b === '') fail('Andika ubutumwa');
            $cq($room, 'update chat set b=?,edited=1 where id=?', [$b, $id]); out([]);
        case 'DELETE chat':                                    // gusiba nka WhatsApp: "Ubu butumwa bwasibwe"
            $s = need('admin', 'staff', 'exec'); $room = $chatRoom($s, (string)($_GET['room'] ?? $text('room')));
            $x = $cq($room, 'select * from chat where id=? and room=?', [$id, $room])->fetch();
            $owner = $x && (int)$x['uid'] === $s['id'] && $x['sec'] === $csec($room);
            $mod = $s['role'] === 'admin' && ($room !== 'leaders' || cur_sector() === 'district');
            if (!$x || (!$owner && !$mod)) fail('Ntiwemerewe', 403);
            rmfile($x['file']); $cq($room, "update chat set deleted=1,b='',file='' where id=?", [$id]); out([]);
        case 'GET chatroom':    // guhamagarana video/audio muri chat: Jitsi, icyumba cyihariye kuri buri chat
            $s = need('admin', 'staff', 'exec'); $room = $chatRoom($s, (string)($_GET['room'] ?? ''));
            out(['domain' => $C['jitsi'], 'name' => $s['name'], 'room' => 'muhanga-chat-' . substr(hash_hmac('sha256', "chat" . ($room === 'leaders' ? '' : cur_sector()) . "-$room", $C['jitsi_secret']), 0, 16)]);

        // ---------- ADMIN W'AKARERE: ibitekerezo by'imirenge yose, imibare, gusubiza ----------
        case 'GET dfb':
            $needDist(); $all = [];
            foreach ($C['sectors'] as $slug => $sec) {
                try {
                    foreach (qs($slug, 'select f.*,u.phone,u.village,u.isibo from fb f left join users u on u.id=f.uid order by f.id desc limit 200')->fetchAll() as $r) { $r['sector'] = $slug; $r['sector_name'] = $sec['name']; $all[] = $r; }
                } catch (Throwable $e) { /* database y'uyu murenge ntiraboneka */ }
            }
            usort($all, fn($a, $b) => strcmp($b['at'], $a['at'])); out(array_slice($all, 0, 400));
        case 'POST dfb':                                       // dfb/{id}/reply  { sector, reply }
            $s = $needDist(); $slug = $text('sector');
            if ($act !== 'reply' || !isset($C['sectors'][$slug])) fail('Ntibonetse', 404);
            $x = qs($slug, 'select * from fb where id=?', [$id])->fetch(); if (!$x) fail('Ntibonetse', 404);
            qs($slug, 'update fb set reply=?,rby=?,reply_at=NOW() where id=?', [$text('reply'), $s['name'] . ' (Akarere)', $id]);
            lg($s['name'], 'Yasubije ' . $x['u'] . ' (' . $C['sectors'][$slug]['name'] . ')'); out([]);
        case 'GET dstats':
            $needDist(); $rows = [];
            foreach ($C['sectors'] as $slug => $sec) {
                try {
                    $c = fn(string $sql) => (int)qs($slug, $sql)->fetchColumn();
                    $rows[] = ['slug' => $slug, 'name' => $sec['name'], 'citizens' => $c("select count(*) from users where role='citizen'"), 'cells' => $c('select count(*) from cells'),
                               'staff' => $c("select count(*) from users where role='staff'"), 'exec' => $c("select count(*) from users where role='exec'"),
                               'fb' => $c('select count(*) from fb'), 'open' => $c("select count(*) from fb where reply is null or reply=''")];
                } catch (Throwable $e) { $rows[] = ['slug' => $slug, 'name' => $sec['name'], 'error' => true]; }
            }
            out($rows);
        case 'GET log':
            need('admin'); out(q('select * from `log` order by id desc limit 200')->fetchAll());
        default:
            fail('Ntibonetse', 404);
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    fail('Ikosa muri seriveri', 500);
}
