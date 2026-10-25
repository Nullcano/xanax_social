<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

/* ---------- Reserveløsning hvis mbstring mangler på serveren ---------- */
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s): int { return preg_match_all('/./us', $s); }
    function mb_substr(string $s, int $start, ?int $len = null): string { return implode('', array_slice(preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY), $start, $len)); }
    function mb_strimwidth(string $s, int $start, int $width, string $trim = ''): string {
        return mb_strlen($s) <= $width ? $s : mb_substr($s, $start, $width - mb_strlen($trim)) . $trim;
    }
}

/* ---------- Database og svar ---------- */
function db(): PDO {
    static $p;
    return $p ??= new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function out($d, int $c = 200): never { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $m, int $c = 400): never { out(['error' => $m], $c); }

/* ---------- Innlogget bruker ---------- */
function me(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $u = q('SELECT * FROM users WHERE id=?', [$_SESSION['uid']])->fetch();
    if (!$u) return null;
    q('UPDATE users SET last_seen=NOW() WHERE id=? AND (last_seen IS NULL OR last_seen < NOW() - INTERVAL 60 SECOND)', [$u['id']]);
    return $u;
}
function need(): array { $u = me(); if (!$u) fail('Du må logge inn først', 401); return $u; }
function admin(): array { $u = need(); if ($u['role'] !== 'admin') fail('Kun for administratorer', 403); return $u; }
function text(array $in, string $k, int $min, int $max, string $label): string {
    $v = trim((string)($in[$k] ?? ''));
    $len = mb_strlen($v);
    if ($len < $min || $len > $max) fail("$label må være mellom $min og $max tegn");
    return $v;
}
function flood(string $table, int $uid): void {
    if (q("SELECT 1 FROM $table WHERE user_id=? AND created_at > NOW() - INTERVAL 10 SECOND", [$uid])->fetch())
        fail('Rolig, vent noen sekunder før du poster igjen', 429);
}

/* ---------- Gamification ---------- */
const LEVELS = [[0, 'Nykommer'], [50, 'Deltaker'], [150, 'Bidragsyter'], [300, 'Veteran'], [600, 'Mentor'], [1000, 'Legende'], [2000, 'Ikon']];
const TAGS = ['Diskusjon', 'Spørsmål', 'Showcase', 'Tips', 'Off-topic'];
const NEWS_TAGS = ['Kunngjøring', 'Oppdatering', 'Arrangement', 'Tips'];
// kode => [emoji, navn, beskrivelse, xp-belønning]
const ACH = [
    'welcome'      => ['👋', 'Velkommen', 'Opprettet konto', 5],
    'profile'      => ['🪪', 'Komplett profil', 'Fylte ut biografi, status og sted', 10],
    'first_thread' => ['✍️', 'Første tråd', 'Startet din første tråd', 10],
    'first_reply'  => ['💬', 'Første svar', 'Skrev ditt første svar', 5],
    'chatty'       => ['🗣️', 'Pratkjær', 'Skrev 25 svar', 25],
    'starter'      => ['🌱', 'Ildsjel', 'Startet 10 tråder', 30],
    'liked'        => ['👍', 'Populær', 'Fikk 10 likes', 20],
    'loved'        => ['❤️', 'Elsket', 'Fikk 50 likes', 50],
    'social'       => ['🤝', 'Sosial', 'Følger 5 brukere', 10],
    'known'        => ['🌟', 'Kjent', 'Har 10 følgere', 30],
    'explorer'     => ['🧭', 'Oppdager', 'Abonnerer på 5 kategorier', 15],
    'generous'     => ['🎁', 'Raus', 'Har sendt 3 gaver', 20],
    'valued'       => ['💝', 'Verdsatt', 'Har mottatt 3 gaver', 20],
    'streak7'      => ['🔥', 'Ukesstreak', 'Logget inn 7 dager på rad', 25],
    'streak30'     => ['🏅', 'Månedsstreak', 'Logget inn 30 dager på rad', 100],
    'level5'       => ['🏆', 'Mentor', 'Nådde nivå 5', 50],
];
function level(int $xp): array {
    $l = 0;
    foreach (LEVELS as $i => $t) if ($xp >= $t[0]) $l = $i;
    return ['level' => $l + 1, 'title' => LEVELS[$l][1], 'xp' => $xp, 'floor' => LEVELS[$l][0], 'next' => LEVELS[$l + 1][0] ?? null];
}
function pub(array $u): array {
    return ['id' => (int)$u['id'], 'username' => $u['username'], 'role' => $u['role']] + level((int)$u['xp']);
}
function notify(int $uid, string $type, ?int $actor, ?int $thread, string $text): void {
    if ($actor !== null && $actor === $uid) return;
    if ($actor !== null && q('SELECT 1 FROM notifications WHERE user_id=? AND type=? AND actor_id=? AND thread_id <=> ? AND created_at > NOW() - INTERVAL 1 DAY', [$uid, $type, $actor, $thread])->fetch()) return;
    q('INSERT INTO notifications (user_id,type,actor_id,thread_id,text) VALUES (?,?,?,?,?)', [$uid, $type, $actor, $thread, mb_substr($text, 0, 255)]);
}
function award(int $uid, int $xp, ?int $coins = null, string $why = ''): void {
    $before = (int)q('SELECT xp FROM users WHERE id=?', [$uid])->fetchColumn();
    q('UPDATE users SET xp=xp+?, coins=coins+? WHERE id=?', [$xp, $coins ?? $xp, $uid]);
    if ($xp > 0) q('INSERT INTO xp_log (user_id,xp,reason) VALUES (?,?,?)', [$uid, $xp, $why]);
    $a = level($before); $b = level($before + $xp);
    if ($b['level'] > $a['level']) notify($uid, 'levelup', null, null, "Du nådde nivå {$b['level']} – {$b['title']}");
}
function grant(int $uid, string $code): void {
    if (!isset(ACH[$code])) return;
    if (q('INSERT IGNORE INTO user_achievements (user_id,code) VALUES (?,?)', [$uid, $code])->rowCount()) {
        award($uid, ACH[$code][3], null, 'prestasjon');
        notify($uid, 'achievement', null, null, 'Ny prestasjon: ' . ACH[$code][0] . ' ' . ACH[$code][1]);
    }
}
function check_ach(int $uid): void {
    $c = q(<<<'SQL'
        SELECT u.xp, u.best_streak, u.bio, u.status, u.location,
          (SELECT COUNT(*) FROM threads WHERE user_id=u.id) threads,
          (SELECT COUNT(*) FROM posts WHERE user_id=u.id) posts,
          (SELECT COUNT(*) FROM follows WHERE follower_id=u.id) following,
          (SELECT COUNT(*) FROM follows WHERE followed_id=u.id) followers,
          (SELECT COUNT(*) FROM subscriptions WHERE user_id=u.id) subs,
          (SELECT COUNT(*) FROM user_gifts WHERE from_user=u.id) sent,
          (SELECT COUNT(*) FROM user_gifts WHERE to_user=u.id) got,
          (SELECT COUNT(*) FROM likes l WHERE l.active=1 AND l.user_id<>u.id AND (
             (l.kind='thread' AND l.target_id IN (SELECT id FROM threads WHERE user_id=u.id)) OR
             (l.kind='post' AND l.target_id IN (SELECT id FROM posts WHERE user_id=u.id)))) likes
        FROM users u WHERE u.id=?
SQL, [$uid])->fetch();
    if (!$c) return;
    $rules = [
        'profile' => $c['bio'] !== '' && $c['status'] !== '' && $c['location'] !== '',
        'first_thread' => $c['threads'] >= 1, 'first_reply' => $c['posts'] >= 1, 'chatty' => $c['posts'] >= 25,
        'starter' => $c['threads'] >= 10, 'liked' => $c['likes'] >= 10, 'loved' => $c['likes'] >= 50,
        'social' => $c['following'] >= 5, 'known' => $c['followers'] >= 10, 'explorer' => $c['subs'] >= 5,
        'generous' => $c['sent'] >= 3, 'valued' => $c['got'] >= 3,
        'streak7' => $c['best_streak'] >= 7, 'streak30' => $c['best_streak'] >= 30,
    ];
    foreach ($rules as $code => $ok) if ($ok) grant($uid, $code);
    if (level((int)q('SELECT xp FROM users WHERE id=?', [$uid])->fetchColumn())['level'] >= 5) grant($uid, 'level5');
}
function mentions(string $body, int $actor, int $thread, string $title): void {
    preg_match_all('/@([A-Za-z0-9_æøåÆØÅ]{3,20})/u', $body, $m);
    foreach (array_slice(array_unique($m[1]), 0, 5) as $name) {
        $u = q('SELECT id FROM users WHERE username=?', [$name])->fetch();
        if ($u) notify((int)$u['id'], 'mention', $actor, $thread, 'nevnte deg i «' . mb_strimwidth($title, 0, 60, '…') . '»');
    }
}
