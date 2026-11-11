<?php
/* Tarazu API: a small document store (SQLite) with PIN sign-in, role checks and web push. */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
if (!defined('TARAZU_LIB')) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  header('X-Content-Type-Options: nosniff');
}

$op = $_GET['op'] ?? '';
if (!defined('N8N_BASE')) define('N8N_BASE', 'https://hitarthuppal1.app.n8n.cloud');
$RAW = file_get_contents('php://input') ?: '{}';
$in = json_decode($RAW, true);
$INOBJ = json_decode($RAW, false); // keeps {} as objects when storing
if (!is_array($in)) $in = [];
$PUSH_QUEUE = [];

function out(array $a, int $code = 200): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); flush_pushes(); exit; }
function fail(string $m, int $c = 400): void { out(['error' => $m], $c); }
function parent_of(string $p): string { $i = strrpos($p, '/'); return $i === false ? '' : substr($p, 0, $i); }
function valid_path($p): bool { return is_string($p) && preg_match('#^[A-Za-z0-9_\-.~:@+]{1,200}(/[A-Za-z0-9_\-.~:@+]{1,200}){1,14}$#', $p) === 1 && substr_count($p, '/') % 2 === 1; }

function db(): PDO {
  static $pdo = null; if ($pdo) return $pdo;
  if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
  $pdo = new PDO('sqlite:' . DATA_DIR . '/tarazu.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec('PRAGMA journal_mode=WAL'); $pdo->exec('PRAGMA busy_timeout=4000');
  $pdo->exec('CREATE TABLE IF NOT EXISTS docs(path TEXT PRIMARY KEY, parent TEXT, data TEXT, updated REAL, deleted INTEGER DEFAULT 0)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS i_upd ON docs(updated)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS subs(endpoint TEXT PRIMARY KEY, user_id TEXT, data TEXT, ua TEXT, created REAL)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS fails(k TEXT PRIMARY KEY, n INTEGER, until REAL)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS inbox(id INTEGER PRIMARY KEY AUTOINCREMENT, endpoint TEXT, title TEXT, body TEXT, url TEXT, created REAL, delivered INTEGER DEFAULT 0)');
  seed($pdo);
  return $pdo;
}
function seed(PDO $pdo): void {
  $f = __DIR__ . '/seed.json';
  if (!is_file($f)) return;
  if ((int)$pdo->query('SELECT COUNT(*) FROM docs')->fetchColumn() === 0) {
    $docs = json_decode(file_get_contents($f), false) ?: [];
    $st = $pdo->prepare('INSERT OR REPLACE INTO docs(path,parent,data,updated,deleted) VALUES(?,?,?,?,0)');
    $pdo->beginTransaction(); $t = microtime(true);
    foreach ($docs as $d) $st->execute([$d->path, parent_of($d->path), json_encode($d->data, JSON_UNESCAPED_UNICODE), $t]);
    $pdo->commit();
  }
  @unlink($f); // the seed holds PINs: never leave it on the server
}
function get_doc(string $p): ?array { $st = db()->prepare('SELECT data FROM docs WHERE path=? AND deleted=0'); $st->execute([$p]); $r = $st->fetchColumn(); return $r === false ? null : json_decode($r, true); }
function put_doc(string $p, $d): void { db()->prepare('INSERT OR REPLACE INTO docs(path,parent,data,updated,deleted) VALUES(?,?,?,?,0)')->execute([$p, parent_of($p), json_encode($d, JSON_UNESCAPED_UNICODE), microtime(true)]); }
function del_doc(string $p): void { db()->prepare('UPDATE docs SET deleted=1, data=NULL, updated=? WHERE path=?')->execute([microtime(true), $p]); }

/* sign-in tokens */
function sign(string $s): string { return hash_hmac('sha256', $s, APP_SECRET); }
function make_token(string $uid): string { $p = $uid . '|' . (time() + 60 * 60 * 24 * 30); return base64_encode($p) . '.' . sign($p); }
/* a session opened by master admin on someone else's account: 12 hours, marked with the master's id */
function make_token_as(string $uid, string $master): string { $p = $uid . '|' . (time() + 60 * 60 * 12) . '|' . $master; return base64_encode($p) . '.' . sign($p); }
function me(): ?array {
  static $u = false; if ($u !== false) return $u; $u = null;
  $t = $_SERVER['HTTP_X_TOKEN'] ?? ''; if ($t === '' || strpos($t, '.') === false) return null;
  [$b, $sig] = explode('.', $t, 2); $p = (string)base64_decode($b, true);
  if (!hash_equals(sign($p), $sig)) return null;
  $parts = explode('|', $p); if ((count($parts) !== 2 && count($parts) !== 3) || (int)$parts[1] < time()) return null;
  $d = get_doc('users/' . $parts[0]); if (!$d || ($d['active'] ?? true) === false) return null;
  if (count($parts) === 3) { $m = get_doc('users/' . $parts[2]); if (!$m || ($m['role'] ?? '') !== 'master') return null; $d['_via_master'] = $parts[2]; }
  $d['id'] = $parts[0]; $u = $d; return $u;
}

/* who may read and write what */
function can_read(?array $u, string $p, array $d): bool {
  $par = parent_of($p);
  if (!$u) return in_array($par, ['users', 'clients'], true) || $p === 'config/market' || $p === 'config/auth';
  if ($u['role'] === 'master') return true;
  $cid = $u['client_id'] ?? '';
  if ($u['role'] === 'panelist' && preg_match('#^clients/[^/]+/(rviews|outlooks|comments|pvotes)/([^/]+)$#', $p, $m)) return substr($m[2], -strlen('__' . $u['id'])) === '__' . $u['id'] && strpos($p, 'clients/' . $cid . '/') === 0;
  if ($par === 'users') return ($d['client_id'] ?? null) === $cid || ($d['role'] ?? '') === 'master';
  if ($par === 'clients') return $p === 'clients/' . $cid;
  if (in_array($par, ['reports', 'config'], true)) return true;
  return strpos($p, 'clients/' . $cid . '/') === 0;
}
function shape(?array $u, string $p, array $d): array {
  if (parent_of($p) !== 'users') return $d;
  $pin_ok = $u && ($u['role'] === 'master' || ($u['role'] === 'owner' && ($d['client_id'] ?? '') === ($u['client_id'] ?? '') && ($d['role'] ?? '') !== 'master'));
  if (!$pin_ok) { unset($d['pin']); if (!$u || $u['role'] !== 'master' && ($u['id'] ?? '') !== substr($p, 6)) unset($d['phone']); }
  if (!$u) $d = array_intersect_key($d, array_flip(['name', 'role', 'client_id', 'active']));
  return $d;
}
function can_write(array $u, string $p, ?array $new, ?array $old): bool {
  if ($u['role'] === 'master') return true;
  $cid = $u['client_id'] ?? ''; $par = parent_of($p);
  if ($par === 'users') {
    if ($u['role'] !== 'owner') return false;
    $c = $new['client_id'] ?? ($old['client_id'] ?? null);
    if ($c !== $cid || ($new['role'] ?? '') === 'master' || ($old['role'] ?? '') === 'master') return false;
    return true;
  }
  if (strpos($p, 'clients/' . $cid . '/') !== 0) return false;
  if (preg_match('#^clients/[^/]+/(rviews|outlooks|comments|pvotes)/[^/]+__([A-Za-z0-9_\-]+)$#', $p, $am) && $am[2] !== ($u['id'] ?? '')) {
    if ($u['role'] === 'panelist') return false;
    return $new === null && in_array($u['role'], ['owner', 'manager'], true);
  }
  // an advisor's view only while its round is open
  if ($u['role'] === 'panelist' && preg_match('#^clients/[^/]+/rviews/([^/]+)__#', $p, $rm) && $new !== null) {
    $rd = get_doc('clients/' . $cid . '/rounds/' . $rm[1]);
    if (!$rd || ($rd['status'] ?? '') === 'decided' || (isset($rd['valid_until']) && strtotime((string)$rd['valid_until']) < time() - 120)) return false;
  }
  if ($u['role'] === 'panelist') return preg_match('#^clients/' . preg_quote($cid, '#') . '/(rviews|outlooks|comments|pvotes)/[^/]+__' . preg_quote($u['id'], '#') . '$#', $p) === 1;
  return in_array($u['role'], ['owner', 'manager'], true);
}

/* notification switches (config/notify); round notifications default on, scheduled ones default off */
function notify_on(string $k, bool $def): bool { $n = get_doc('config/notify') ?? []; return array_key_exists($k, $n) ? (bool)$n[$k] : $def; }
function norm_phone($s): string { $d = preg_replace('#\D#', '', (string)$s); return strlen($d) >= 10 ? substr($d, -10) : $d; }

/* notifications fired by writes */
function users_where(string $cid, array $roles): array {
  $st = db()->prepare("SELECT path, data FROM docs WHERE parent='users' AND deleted=0"); $st->execute(); $out = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $d = json_decode($r['data'], true); if (($d['active'] ?? true) === false) continue;
    if (in_array($d['role'] ?? '', $roles, true) && (($d['client_id'] ?? '') === $cid || ($d['role'] ?? '') === 'master')) $out[] = substr($r['path'], 6); }
  return $out;
}
function after_write(array $u, string $p, ?array $new, ?array $old): void {
  global $PUSH_QUEUE;
  $who = $u['name'] ?? 'An advisor';
  if (notify_on('comment_push', true) && preg_match('#^clients/([^/]+)/comments/([^/]+)__([^/]+)$#', $p, $m) && $new && !$old) {
    $txt = trim((string)($new['text'] ?? '')) ?: trim((string)($new['voice']['english'] ?? '')) ?: 'Voice note';
    foreach (users_where($m[1], ['owner', 'manager']) as $uid) $PUSH_QUEUE[] = [$uid, $who . ' sent a comment', mb_substr($txt, 0, 140), './#comments'];
  }
  if (notify_on('poll_push', true) && preg_match('#^clients/([^/]+)/polls/([^/]+)$#', $p, $m) && $new && !$old)
    foreach (users_where($m[1], ['panelist']) as $uid) if ((get_doc('users/' . $uid)['role'] ?? '') === 'panelist') $PUSH_QUEUE[] = [$uid, 'New poll', mb_substr((string)($new['question'] ?? 'Your answer is needed.'), 0, 140), './#poll=' . $m[2]];
  if (notify_on('poll_push', true) && preg_match('#^clients/([^/]+)/pvotes/([^/]+)__([^/]+)$#', $p, $m) && $new) {
    $poll = get_doc("clients/{$m[1]}/polls/{$m[2]}") ?? [];
    $mgmt = users_where($m[1], ['owner', 'manager']);
    foreach ($mgmt as $uid) $PUSH_QUEUE[] = [$uid, $who . ' answered the poll', ($new['choice'] ?? '') . (trim((string)($new['comment'] ?? '')) !== '' ? ' · ' . mb_substr((string)$new['comment'], 0, 100) : '') . ' — ' . mb_substr((string)($poll['question'] ?? ''), 0, 80), './#poll=' . $m[2]];
    $pans = array_values(array_filter(users_where($m[1], ['panelist']), fn($x) => (get_doc('users/' . $x)['role'] ?? '') === 'panelist'));
    $votes = array_filter(docs_under("clients/{$m[1]}/pvotes"), fn($v, $k) => str_starts_with($k, $m[2] . '__'), ARRAY_FILTER_USE_BOTH);
    if (!$old && $pans && count($votes) >= count($pans)) {
      $tally = []; foreach ($votes as $v) { $c = (string)($v['choice'] ?? ''); $tally[$c] = ($tally[$c] ?? 0) + 1; }
      $sum = implode(', ', array_map(fn($k, $n) => "$k $n", array_keys($tally), $tally));
      foreach ($mgmt as $uid) $PUSH_QUEUE[] = [$uid, 'Poll complete', 'All advisors answered: ' . $sum . '.', './#poll=' . $m[2]];
    }
  }
  if (preg_match('#^clients/([^/]+)/rviews/([^/]+)__([^/]+)$#', $p, $m) && $new && notify_on('view_push', true)) {
    $vers = is_array($new['versions'] ?? null) ? $new['versions'] : []; $last = $vers ? end($vers) : null; $changed = is_array($last) && ($last['kind'] ?? '') === 'change';
    foreach (users_where($m[1], ['owner', 'manager']) as $uid) $PUSH_QUEUE[] = [$uid, $changed ? 'An advisor changed their view' : 'New advisor view', $who . ($changed ? ' changed their view: ' . ($last['change_reason'] ?? '') : ' has sent a view.'), './#round=' . $m[2]];
  }
  if (!notify_on('round_push', true)) return;
  if (preg_match('#^clients/([^/]+)/rounds/([^/]+)$#', $p, $m)) {
    if (!$old && $new) foreach (users_where($m[1], ['panelist', 'owner']) as $uid) $PUSH_QUEUE[] = [$uid, 'New advisory round', ($new['title'] ?? 'A new round') . ' needs your view.', './#round=' . $m[2]];
    if ($old && $new && ($old['status'] ?? '') !== 'decided' && ($new['status'] ?? '') === 'decided') foreach (users_where($m[1], ['manager', 'owner']) as $uid) $PUSH_QUEUE[] = [$uid, 'Decision recorded', 'The owner has decided on ' . ($new['title'] ?? 'the round') . '.', './#round=' . $m[2]];
  }
}
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function der_to_raw(string $der): string {
  $pos = 3; $rl = ord($der[$pos]); $pos++; $r = substr($der, $pos, $rl); $pos += $rl + 1; $sl = ord($der[$pos]); $pos++; $s = substr($der, $pos, $sl);
  return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT) . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
}
function vapid_jwt(string $aud): string {
  $h = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])); $c = b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => VAPID_SUBJECT]));
  openssl_sign("$h.$c", $der, VAPID_PRIVATE_PEM, OPENSSL_ALGO_SHA256); return "$h.$c." . b64u(der_to_raw($der));
}
function send_push(string $ep): int {
  $u = parse_url($ep); $aud = $u['scheme'] . '://' . $u['host'];
  $auth = 'Authorization: vapid t=' . vapid_jwt($aud) . ', k=' . VAPID_PUBLIC;
  if (function_exists('curl_init')) {
    $ch = curl_init($ep);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
      CURLOPT_HTTPHEADER => ['TTL: 86400', 'Urgency: high', 'Content-Length: 0', $auth]]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return $code;
  }
  $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "TTL: 86400\r\nUrgency: high\r\nContent-Length: 0\r\n" . $auth . "\r\n", 'content' => '', 'timeout' => 8, 'ignore_errors' => true]]);
  @file_get_contents($ep, false, $ctx);
  $code = 0;
  if (isset($http_response_header[0]) && preg_match('#\s(\d{3})(\s|$)#', $http_response_header[0], $m)) $code = (int)$m[1];
  return $code;
}
function flush_pushes(): void {
  global $PUSH_QUEUE; if (!$PUSH_QUEUE) return;
  if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
  $q = $PUSH_QUEUE; $PUSH_QUEUE = [];
  foreach ($q as [$uid, $title, $body, $url]) {
    $st = db()->prepare('SELECT endpoint FROM subs WHERE user_id=?'); $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ep) {
      db()->prepare('INSERT INTO inbox(endpoint,title,body,url,created,delivered) VALUES(?,?,?,?,?,0)')->execute([$ep, $title, mb_substr($body, 0, 180), $url, microtime(true)]);
      $code = send_push($ep);
      if ($code === 404 || $code === 410) db()->prepare('DELETE FROM subs WHERE endpoint=?')->execute([$ep]);
    }
  }
}

/* scheduled job: noon outlook prompt (Mon-Sat), closing reminder, owner's 3 PM digest. Days run 3 AM to 3 AM, India time. */
function docs_under(string $parent): array {
  $st = db()->prepare('SELECT path, data FROM docs WHERE parent=? AND deleted=0'); $st->execute([$parent]); $o = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $o[substr($r['path'], strlen($parent) + 1)] = json_decode($r['data'], true) ?: [];
  return $o;
}
function once(string $k, bool $dry): bool {
  db()->exec('CREATE TABLE IF NOT EXISTS sent(k TEXT PRIMARY KEY, at REAL)');
  if ($dry) { $st = db()->prepare('SELECT 1 FROM sent WHERE k=?'); $st->execute([$k]); return !$st->fetchColumn(); }
  $st = db()->prepare('INSERT OR IGNORE INTO sent(k,at) VALUES(?,?)'); $st->execute([$k, microtime(true)]); return $st->rowCount() === 1;
}
function run_schedule(bool $dry, ?string $at = null): array {
  global $PUSH_QUEUE;
  $tz = new DateTimeZone('Asia/Kolkata'); $now = new DateTimeImmutable($at ?? 'now', $tz);
  $cycle = $now->modify('-3 hours')->format('Y-m-d'); $cycleStart = new DateTimeImmutable($cycle . ' 03:00', $tz);
  $workday = (int)$now->format('N') <= 6; $hm = (int)$now->format('G') * 60 + (int)$now->format('i'); $ts = $now->getTimestamp();
  $sw = ['noon' => notify_on('noon', false), 'reminder' => notify_on('reminder', false), 'digest' => notify_on('digest', false), 'chase' => notify_on('chase', false)];
  $plan = [];
  $add = function (string $kind, array $uids, string $title, string $body, string $url, string $cid) use (&$plan, $dry, $sw) {
    if (!$uids) return; $plan[] = ['kind' => $kind, 'client' => $cid, 'to' => count($uids), 'title' => $title, 'body' => $body, 'on' => $sw[$kind]];
    global $PUSH_QUEUE; if (!$dry && $sw[$kind]) foreach ($uids as $u) $PUSH_QUEUE[] = [$u, $title, $body, $url];
  };
  foreach (array_keys(docs_under('clients')) as $cid) {
    $rounds = docs_under("clients/$cid/rounds"); $rviews = docs_under("clients/$cid/rviews"); $outl = docs_under("clients/$cid/outlooks");
    $pan = array_values(array_filter(users_where($cid, ['panelist']), fn($u) => (get_doc('users/' . $u)['role'] ?? '') === 'panelist'));
    $open = array_filter($rounds, fn($r) => ($r['status'] ?? '') !== 'decided' && strtotime((string)($r['valid_until'] ?? '')) > $ts);
    $mk = fn(string $k) => $dry ? once($k, true) : ($sw[explode(':', $k)[0]] ? once($k, false) : false);
    if ($workday && $hm >= 720 && $hm < 840 && !$open && $mk("noon:$cid:$cycle")) {
      $to = array_values(array_filter($pan, fn($u) => !isset($outl[$cycle . '__' . $u])));
      $add('noon', $to, "Today's market outlook", "What's your read on today's market, and what should the mills do? One tap, no figures needed.", './#today', $cid);
    }
    foreach ($open as $rid => $r) {
      $left = strtotime((string)$r['valid_until']) - $ts;
      if ($left > 0 && $left <= 3600 && $mk("chase:$cid:$rid")) {
        $miss = array_values(array_filter($pan, fn($u) => !isset($rviews[$rid . '__' . $u])));
        if ($miss) { $names = array_map(fn($u) => get_doc('users/' . $u)['name'] ?? $u, $miss);
          $add('chase', users_where($cid, ['manager']), 'Still waiting on ' . count($miss) . ' advisor' . (count($miss) > 1 ? 's' : ''), implode(', ', $names) . ' ' . (count($miss) > 1 ? 'have' : 'has') . ' not answered ' . ($r['title'] ?? 'the round') . '. It closes at ' . (new DateTimeImmutable('@' . strtotime((string)$r['valid_until'])))->setTimezone($tz)->format('g:i A') . '.', './#round=' . $rid, $cid); }
      }
      if ($left > 0 && $left <= 7200 && $mk("reminder:$cid:$rid")) {
        $to = array_values(array_filter($pan, fn($u) => !isset($rviews[$rid . '__' . $u])));
        $add('reminder', $to, 'Round closes soon', ($r['title'] ?? 'The round') . ' closes at ' . (new DateTimeImmutable('@' . strtotime((string)$r['valid_until'])))->setTimezone($tz)->format('g:i A') . '. Your view is still needed.', './#round=' . $rid, $cid);
      }
    }
    if ($workday && $hm >= 900 && $hm < 1020 && $mk("digest:$cid:$cycle")) {
      $todayRounds = array_keys(array_filter($rounds, fn($r) => strtotime((string)($r['created_at'] ?? '')) >= $cycleStart->getTimestamp()));
      $n = 0; $st = ['sell' => 0, 'some' => 0, 'hold' => 0];
      foreach ($pan as $u) {
        $s = null;
        if (isset($outl[$cycle . '__' . $u])) $s = $outl[$cycle . '__' . $u]['stance'] ?? null;
        else foreach ($todayRounds as $rid) if (isset($rviews[$rid . '__' . $u])) { $p = (float)($rviews[$rid . '__' . $u]['sell_pct'] ?? 0); $s = $p >= 100 ? 'sell' : ($p <= 0 ? 'hold' : 'some'); }
        if ($s !== null) { $n++; if (isset($st[$s])) $st[$s]++; }
      }
      $mix = implode(', ', array_filter([$st['sell'] ? $st['sell'] . ' sell all' : '', $st['some'] ? $st['some'] . ' sell some' : '', $st['hold'] ? $st['hold'] . ' hold' : '']));
      $add('digest', users_where($cid, ['owner']), 'Advisor responses today', "$n of " . count($pan) . ' advisors have responded today' . ($mix ? " ($mix)." : '.'), './', $cid);
    }
  }
  if (!$dry) put_doc('config/cron', (object)['last_run' => $now->format(DATE_ATOM), 'sent' => array_sum(array_map(fn($p) => $p['on'] ? $p['to'] : 0, $plan))]);
  return ['at' => $now->format(DATE_ATOM), 'cycle' => $cycle, 'switches' => $sw, 'plan' => $plan];
}

/* Demo mode: while config/demo is switched on, the day writes itself from a script (DATA_DIR/demo_story.json).
   10 AM the manager posts the round, advisors answer through the day, noon outlooks, comments and polls come in,
   the owner decides after the close and the sales are lifted in the evening. Rates follow the live spot.
   Holidays in the script get no round. Everything is marked demo:true and written straight to the database, so no notifications go out. */
function demo_r(string $k): float { return (crc32($k) % 100000) / 100000; }
function demo_pick(array $a, string $k) { return $a[(int)floor(demo_r($k) * count($a)) % count($a)]; }
function demo_story(): ?array { static $s = false; if ($s === false) { $f = DATA_DIR . '/demo_story.json'; $s = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: null) : null; } return $s; }
function demo_fmt(float $n): string { $s = (string)(int)round($n); if (strlen($s) <= 3) return $s; $h = substr($s, 0, -3); $t = substr($s, -3); $h = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $h); return $h . ',' . $t; }
function demo_tick(): void {
  $cfg = get_doc('config/demo'); if (!$cfg || empty($cfg['enabled'])) return;
  $story = demo_story(); if (!$story) return;
  $tz = new DateTimeZone('Asia/Kolkata'); $now = new DateTimeImmutable($GLOBALS['DEMO_AT'] ?? 'now', $tz); $ts = $now->getTimestamp();
  $day = $now->modify('-3 hours')->format('Y-m-d');
  if ($day > (string)($cfg['until'] ?? '') || $day < (string)($cfg['from'] ?? '')) return;
  $cid = (string)($cfg['client'] ?? 'nirani'); $C = "clients/$cid";
  $at = fn(int $min) => (new DateTimeImmutable($day . ' 00:00', $tz))->modify("+$min minutes");
  $iso = fn(DateTimeImmutable $d) => $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
  $due = fn(int $min) => $at($min)->getTimestamp() <= $ts;
  $mills = docs_under("$C/mills");
  // crushing output, once a day at 6 AM (the mills crush through holidays too)
  if ($due(360) && once("demo:prod:$cid:$day", false)) {
    foreach ($story['crush'] ?? [] as $mid => $q) if (isset($mills[$mid]) && $q > 0) { $mills[$mid]['stock_wh'] = (float)($mills[$mid]['stock_wh'] ?? 0) + $q; put_doc("$C/mills/$mid", $mills[$mid]); }
  }
  $S = $story['days'][$day] ?? null; if (!$S || in_array($day, $story['holidays'] ?? [], true)) return;
  // live spot: Karnataka, Maharashtra and UP M/30 from the latest market fetch
  $mk = get_doc('config/market') ?? []; $tr = array_values(array_filter($mk['trend'] ?? [], fn($p) => !empty($p['karnataka_m30'])));
  $ka = $tr ? (float)end($tr)['karnataka_m30'] : 5300;
  $trm = array_values(array_filter($mk['trend'] ?? [], fn($p) => !empty($p['maharashtra_m30']))); $mh = $trm ? (float)end($trm)['maharashtra_m30'] : $ka - 200;
  $up = $ka + 125; foreach (($mk['spot']['exmill'] ?? []) as $row) if (stripos((string)($row['state'] ?? ''), 'uttar') !== false && !empty($row['m30']['mid'])) $up = (float)$row['m30']['mid'];
  $rid = 'r' . str_replace('-', '', $day);

  // 10 AM: the mill manager posts the round
  if ($due((int)$S['created']) && once("demo:round:$cid:$day", false) && !get_doc("$C/rounds/$rid")) {
    $rm = []; $left = 0; // working days left this month, today included (Mon-Sat, not holidays)
    for ($t = new DateTimeImmutable($day, $tz); $t->format('m') === substr($day, 5, 2); $t = $t->modify('+1 day')) if ((int)$t->format('N') <= 6 && !in_array($t->format('Y-m-d'), $story['holidays'] ?? [], true)) $left++;
    foreach ($mills as $mid => $m) {
      $bq = max(0, (float)($m['quota'] ?? 0) - (float)($m['sold_qty'] ?? 0));
      $w = min(floor($bq * (float)$S['wf'] * 0.30 / 100) * 100, floor(max(0, (float)($m['stock_wh'] ?? 0) - (float)($m['sold_qty'] ?? 0)) / 100) * 100);
      $rm[] = ['id' => $mid, 'unit_no' => $m['unit_no'] ?? 0, 'name' => $m['name'] ?? $mid, 'code' => $m['code'] ?? '', 'state' => $m['state'] ?? 'KA', 'stock_wh' => (float)($m['stock_wh'] ?? 0), 'quota' => (float)($m['quota'] ?? 0), 'sold_qty' => (float)($m['sold_qty'] ?? 0), 'sold_avg_price' => (float)($m['sold_avg_price'] ?? 0), 'balance_quota' => $bq, 'wish_to_sell' => $w];
    }
    usort($rm, fn($a, $b) => $a['unit_no'] <=> $b['unit_no']);
    $ask = (int)(round(($ka + (int)$S['ask_off']) / 10) * 10); $bid = (int)(round(($ka + (int)($S['bid_off'] ?? 0)) / 10) * 10);
    $sum = fn($k) => array_sum(array_column($rm, $k));
    $note = strtr((string)$S['note'], ['{bal}' => demo_fmt($sum('balance_quota')), '{quota}' => demo_fmt($sum('quota')), '{stock}' => demo_fmt($sum('stock_wh') - $sum('sold_qty')), '{days}' => (string)$left, '{bid}' => demo_fmt($bid)]);
    put_doc("$C/rounds/$rid", ['title' => 'Round of ' . (new DateTimeImmutable($day, $tz))->format('j M'), 'created_by' => 'manager', 'created_at' => $iso($at((int)$S['created'])), 'valid_until' => $iso($at(960)),
      'band_min' => $ask - 40, 'band_max' => $ask + 40, 'ask_rate' => $ask, 'floor_rate' => $ask - 40, 'flags' => $S['flags'] ?? [], 'cash_need' => in_array('cash', $S['flags'] ?? [], true) ? 'urgent' : 'moderate',
      'note' => $note, 'status' => 'open', 'demo' => true, 'bid' => $bid, 'rates' => ['KA' => $ask, 'MH' => (int)(round($mh / 5) * 5), 'BR' => (int)(round(($up - 40) / 5) * 5)], 'mills' => $rm]);
  }
  $round = get_doc("$C/rounds/$rid");
  // advisors answer through the day (an advisor who has already answered in person is left alone)
  if ($round && ($round['status'] ?? '') !== 'decided') {
    $ask = (int)$round['ask_rate'];
    foreach ($S['views'] as $v) {
      $pid = $v['pid']; if (!$due((int)$v['at']) || !once("demo:view:$cid:$day:$pid", false) || get_doc("$C/rviews/{$rid}__$pid")) continue;
      $pct = (int)$v['sell_pct']; $em = $v['expected_move'] ?? null;
      $tgt = $pct <= 0 ? $ask + ($em && $em > 0 ? (int)(round($em / 10) * 10) : 60) : ($pct >= 75 ? $ask - 10 * (int)floor(demo_r("tr:$day:$pid") * 3) : $ask + ($v['direction'] === 'up' ? 20 : 0));
      $when = $iso($at((int)$v['at']));
      $doc = ['round_id' => $rid, 'panelist_id' => $pid, 'stance' => $v['stance'], 'direction' => $v['direction'], 'sell_pct' => $pct, 'target_rate' => $tgt, 'rest' => 'hold', 'lots' => 4, 'interval_days' => 3,
        'horizon_months' => round(((int)($v['move_days'] ?? 30) ?: 30) / 30, 3), 'overrides' => (object)[], 'reasons' => (object)($v['reasons'] ?: []), 'conviction' => $v['conviction'], 'note' => $v['note'], 'voice' => $v['voice'],
        'updated_at' => $when, 'versions' => [['sell_pct' => $pct, 'target_rate' => $tgt, 'at' => $when, 'kind' => 'first', 'change_reason' => '']], 'demo' => true,
        'move_range' => $v['move_range'], 'move_days' => $v['move_days'], 'expected_move' => $em];
      if (!empty($v['move_unit'])) $doc['move_unit'] = $v['move_unit'];
      put_doc("$C/rviews/{$rid}__$pid", $doc);
      if (!empty($v['outlook']) && !get_doc("$C/outlooks/{$day}__$pid")) {
        $o = ['day' => $day, 'panelist_id' => $pid, 'stance' => $v['stance'], 'sell_pct' => $pct, 'direction' => $v['direction'], 'note' => '', 'voice' => null, 'created_at' => $when, 'updated_at' => $when, 'edits' => 0, 'demo' => true, 'move_range' => $v['move_range'], 'move_days' => $v['move_days'], 'expected_move' => $em];
        put_doc("$C/outlooks/{$day}__$pid", $o);
      }
    }
  }
  // comments
  foreach ($S['comments'] ?? [] as $i => $c) {
    if (!$due((int)$c['at']) || !once("demo:comment:$cid:$day:$i", false)) continue;
    put_doc("$C/comments/" . str_replace('-', '', $day) . $i . '__' . $c['pid'], ['panelist_id' => $c['pid'], 'text' => $c['text'], 'voice' => $c['voice'], 'created_at' => $iso($at((int)$c['at'])), 'demo' => true]);
  }
  // poll at 11 AM, votes through the day
  if (!empty($S['poll']) && $round) {
    $pp = 'p' . str_replace('-', '', $day);
    if ($due(660) && once("demo:poll:$cid:$day", false) && !get_doc("$C/polls/$pp")) {
      put_doc("$C/polls/$pp", ['question' => str_replace('{bid}', demo_fmt((float)($round['bid'] ?? $round['ask_rate'])), (string)$S['poll']['question']), 'options' => $S['poll']['options'], 'note' => $S['poll']['note'], 'created_by' => 'manager', 'created_at' => $iso($at(660)), 'closes_at' => $iso($at(960)), 'round_id' => $rid, 'demo' => true]);
    }
    if (get_doc("$C/polls/$pp")) foreach ($S['poll']['votes'] as $pv) {
      if (!$due((int)$pv['at']) || !once("demo:pvote:$cid:$day:{$pv['pid']}", false) || get_doc("$C/pvotes/{$pp}__{$pv['pid']}")) continue;
      put_doc("$C/pvotes/{$pp}__{$pv['pid']}", ['poll_id' => $pp, 'panelist_id' => $pv['pid'], 'choice' => $pv['choice'], 'comment' => $pv['comment'], 'at' => $iso($at((int)$pv['at'])), 'demo' => true]);
    }
  }
  // after the close: the owner's decision (unless the owner has decided in person), then the evening sales
  $round = get_doc("$C/rounds/$rid");
  if ($round && !empty($round['demo']) && ($round['status'] ?? '') !== 'decided' && $due((int)$S['decision']['at']) && once("demo:close:$cid:$day", false)) {
    $pct = (int)$S['decision']['pct']; $dm = []; $tq = 0; $val = 0; $rates = $round['rates'] ?? [];
    foreach ($round['mills'] ?? [] as $m) {
      $q = (int)min(round((float)$m['wish_to_sell'] * $pct / 100 / 100) * 100, (float)$m['wish_to_sell']);
      $st = $m['state'] ?? 'KA'; $rate = (int)($rates[$st] ?? $round['ask_rate']);
      if ($st === 'KA') { $rate = in_array('buyer', $round['flags'] ?? [], true) && in_array($m['id'], ['unit1', 'unit2'], true) ? (int)($round['bid'] ?? $rate) : $rate - 10 * (int)floor(demo_r("dk:$day") * 3); if (in_array($m['id'], ['unit7', 'unit8'], true)) $rate += 15; }
      $dm[$m['id']] = ['qty' => $q, 'rate' => $rate]; $tq += $q; $val += $q * $rate;
    }
    $round['status'] = 'decided';
    $round['decision'] = ['mills' => (object)$dm, 'total_qty' => $tq, 'avg_rate' => $tq ? (int)round($val / $tq) : null, 'note' => $S['decision']['note'], 'by' => 'owner', 'at' => $iso($at((int)$S['decision']['at'])), 'demo' => true];
    put_doc("$C/rounds/$rid", $round);
  }
  $round = get_doc("$C/rounds/$rid");
  if ($round && ($round['status'] ?? '') === 'decided' && !empty($round['decision']['demo']) && $due((int)$S['decision']['at'] + 30) && once("demo:sales:$cid:$day", false)) {
    $mills = docs_under("$C/mills"); $n = 0;
    foreach (($round['decision']['mills'] ?? []) as $mid => $x) {
      $q = (int)($x['qty'] ?? 0); if ($q <= 0 || !isset($mills[$mid])) continue; $m = $mills[$mid]; $st = $m['state'] ?? 'KA';
      $parts = ($q < 6000 || demo_r("sp:$day:$mid") < 0.4) ? [$q] : [(int)(round($q * 0.6 / 100) * 100), $q - (int)(round($q * 0.6 / 100) * 100)];
      foreach ($parts as $k => $pq) {
        $buyer = ($k === 0 && $st === 'KA' && in_array('buyer', $round['flags'] ?? [], true)) ? 'Hyderabad trader' : demo_pick($story['buyers'][$st] ?? ['Trader'], "by:$day:$mid:$k");
        put_doc("$C/ledger/{$day}_{$mid}_$k", ['date' => $day, 'mill_id' => $mid, 'mill_code' => $m['code'] ?? '', 'mill_name' => $m['name'] ?? $mid, 'qty' => $pq, 'rate' => (int)$x['rate'], 'grade' => 'M/30', 'buyer' => $buyer, 'source' => 'demo', 'created_at' => $iso($at((int)$S['decision']['at'] + 30 + $n++ * 4)), 'demo' => true]);
      }
      $sold = (float)($m['sold_qty'] ?? 0); $avg = (float)($m['sold_avg_price'] ?? 0); $ns = $sold + $q;
      $m['sold_qty'] = $ns; $m['sold_avg_price'] = $ns ? (int)round(($avg * $sold + (int)$x['rate'] * $q) / $ns) : 0;
      $m['wish_to_sell'] = max(0, floor(max(0, (float)($m['quota'] ?? 0) - $ns) * 0.35 / 100) * 100); $m['updated_at'] = $iso($now);
      put_doc("$C/mills/$mid", $m);
    }
  }
}

/* routes */
if (!defined('TARAZU_LIB')) try {
  switch ($op) {
    case 'sync': {
      try { demo_tick(); } catch (Throwable $e) { error_log('tarazu demo: ' . $e->getMessage()); }
      $u = me(); $since = (float)($in['since'] ?? 0); $now = microtime(true);
      $st = db()->prepare('SELECT path, data, deleted FROM docs WHERE updated > ? ORDER BY updated'); $st->execute([$since]);
      $docs = [];
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['deleted'] === 1) { if ($since > 0) $docs[] = ['path' => $r['path'], 'deleted' => true]; continue; }
        $d = json_decode($r['data'], true) ?: []; $o = json_decode($r['data'], false);
        if (!can_read($u, $r['path'], $d)) continue;
        $docs[] = ['path' => $r['path'], 'data' => parent_of($r['path']) === 'users' ? shape($u, $r['path'], $d) : $o];
      }
      out(['now' => $now, 'docs' => $docs, 'signed_in' => (bool)$u]);
    }
    case 'login': {
      $uid = (string)($in['user_id'] ?? ''); $pin = (string)($in['pin'] ?? '');
      if (!preg_match('#^[A-Za-z0-9_\-]{1,64}$#', $uid)) fail('Unknown user', 401);
      usleep(250000);
      $now = microtime(true); $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
      $keys = ['u:' . $uid => [5, 900], 'ip:' . $ip => [20, 900]];
      foreach ($keys as $k => $lim) { $st = db()->prepare('SELECT until FROM fails WHERE k=?'); $st->execute([$k]); $u = (float)$st->fetchColumn();
        if ($u > $now) fail('Too many wrong PINs. Try again in ' . max(1, (int)ceil(($u - $now) / 60)) . ' minutes.', 429); }
      $d = get_doc('users/' . $uid);
      if ((get_doc('config/auth')['phone_required'] ?? false) === true) fail('Sign in with your mobile number.', 403);
      if (!$d || ($d['active'] ?? true) === false || !hash_equals((string)($d['pin'] ?? ''), $pin)) {
        foreach ($keys as $k => [$max, $secs]) {
          $st = db()->prepare('SELECT n FROM fails WHERE k=?'); $st->execute([$k]); $n = (int)$st->fetchColumn() + 1;
          db()->prepare('INSERT OR REPLACE INTO fails(k,n,until) VALUES(?,?,?)')->execute([$k, $n >= $max ? 0 : $n, $n >= $max ? $now + $secs : 0]);
        }
        fail('That PIN does not match.', 401);
      }
      db()->prepare('DELETE FROM fails WHERE k=?')->execute(['u:' . $uid]);
      out(['token' => make_token($uid), 'role' => $d['role'] ?? '']);
    }
    case 'impersonate': {
      $u = me(); if (!$u || ($u['role'] ?? '') !== 'master' || isset($u['_via_master'])) fail('Only master admin can do this.', 403);
      $uid = (string)($in['user_id'] ?? ''); if (!preg_match('#^[A-Za-z0-9_\-]{1,64}$#', $uid)) fail('Unknown user', 404);
      $d = get_doc('users/' . $uid); if (!$d || ($d['active'] ?? true) === false) fail('Unknown user', 404);
      if (($d['role'] ?? '') === 'master') out(['token' => make_token($uid), 'role' => 'master']);
      out(['token' => make_token_as($uid, $u['id']), 'role' => $d['role'] ?? '', 'via_master' => true]);
    }
    case 'login_phone': {
      $ph = norm_phone($in['phone'] ?? ''); $pin = (string)($in['pin'] ?? '');
      if (!preg_match('#^\d{10}$#', $ph)) fail('Enter your 10-digit mobile number.', 400);
      usleep(250000);
      $now = microtime(true); $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
      $keys = ['p:' . $ph => [5, 900], 'ip:' . $ip => [20, 900]];
      foreach ($keys as $k => $lim) { $st = db()->prepare('SELECT until FROM fails WHERE k=?'); $st->execute([$k]); $x = (float)$st->fetchColumn();
        if ($x > $now) fail('Too many wrong attempts. Try again in ' . max(1, (int)ceil(($x - $now) / 60)) . ' minutes.', 429); }
      $uid = null; $d = null;
      foreach (docs_under('users') as $id => $doc) if (($doc['active'] ?? true) !== false && norm_phone($doc['phone'] ?? '') === $ph) { $uid = $id; $d = $doc; break; }
      if (!$d || !hash_equals((string)($d['pin'] ?? ''), $pin)) {
        foreach ($keys as $k => [$max, $secs]) {
          $st = db()->prepare('SELECT n FROM fails WHERE k=?'); $st->execute([$k]); $n = (int)$st->fetchColumn() + 1;
          db()->prepare('INSERT OR REPLACE INTO fails(k,n,until) VALUES(?,?,?)')->execute([$k, $n >= $max ? 0 : $n, $n >= $max ? $now + $secs : 0]);
        }
        fail('That number and PIN do not match.', 401);
      }
      db()->prepare('DELETE FROM fails WHERE k=?')->execute(['p:' . $ph]);
      out(['token' => make_token($uid), 'user_id' => $uid, 'role' => $d['role'] ?? '']);
    }
    case 'schedule_preview': {
      $u = me(); if (!$u || $u['role'] !== 'master') fail('Only master admins can do this.', 403);
      out(run_schedule(true, isset($in['at']) ? (string)$in['at'] : null));
    }
    case 'set': case 'update': case 'delete': {
      $u = me(); if (!$u) fail('Please sign in again.', 401);
      if (strlen($RAW) > (($u['role'] ?? '') === 'master' ? 8000000 : 131072)) fail('That is too large to save. Please shorten it.', 413);
      $p = $in['path'] ?? ''; if (!valid_path($p)) fail('Bad path');
      $old = get_doc($p);
      $new = null;
      if ($op !== 'delete') { $data = $in['data'] ?? null; if (!is_array($data)) fail('Bad data'); $new = $op === 'update' ? array_merge($old ?? [], $data) : $data; }
      if (!can_write($u, $p, $new, $old)) fail('You do not have permission to change this.', 403);
      if (parent_of($p) === 'users' && $u['role'] !== 'master' && isset($new['pin']) && !preg_match('#^\d{4,6}$#', (string)$new['pin'])) fail('PIN must be 4 to 6 digits');
      if (parent_of($p) === 'users' && is_array($new) && ($new['phone'] ?? '') !== '' && isset($in['data']['phone'])) {
        $ph = norm_phone($new['phone']); if (!preg_match('#^[6-9]\d{9}$#', $ph)) fail('Enter a 10-digit Indian mobile number.');
        foreach (docs_under('users') as $id => $doc) if ('users/' . $id !== $p && ($doc['active'] ?? true) !== false && norm_phone($doc['phone'] ?? '') === $ph) fail('That number already belongs to ' . ($doc['name'] ?? 'someone else') . '.');
      }
      if ($p === 'config/auth' && ($new['phone_required'] ?? false) === true) {
        $mine = get_doc('users/' . $u['id']); if (norm_phone($mine['phone'] ?? '') === '') fail('Add your own mobile number first, so you are not locked out.');
      }
      if ($op === 'delete') del_doc($p);
      else {
        $dataObj = is_object($INOBJ) && isset($INOBJ->data) && is_object($INOBJ->data) ? $INOBJ->data : (object)$data;
        if ($op === 'update') { $st = db()->prepare('SELECT data FROM docs WHERE path=? AND deleted=0'); $st->execute([$p]); $raw = $st->fetchColumn();
          $obj = $raw ? json_decode($raw, false) : new stdClass(); if (!is_object($obj)) $obj = new stdClass(); foreach ($dataObj as $k => $v) $obj->$k = $v; }
        else $obj = $dataObj;
        if (isset($u['_via_master']) && strpos($p, 'clients/') === 0 && is_object($obj)) $obj->acted_by_master = $u['_via_master'];
        put_doc($p, $obj);
      }
      after_write($u, $p, $new, $old);
      out(['ok' => true]);
    }
    case 'bulk': {
      /* many plain writes in one request (CSV imports); each path is checked like a single write; no notifications */
      $u = me(); if (!$u) fail('Please sign in again.', 401);
      if (!in_array($u['role'], ['owner', 'manager', 'master'], true)) fail('You do not have permission to change this.', 403);
      if (strlen($RAW) > 2000000) fail('That import is too large. Split the file.', 413);
      $items = $in['items'] ?? null; if (!is_array($items) || count($items) > 500) fail('Send 1 to 500 items.');
      $pdo = db(); $pdo->beginTransaction(); $n = 0;
      foreach ($items as $it) {
        $p = $it['path'] ?? ''; $d = $it['data'] ?? null;
        if (!valid_path($p) || !is_array($d) || parent_of($p) === 'users' || strpos($p, 'config/') === 0) { $pdo->rollBack(); fail('Bad item ' . $n); }
        if (!can_write($u, $p, $d, get_doc($p))) { $pdo->rollBack(); fail('You do not have permission to change ' . $p, 403); }
        put_doc($p, $d); $n++;
      }
      $pdo->commit(); out(['ok' => true, 'written' => $n]);
    }
    case 'market_refresh': {
      $u = me(); if (!$u) fail('Please sign in again.', 401);
      $cur = get_doc('config/market') ?? []; $age = time() - (int)strtotime((string)($cur['fetched_at'] ?? '1970-01-01'));
      $force = !empty($in['force']) && in_array($u['role'], ['master', 'owner', 'manager'], true);
      if (!$force && $age < 10500) out(['ok' => true, 'skipped' => 'fresh']);
      if ($force && $age < 120) out(['ok' => true, 'skipped' => 'just refreshed']);
      $lk = fopen(DATA_DIR . '/market.lock', 'c'); if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) out(['ok' => true, 'skipped' => 'busy']);
      $nw = get_doc('config/news') ?? [];
      $body = json_encode(['news_flag_field' => substr((string)($nw['news_flag_field'] ?? ''), 0, 40), 'news_flag_value' => substr((string)($nw['news_flag_value'] ?? ''), 0, 40)]);
      $ch = curl_init(N8N_BASE . '/webhook/tarazu-market-q8m4z2');
      curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10]);
      $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
      $j = is_string($raw) ? json_decode($raw, false) : null;
      if ($code !== 200 || !is_object($j) || empty($j->ok)) { flock($lk, LOCK_UN); fail('Live prices could not be refreshed right now. Showing the last saved copy.', 502); }
      unset($j->debug); put_doc('config/market', $j); flock($lk, LOCK_UN);
      out(['ok' => true, 'refreshed' => true]);
    }
    case 'vapid': out(['key' => VAPID_PUBLIC]);
    case 'subscribe': {
      $u = me(); if (!$u) fail('Please sign in again.', 401);
      $s = $in['subscription'] ?? null; $ep = is_array($s) ? (string)($s['endpoint'] ?? '') : '';
      if (!preg_match('#^https://#', $ep)) fail('Bad subscription');
      db()->prepare('INSERT OR REPLACE INTO subs(endpoint,user_id,data,ua,created) VALUES(?,?,?,?,?)')->execute([$ep, $u['id'], json_encode($s), substr((string)($in['ua'] ?? ''), 0, 160), microtime(true)]);
      out(['ok' => true]);
    }
    case 'inbox': {
      $ep = (string)($in['endpoint'] ?? '');
      $st = db()->prepare('SELECT id,title,body,url FROM inbox WHERE endpoint=? AND delivered=0 ORDER BY id LIMIT 1'); $st->execute([$ep]);
      $r = $st->fetch(PDO::FETCH_ASSOC);
      if (!$r) out(['title' => 'Tarazu', 'body' => 'You have an update.', 'url' => './']);
      db()->prepare('UPDATE inbox SET delivered=1 WHERE id=?')->execute([$r['id']]);
      out(['title' => $r['title'], 'body' => $r['body'], 'url' => $r['url']]);
    }
    case 'upload': {
      $u = me(); if (!$u || $u['role'] !== 'master') fail('Only master admins can upload reports.', 403);
      $name = preg_replace('#[^A-Za-z0-9_\-.]#', '_', (string)($in['name'] ?? 'report.pdf'));
      if (!preg_match('#\.pdf$#i', $name)) fail('Only PDF files');
      $bin = base64_decode((string)($in['data'] ?? ''), true); if ($bin === false || substr($bin, 0, 4) !== '%PDF') fail('Not a PDF');
      if (!is_dir(__DIR__ . '/files')) mkdir(__DIR__ . '/files', 0755, true);
      file_put_contents(__DIR__ . '/files/' . $name, $bin);
      out(['url' => 'files/' . $name]);
    }
    case 'health': out(['ok' => true, 'php' => PHP_VERSION, 'sqlite' => extension_loaded('pdo_sqlite'), 'openssl' => extension_loaded('openssl'), 'curl' => extension_loaded('curl'), 'data_dir_outside_web' => strpos(DATA_DIR, __DIR__) !== 0]);
    default: fail('Unknown operation', 404);
  }
} catch (Throwable $e) {
  error_log('tarazu: ' . $e->getMessage());
  fail('Server error. Please try again.', 500);
}
