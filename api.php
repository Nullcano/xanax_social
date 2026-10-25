<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
header('Content-Type: application/json; charset=utf-8');

$a = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))
    fail('Ugyldig økt. Last siden på nytt.', 403);

/** Delen av SELECT som gir antall likes og om innlogget bruker har likt. */
function like_cols(string $kind, string $alias, int $mid): array {
    return ["(SELECT COUNT(*) FROM likes l WHERE l.kind='$kind' AND l.target_id=$alias.id AND l.active=1) likes,
             EXISTS(SELECT 1 FROM likes l WHERE l.kind='$kind' AND l.target_id=$alias.id AND l.user_id=$mid AND l.active=1) liked"];
}
function can_edit(array $u, int $owner): bool { return $u['role'] === 'admin' || (int)$u['id'] === $owner; }

try {
switch ($a) {

/* ===== Økt og konto ===== */
case 'me':
    $u = me();
    $unread = $u ? (int)q('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0', [$u['id']])->fetchColumn() : 0;
    out(['csrf' => $_SESSION['csrf'], 'now' => q('SELECT NOW()')->fetchColumn(), 'user' => $u ? pub($u) + ['coins' => (int)$u['coins'], 'streak' => (int)$u['streak'], 'unread' => $unread] : null]);

case 'register':
    $name = trim((string)($in['username'] ?? ''));
    $email = trim((string)($in['email'] ?? ''));
    $pw = (string)($in['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_æøåÆØÅ]{3,20}$/u', $name)) fail('Brukernavn: 3–20 tegn, kun bokstaver, tall og _');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Ugyldig e-postadresse');
    if (strlen($pw) < 8) fail('Passordet må ha minst 8 tegn');
    if (q('SELECT 1 FROM users WHERE username=? OR email=?', [$name, $email])->fetch()) fail('Brukernavn eller e-post er allerede i bruk');
    $first = (int)q('SELECT COUNT(*) FROM users WHERE is_demo=0')->fetchColumn() === 0;
    q('INSERT INTO users (username,email,password_hash,role,xp,coins,streak,best_streak,last_daily,last_seen) VALUES (?,?,?,?,10,10,1,1,CURDATE(),NOW())',
      [$name, $email, password_hash($pw, PASSWORD_DEFAULT), $first ? 'admin' : 'user']);
    $id = (int)db()->lastInsertId();
    q('INSERT INTO xp_log (user_id,xp,reason) VALUES (?,10,?)', [$id, 'registrering']);
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
    grant($id, 'welcome');
    out(['ok' => true]);

case 'login':
    $id = trim((string)($in['login'] ?? ''));
    $u = q('SELECT * FROM users WHERE username=? OR email=?', [$id, $id])->fetch();
    if (!$u || !password_verify((string)($in['password'] ?? ''), $u['password_hash'])) fail('Feil brukernavn eller passord', 401);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    $d = q('SELECT CURDATE() today, CURDATE() - INTERVAL 1 DAY yest')->fetch();
    $bonus = 0; $streak = (int)$u['streak'];
    if ($u['last_daily'] !== $d['today']) {
        $streak = $u['last_daily'] === $d['yest'] ? $streak + 1 : 1;
        $bonus = min(5 + $streak, 15);
        q('UPDATE users SET streak=?, best_streak=GREATEST(best_streak,?), last_daily=CURDATE() WHERE id=?', [$streak, $streak, $u['id']]);
        award((int)$u['id'], $bonus, null, 'daglig');
        check_ach((int)$u['id']);
    }
    out(['ok' => true, 'bonus' => $bonus, 'streak' => $streak, 'csrf' => $_SESSION['csrf']]);

case 'logout':
    $_SESSION = [];
    session_destroy();
    out(['ok' => true]);

/* ===== Oversikt, søk, toppliste ===== */
case 'overview':
    out([
        'stats' => [
            'members' => (int)q('SELECT COUNT(*) FROM users')->fetchColumn(),
            'threads' => (int)q('SELECT COUNT(*) FROM threads')->fetchColumn(),
            'posts' => (int)q('SELECT COUNT(*) FROM posts')->fetchColumn(),
            'online' => (int)q('SELECT COUNT(*) FROM users WHERE last_seen > NOW() - INTERVAL 5 MINUTE')->fetchColumn(),
        ],
        'trending' => q("SELECT t.id, t.title, t.reply_count, t.tag, u.username, c.name cat, c.icon,
                (SELECT COUNT(*) FROM likes l WHERE l.kind='thread' AND l.target_id=t.id AND l.active=1) likes
            FROM threads t JOIN users u ON u.id=t.user_id JOIN categories c ON c.id=t.category_id
            WHERE t.created_at > NOW() - INTERVAL 30 DAY OR t.last_post_at > NOW() - INTERVAL 14 DAY
            ORDER BY (t.reply_count*2 + likes + t.views/20) DESC LIMIT 5")->fetchAll(),
    ]);

case 'search':
    $s = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($s) < 2) fail('Skriv minst 2 tegn');
    $like = '%' . addcslashes($s, '%_\\') . '%';
    out([
        'users' => q('SELECT username, xp, status FROM users WHERE username LIKE ? ORDER BY xp DESC LIMIT 8', [$like])->fetchAll(),
        'threads' => q('SELECT t.id, t.title, LEFT(t.body,160) excerpt, t.created_at, t.reply_count, u.username, c.name cat
            FROM threads t JOIN users u ON u.id=t.user_id JOIN categories c ON c.id=t.category_id
            WHERE t.title LIKE ? OR t.body LIKE ? ORDER BY t.last_post_at DESC LIMIT 20', [$like, $like])->fetchAll(),
    ]);

case 'leaderboard':
    if (($_GET['period'] ?? '') === 'week') {
        $rows = q('SELECT u.username, SUM(l.xp) xp, u.xp total FROM xp_log l JOIN users u ON u.id=l.user_id
                   WHERE l.created_at > NOW() - INTERVAL 7 DAY GROUP BY u.id ORDER BY xp DESC LIMIT 10')->fetchAll();
    } else {
        $rows = q('SELECT username, xp, xp total FROM users ORDER BY xp DESC LIMIT 10')->fetchAll();
    }
    foreach ($rows as &$r) { $r['xp'] = (int)$r['xp']; $r['title'] = level((int)$r['total'])['title']; unset($r['total']); }
    out($rows);

/* ===== Profiler og følging ===== */
case 'profile':
    $u = q('SELECT * FROM users WHERE username=?', [$_GET['username'] ?? ''])->fetch();
    if (!$u) fail('Fant ikke brukeren', 404);
    $id = (int)$u['id'];
    $viewer = me();
    $earned = q('SELECT code, earned_at FROM user_achievements WHERE user_id=?', [$id])->fetchAll(PDO::FETCH_KEY_PAIR);
    $ach = [];
    foreach (ACH as $code => [$emoji, $name, $desc, $xp])
        $ach[] = ['code' => $code, 'emoji' => $emoji, 'name' => $name, 'desc' => $desc, 'xp' => $xp, 'earned_at' => $earned[$code] ?? null];
    out([
        'user' => pub($u) + ['bio' => $u['bio'], 'status' => $u['status'], 'location' => $u['location'], 'website' => $u['website'],
            'joined' => $u['created_at'], 'streak' => (int)$u['streak'], 'best_streak' => (int)$u['best_streak'],
            'online' => $u['last_seen'] !== null && (int)q('SELECT ? > NOW() - INTERVAL 5 MINUTE', [$u['last_seen']])->fetchColumn() === 1],
        'followers' => (int)q('SELECT COUNT(*) FROM follows WHERE followed_id=?', [$id])->fetchColumn(),
        'following' => (int)q('SELECT COUNT(*) FROM follows WHERE follower_id=?', [$id])->fetchColumn(),
        'threads' => (int)q('SELECT COUNT(*) FROM threads WHERE user_id=?', [$id])->fetchColumn(),
        'posts' => (int)q('SELECT COUNT(*) FROM posts WHERE user_id=?', [$id])->fetchColumn(),
        'is_following' => $viewer ? (bool)q('SELECT 1 FROM follows WHERE follower_id=? AND followed_id=?', [$viewer['id'], $id])->fetch() : false,
        'achievements' => $ach,
        'gifts' => q('SELECT g.emoji, g.name, COUNT(*) n FROM user_gifts ug JOIN gifts g ON g.id=ug.gift_id WHERE ug.to_user=? GROUP BY g.id ORDER BY g.cost DESC', [$id])->fetchAll(),
        'gift_wall' => q('SELECT g.emoji, g.name, ug.message, ug.created_at, s.username sender FROM user_gifts ug JOIN gifts g ON g.id=ug.gift_id JOIN users s ON s.id=ug.from_user WHERE ug.to_user=? ORDER BY ug.id DESC LIMIT 12', [$id])->fetchAll(),
        'activity' => q("(SELECT 'thread' kind, id thread_id, title, created_at FROM threads WHERE user_id=:u)
            UNION ALL (SELECT 'reply', th.id, th.title, p.created_at FROM posts p JOIN threads th ON th.id=p.thread_id WHERE p.user_id=:u)
            ORDER BY created_at DESC LIMIT 12", [':u' => $id])->fetchAll(),
    ]);

case 'follow_list':
    $t = q('SELECT id FROM users WHERE username=?', [$_GET['username'] ?? ''])->fetch();
    if (!$t) fail('Fant ikke brukeren', 404);
    $viewer = me();
    $followers = ($_GET['type'] ?? '') === 'followers';
    $join = $followers ? 'f.follower_id' : 'f.followed_id';
    $where = $followers ? 'f.followed_id' : 'f.follower_id';
    $rows = q("SELECT u.username, u.xp, u.status,
            EXISTS(SELECT 1 FROM follows f2 WHERE f2.follower_id=:v AND f2.followed_id=u.id) is_following
        FROM follows f JOIN users u ON u.id=$join WHERE $where=:t ORDER BY f.created_at DESC LIMIT 100",
        [':v' => $viewer['id'] ?? 0, ':t' => $t['id']])->fetchAll();
    foreach ($rows as &$r) { $r['title'] = level((int)$r['xp'])['title']; unset($r['xp']); $r['is_following'] = (bool)$r['is_following']; }
    out($rows);

case 'update_profile':
    $u = need();
    $bio = text($in, 'bio', 0, 500, 'Biografien');
    $status = text($in, 'status', 0, 80, 'Statusen');
    $loc = text($in, 'location', 0, 60, 'Stedet');
    $web = text($in, 'website', 0, 120, 'Nettsiden');
    if ($web !== '' && (!preg_match('~^https?://~i', $web) || !filter_var($web, FILTER_VALIDATE_URL))) fail('Nettsiden må starte med http:// eller https://');
    q('UPDATE users SET bio=?, status=?, location=?, website=? WHERE id=?', [$bio, $status, $loc, $web, $u['id']]);
    check_ach((int)$u['id']);
    out(['ok' => true]);

case 'follow':
    $u = need();
    $target = (int)($in['user_id'] ?? 0);
    if ($target === (int)$u['id']) fail('Du kan ikke følge deg selv');
    if (!q('SELECT 1 FROM users WHERE id=?', [$target])->fetch()) fail('Fant ikke brukeren', 404);
    $has = q('SELECT 1 FROM follows WHERE follower_id=? AND followed_id=?', [$u['id'], $target])->fetch();
    if ($has) q('DELETE FROM follows WHERE follower_id=? AND followed_id=?', [$u['id'], $target]);
    else {
        q('INSERT INTO follows (follower_id, followed_id) VALUES (?,?)', [$u['id'], $target]);
        notify($target, 'follow', (int)$u['id'], null, 'begynte å følge deg');
        check_ach($target); check_ach((int)$u['id']);
    }
    out(['following' => !$has]);

/* ===== Kategorier og tråder ===== */
case 'categories':
    $u = me();
    out(q('SELECT c.id, c.name, c.description, c.icon,
             (SELECT COUNT(*) FROM threads t WHERE t.category_id=c.id) threads,
             (SELECT COUNT(*) FROM subscriptions s WHERE s.category_id=c.id) subscribers,
             EXISTS(SELECT 1 FROM subscriptions s WHERE s.category_id=c.id AND s.user_id=?) subscribed,
             (SELECT t.id FROM threads t WHERE t.category_id=c.id ORDER BY t.last_post_at DESC LIMIT 1) last_id,
             (SELECT t.title FROM threads t WHERE t.category_id=c.id ORDER BY t.last_post_at DESC LIMIT 1) last_title,
             (SELECT t.last_post_at FROM threads t WHERE t.category_id=c.id ORDER BY t.last_post_at DESC LIMIT 1) last_at
           FROM categories c ORDER BY c.sort_order, c.id', [$u['id'] ?? 0])->fetchAll());

case 'subscribe':
    $u = need();
    $cid = (int)($in['category_id'] ?? 0);
    if (!q('SELECT 1 FROM categories WHERE id=?', [$cid])->fetch()) fail('Fant ikke kategorien', 404);
    $has = q('SELECT 1 FROM subscriptions WHERE user_id=? AND category_id=?', [$u['id'], $cid])->fetch();
    if ($has) q('DELETE FROM subscriptions WHERE user_id=? AND category_id=?', [$u['id'], $cid]);
    else { q('INSERT INTO subscriptions (user_id, category_id) VALUES (?,?)', [$u['id'], $cid]); check_ach((int)$u['id']); }
    out(['subscribed' => !$has]);

case 'threads':
    $cid = (int)($_GET['category_id'] ?? 0);
    $cat = q('SELECT * FROM categories WHERE id=?', [$cid])->fetch();
    if (!$cat) fail('Fant ikke kategorien', 404);
    $u = me();
    $page = max(0, (int)($_GET['page'] ?? 0));
    $order = ['ny' => 't.created_at DESC', 'aktive' => 't.last_post_at DESC',
              'populaere' => '(t.reply_count*2 + likes) DESC, t.last_post_at DESC'][$_GET['sort'] ?? ''] ?? 't.last_post_at DESC';
    $where = 't.category_id=?'; $params = [$cid];
    if (in_array($_GET['tag'] ?? '', TAGS, true)) { $where .= ' AND t.tag=?'; $params[] = $_GET['tag']; }
    $rows = q("SELECT t.id, t.title, t.tag, t.pinned, t.locked, t.views, t.reply_count, t.created_at, t.last_post_at, u.username,
                (SELECT COUNT(*) FROM likes l WHERE l.kind='thread' AND l.target_id=t.id AND l.active=1) likes
            FROM threads t JOIN users u ON u.id=t.user_id WHERE $where
            ORDER BY t.pinned DESC, $order LIMIT 26 OFFSET " . ($page * 25), $params)->fetchAll();
    out([
        'category' => $cat + ['subscribed' => $u ? (bool)q('SELECT 1 FROM subscriptions WHERE user_id=? AND category_id=?', [$u['id'], $cid])->fetch() : false],
        'threads' => array_slice($rows, 0, 25), 'has_more' => count($rows) > 25, 'tags' => TAGS,
    ]);

case 'thread':
    $mid = (int)(me()['id'] ?? 0);
    $page = max(0, (int)($_GET['page'] ?? 0));
    [$lc] = like_cols('thread', 't', $mid);
    if ($page === 0 && empty($_GET['nocount'])) q('UPDATE threads SET views=views+1 WHERE id=?', [$_GET['id'] ?? 0]);
    $t = q("SELECT t.id, t.category_id, t.user_id, t.title, t.body, t.tag, t.pinned, t.locked, t.views, t.reply_count, t.created_at, t.edited_at,
              u.username, u.xp, c.name cat, c.icon, $lc
            FROM threads t JOIN users u ON u.id=t.user_id JOIN categories c ON c.id=t.category_id WHERE t.id=?", [$_GET['id'] ?? 0])->fetch();
    if (!$t) fail('Fant ikke tråden', 404);
    [$lp] = like_cols('post', 'p', $mid);
    $posts = q("SELECT p.id, p.user_id, p.body, p.created_at, p.edited_at, u.username, u.xp, $lp
                FROM posts p JOIN users u ON u.id=p.user_id WHERE p.thread_id=? ORDER BY p.created_at, p.id LIMIT 20 OFFSET " . ($page * 20), [$t['id']])->fetchAll();
    $t['title_level'] = level((int)$t['xp'])['title']; unset($t['xp']);
    foreach ($posts as &$p) { $p['title_level'] = level((int)$p['xp'])['title']; unset($p['xp']); }
    out(['thread' => $t, 'posts' => $posts, 'pages' => max(1, (int)ceil($t['reply_count'] / 20)), 'page' => $page, 'tags' => TAGS]);

case 'new_thread':
    $u = need();
    $cid = (int)($in['category_id'] ?? 0);
    if (!q('SELECT 1 FROM categories WHERE id=?', [$cid])->fetch()) fail('Velg en kategori');
    $title = text($in, 'title', 3, 150, 'Tittelen');
    $body = text($in, 'body', 1, 10000, 'Innlegget');
    $tag = in_array($in['tag'] ?? '', TAGS, true) ? $in['tag'] : 'Diskusjon';
    flood('threads', (int)$u['id']);
    q('INSERT INTO threads (category_id, user_id, title, body, tag) VALUES (?,?,?,?,?)', [$cid, $u['id'], $title, $body, $tag]);
    $id = (int)db()->lastInsertId();
    award((int)$u['id'], 10, null, 'tråd');
    mentions($body, (int)$u['id'], $id, $title);
    check_ach((int)$u['id']);
    out(['id' => $id]);

case 'reply':
    $u = need();
    $t = q('SELECT id, user_id, title, locked FROM threads WHERE id=?', [$in['thread_id'] ?? 0])->fetch();
    if (!$t) fail('Fant ikke tråden', 404);
    if ($t['locked'] && $u['role'] !== 'admin') fail('Tråden er låst', 403);
    $body = text($in, 'body', 1, 10000, 'Svaret');
    flood('posts', (int)$u['id']);
    q('INSERT INTO posts (thread_id, user_id, body) VALUES (?,?,?)', [$t['id'], $u['id'], $body]);
    $pid = (int)db()->lastInsertId();
    q('UPDATE threads SET reply_count=reply_count+1, last_post_at=NOW() WHERE id=?', [$t['id']]);
    award((int)$u['id'], 3, null, 'svar');
    if ((int)$t['user_id'] !== (int)$u['id']) {
        award((int)$t['user_id'], 1, null, 'svar mottatt');
        notify((int)$t['user_id'], 'reply', (int)$u['id'], (int)$t['id'], 'svarte på «' . mb_strimwidth($t['title'], 0, 60, '…') . '»');
    }
    mentions($body, (int)$u['id'], (int)$t['id'], $t['title']);
    check_ach((int)$u['id']);
    out(['id' => $pid, 'reply_count' => (int)q('SELECT reply_count FROM threads WHERE id=?', [$t['id']])->fetchColumn()]);

case 'edit_post':
    $u = need();
    $p = q('SELECT id, user_id FROM posts WHERE id=?', [$in['id'] ?? 0])->fetch();
    if (!$p) fail('Fant ikke innlegget', 404);
    if (!can_edit($u, (int)$p['user_id'])) fail('Du kan ikke redigere dette', 403);
    q('UPDATE posts SET body=?, edited_at=NOW() WHERE id=?', [text($in, 'body', 1, 10000, 'Svaret'), $p['id']]);
    out(['ok' => true]);

case 'delete_post':
    $u = need();
    $p = q('SELECT id, user_id, thread_id FROM posts WHERE id=?', [$in['id'] ?? 0])->fetch();
    if (!$p) fail('Fant ikke innlegget', 404);
    if (!can_edit($u, (int)$p['user_id'])) fail('Du kan ikke slette dette', 403);
    q('DELETE FROM posts WHERE id=?', [$p['id']]);
    q("DELETE FROM likes WHERE kind='post' AND target_id=?", [$p['id']]);
    q('UPDATE threads SET reply_count=GREATEST(reply_count-1,0) WHERE id=?', [$p['thread_id']]);
    out(['ok' => true]);

case 'edit_thread':
    $u = need();
    $t = q('SELECT id, user_id FROM threads WHERE id=?', [$in['id'] ?? 0])->fetch();
    if (!$t) fail('Fant ikke tråden', 404);
    if (!can_edit($u, (int)$t['user_id'])) fail('Du kan ikke redigere dette', 403);
    $tag = in_array($in['tag'] ?? '', TAGS, true) ? $in['tag'] : 'Diskusjon';
    q('UPDATE threads SET title=?, body=?, tag=?, edited_at=NOW() WHERE id=?', [text($in, 'title', 3, 150, 'Tittelen'), text($in, 'body', 1, 10000, 'Innlegget'), $tag, $t['id']]);
    out(['ok' => true]);

case 'delete_thread':
    $u = need();
    $t = q('SELECT id, user_id, category_id FROM threads WHERE id=?', [$in['id'] ?? 0])->fetch();
    if (!$t) fail('Fant ikke tråden', 404);
    if (!can_edit($u, (int)$t['user_id'])) fail('Du kan ikke slette dette', 403);
    q("DELETE FROM likes WHERE kind='post' AND target_id IN (SELECT id FROM posts WHERE thread_id=?)", [$t['id']]);
    q("DELETE FROM likes WHERE kind='thread' AND target_id=?", [$t['id']]);
    q('DELETE FROM threads WHERE id=?', [$t['id']]);
    out(['ok' => true, 'category_id' => (int)$t['category_id']]);

case 'thread_mod':
    admin();
    $t = q('SELECT id FROM threads WHERE id=?', [$in['id'] ?? 0])->fetch();
    if (!$t) fail('Fant ikke tråden', 404);
    foreach (['pinned', 'locked'] as $f) if (isset($in[$f])) q("UPDATE threads SET $f=? WHERE id=?", [$in[$f] ? 1 : 0, $t['id']]);
    out(['ok' => true]);

case 'like':
    $u = need();
    $kind = ($in['kind'] ?? '') === 'post' ? 'post' : 'thread';
    $tid = (int)($in['id'] ?? 0);
    $row = $kind === 'post'
        ? q('SELECT p.user_id, p.thread_id, t.title FROM posts p JOIN threads t ON t.id=p.thread_id WHERE p.id=?', [$tid])->fetch()
        : q('SELECT user_id, id thread_id, title FROM threads WHERE id=?', [$tid])->fetch();
    if (!$row) fail('Fant ikke innlegget', 404);
    $ex = q('SELECT active, awarded FROM likes WHERE user_id=? AND kind=? AND target_id=?', [$u['id'], $kind, $tid])->fetch();
    if (!$ex) { q('INSERT INTO likes (user_id,kind,target_id) VALUES (?,?,?)', [$u['id'], $kind, $tid]); $active = true; $awarded = 0; }
    else { $active = !$ex['active']; $awarded = (int)$ex['awarded']; q('UPDATE likes SET active=? WHERE user_id=? AND kind=? AND target_id=?', [$active ? 1 : 0, $u['id'], $kind, $tid]); }
    $author = (int)$row['user_id'];
    if ($active && !$awarded && $author !== (int)$u['id']) {
        q('UPDATE likes SET awarded=1 WHERE user_id=? AND kind=? AND target_id=?', [$u['id'], $kind, $tid]);
        award($author, 2, null, 'like');
        notify($author, 'like', (int)$u['id'], (int)$row['thread_id'], 'likte innlegget ditt i «' . mb_strimwidth($row['title'], 0, 60, '…') . '»');
        check_ach($author);
    }
    out(['liked' => $active, 'likes' => (int)q('SELECT COUNT(*) FROM likes WHERE kind=? AND target_id=? AND active=1', [$kind, $tid])->fetchColumn()]);

/* ===== Feed ===== */
case 'feed':
    $u = need();
    $f = in_array($_GET['filter'] ?? '', ['cats', 'following'], true) ? $_GET['filter'] : 'all';
    $page = max(0, (int)($_GET['page'] ?? 0));
    $subs = 'SELECT category_id FROM subscriptions WHERE user_id=:me';
    $fol = 'SELECT followed_id FROM follows WHERE follower_id=:me';
    $cols = "th.id thread_id, th.title, LEFT(th.body,220) excerpt, th.created_at, u.username, c.name cat, c.id cat_id, c.icon,
             th.reply_count replies, (SELECT COUNT(*) FROM likes l WHERE l.kind='thread' AND l.target_id=th.id AND l.active=1) likes, '' extra";
    $tcond = $f === 'cats' ? "th.category_id IN ($subs)" : ($f === 'following' ? "th.user_id IN ($fol)" : "th.category_id IN ($subs) OR th.user_id IN ($fol)");
    $parts = ["SELECT 'thread' kind, $cols FROM threads th JOIN users u ON u.id=th.user_id JOIN categories c ON c.id=th.category_id WHERE $tcond"];
    if ($f !== 'cats') {
        $parts[] = "SELECT 'reply', th.id, th.title, LEFT(p.body,220), p.created_at, u.username, c.name, c.id, c.icon, 0, 0, ''
                    FROM posts p JOIN threads th ON th.id=p.thread_id JOIN users u ON u.id=p.user_id JOIN categories c ON c.id=th.category_id
                    WHERE p.user_id IN ($fol)";
        $parts[] = "SELECT 'gift', 0, g.name, ug.message, ug.created_at, s.username, r.username, 0, g.emoji, 0, 0, ''
                    FROM user_gifts ug JOIN gifts g ON g.id=ug.gift_id JOIN users s ON s.id=ug.from_user JOIN users r ON r.id=ug.to_user
                    WHERE ug.from_user IN ($fol) OR ug.to_user IN ($fol)";
    }
    out(q('(' . implode(') UNION ALL (', $parts) . ') ORDER BY created_at DESC LIMIT 31 OFFSET ' . ($page * 30), [':me' => $u['id']])->fetchAll());

/* ===== Nyheter ===== */
case 'news':
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    out(['items' => q("SELECT n.id, n.title, n.body, n.tag, n.pinned, n.created_at, u.username FROM news n JOIN users u ON u.id=n.user_id
                       ORDER BY n.pinned DESC, n.created_at DESC LIMIT $limit")->fetchAll(), 'tags' => NEWS_TAGS]);

case 'news_post':
    $u = admin();
    $tag = in_array($in['tag'] ?? '', NEWS_TAGS, true) ? $in['tag'] : NEWS_TAGS[0];
    q('INSERT INTO news (user_id, title, body, tag, pinned) VALUES (?,?,?,?,?)',
      [$u['id'], text($in, 'title', 3, 150, 'Tittelen'), text($in, 'body', 1, 10000, 'Teksten'), $tag, empty($in['pinned']) ? 0 : 1]);
    out(['ok' => true]);

case 'news_pin':
    admin();
    q('UPDATE news SET pinned = 1 - pinned WHERE id=?', [$in['id'] ?? 0]);
    out(['ok' => true]);

case 'news_delete':
    admin();
    q('DELETE FROM news WHERE id=?', [$in['id'] ?? 0]);
    out(['ok' => true]);

/* ===== Gaver ===== */
case 'gifts':
    out(q('SELECT id, name, emoji, cost FROM gifts ORDER BY cost')->fetchAll());

case 'send_gift':
    $u = need();
    $gift = q('SELECT * FROM gifts WHERE id=?', [$in['gift_id'] ?? 0])->fetch();
    $to = q('SELECT id FROM users WHERE id=?', [$in['user_id'] ?? 0])->fetch();
    if (!$gift || !$to) fail('Fant ikke gaven eller mottakeren', 404);
    if ((int)$to['id'] === (int)$u['id']) fail('Du kan ikke gi gaver til deg selv');
    $msg = text($in, 'message', 0, 140, 'Meldingen');
    db()->beginTransaction();
    $paid = q('UPDATE users SET coins=coins-? WHERE id=? AND coins>=?', [$gift['cost'], $u['id'], $gift['cost']])->rowCount();
    if (!$paid) { db()->rollBack(); fail('Du har ikke nok mynter'); }
    q('INSERT INTO user_gifts (from_user,to_user,gift_id,message) VALUES (?,?,?,?)', [$u['id'], $to['id'], $gift['id'], $msg]);
    db()->commit();
    award((int)$to['id'], max(1, intdiv((int)$gift['cost'], 2)), 0, 'gave');
    notify((int)$to['id'], 'gift', (int)$u['id'], null, "ga deg {$gift['emoji']} {$gift['name']}" . ($msg !== '' ? ': «' . mb_strimwidth($msg, 0, 80, '…') . '»' : ''));
    check_ach((int)$u['id']); check_ach((int)$to['id']);
    out(['ok' => true]);

/* ===== Varsler ===== */
case 'notifications':
    $u = need();
    out(q('SELECT n.id, n.type, n.thread_id, n.text, n.is_read, n.created_at, a.username actor
           FROM notifications n LEFT JOIN users a ON a.id=n.actor_id WHERE n.user_id=? ORDER BY n.id DESC LIMIT 40', [$u['id']])->fetchAll());

case 'notifications_read':
    $u = need();
    q('UPDATE notifications SET is_read=1 WHERE user_id=?', [$u['id']]);
    out(['ok' => true]);

default:
    fail('Ukjent handling', 404);
}
} catch (PDOException $e) {
    try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable) { /* ingen tilkobling */ }
    error_log('Forum DB: ' . $e->getMessage());
    $msg = $e->getMessage();
    if (in_array($e->getCode(), ['42S22', '42S02'], true))
        fail('Databasen mangler oppdateringer (' . (preg_match("/Unknown column '([^']+)'/", $msg, $m) ? $m[1] : 'tabell mangler') . '). Åpne /migrate.php?go=1 én gang.', 500);
    if (preg_match('/Access denied|Unknown database|Connection refused|No such file|getaddrinfo/i', $msg))
        fail('Kunne ikke koble til databasen. Sjekk innstillingene i config.php.', 500);
    fail('Databasefeil. Se error_log på serveren for detaljer.', 500);
}
