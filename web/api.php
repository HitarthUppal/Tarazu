<?php
/* Tarazu API: a small document store (SQLite) with PIN sign-in, role checks and web push. */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
/* secrets managed in GitHub and written by the deploy workflow, outside the web root */
if (defined('DATA_DIR') && is_file(DATA_DIR . '/secrets.php')) require_once DATA_DIR . '/secrets.php';
if (!defined('TARAZU_LIB')) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: no-referrer');
  /* The iOS and Android apps load the screens from inside the app (origin capacitor://localhost or https://localhost)
     and call this API across origins. Only those two origins are allowed; browsers on any other site are refused. */
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if (in_array($origin, ['capacitor://localhost', 'https://localhost', 'ionic://localhost'], true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Token, Authorization, X-Tarazu-Client');
    header('Access-Control-Expose-Headers: X-Tarazu-Token');
    header('Access-Control-Max-Age: 600');
  }
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}
if (!defined('SESSION_IDLE_DAYS')) define('SESSION_IDLE_DAYS', 30);
if (!defined('SESSION_MAX_DAYS')) define('SESSION_MAX_DAYS', 90);

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
  /* security tables: server-side sessions (token stored only as a hash), audit trail, one-time codes, native push tokens, rate counters */
  $pdo->exec('CREATE TABLE IF NOT EXISTS sessions(id TEXT PRIMARY KEY, token_hash TEXT UNIQUE, user_id TEXT, via_master TEXT, created REAL, last_seen REAL, expires REAL, revoked REAL, ip TEXT, client TEXT)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS i_sess_user ON sessions(user_id)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS audit(id INTEGER PRIMARY KEY AUTOINCREMENT, at REAL, user_id TEXT, via_master TEXT, client_id TEXT, action TEXT, target TEXT, meta TEXT, ip TEXT)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS i_audit_client ON audit(client_id, id)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS otp(phone TEXT PRIMARY KEY, code_hash TEXT, expires REAL, attempts INTEGER, sent REAL)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS native_push(token TEXT PRIMARY KEY, user_id TEXT, platform TEXT, created REAL)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS rate(k TEXT PRIMARY KEY, n INTEGER, window_start REAL)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT)');
  seed($pdo);
  migrate_pins($pdo);
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

/* sign-in sessions.
   A token is 32 random bytes, handed to the device once. The server keeps only its SHA-256 hash, so a copy of the
   database cannot be used to sign in. Sessions expire after SESSION_IDLE_DAYS without use and SESSION_MAX_DAYS in all,
   and can be revoked (sign out, sign out everywhere, PIN or role change, owner or master action).
   A session opened by a master admin on someone else's account lasts 12 hours and is marked with the master's id. */
function sign(string $s): string { return hash_hmac('sha256', $s, APP_SECRET); }
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64); }
function client_label(): string { return substr((string)($_SERVER['HTTP_X_TARAZU_CLIENT'] ?? 'web'), 0, 40); }
function new_session(string $uid, ?string $via_master = null): string {
  $raw = 'tz1_' . bin2hex(random_bytes(32)); $now = microtime(true);
  $life = $via_master ? 12 * 3600 : SESSION_MAX_DAYS * 86400;
  db()->prepare('INSERT INTO sessions(id,token_hash,user_id,via_master,created,last_seen,expires,revoked,ip,client) VALUES(?,?,?,?,?,?,?,NULL,?,?)')
    ->execute([bin2hex(random_bytes(8)), hash('sha256', $raw), $uid, $via_master, $now, $now, $now + $life, client_ip(), client_label()]);
  if (!defined('TARAZU_LIB')) header('X-Tarazu-Token: ' . $raw); // the phone apps keep it in Keychain / Keystore
  return $raw;
}
function make_token(string $uid): string { return new_session($uid); }
function make_token_as(string $uid, string $master): string { return new_session($uid, $master); }
function request_token(): string {
  $t = (string)($_SERVER['HTTP_X_TOKEN'] ?? '');
  if ($t === '' && preg_match('#^Bearer\s+(\S+)$#', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $m)) $t = $m[1];
  return $t;
}
function current_session(): ?array {
  static $s = false; if ($s !== false) return $s; $s = null;
  $t = request_token(); if (strncmp($t, 'tz1_', 4) !== 0 || strlen($t) !== 68) return null; // old-style tokens are no longer accepted
  $st = db()->prepare('SELECT * FROM sessions WHERE token_hash=?'); $st->execute([hash('sha256', $t)]); $r = $st->fetch(PDO::FETCH_ASSOC);
  $now = microtime(true);
  if (!$r || $r['revoked'] !== null || (float)$r['expires'] < $now || (float)$r['last_seen'] < $now - SESSION_IDLE_DAYS * 86400) return null;
  if ((float)$r['last_seen'] < $now - 300) db()->prepare('UPDATE sessions SET last_seen=?, ip=? WHERE id=?')->execute([$now, client_ip(), $r['id']]);
  $s = $r; return $s;
}
function revoke_sessions(string $uid, ?string $except_id = null): int {
  $st = db()->prepare('UPDATE sessions SET revoked=? WHERE user_id=? AND revoked IS NULL AND id IS NOT ?'); $st->execute([microtime(true), $uid, $except_id]);
  db()->prepare('DELETE FROM subs WHERE user_id=?')->execute([$uid]);
  db()->prepare('DELETE FROM native_push WHERE user_id=?')->execute([$uid]);
  return $st->rowCount();
}
function me(): ?array {
  static $u = false; if ($u !== false) return $u; $u = null;
  $s = current_session(); if (!$s) return null;
  $d = get_doc('users/' . $s['user_id']); if (!$d || ($d['active'] ?? true) === false) return null;
  if ($s['via_master']) { $m = get_doc('users/' . $s['via_master']); if (!$m || ($m['role'] ?? '') !== 'master' || ($m['active'] ?? true) === false) return null; $d['_via_master'] = $s['via_master']; }
  $d['id'] = $s['user_id']; $d['_session'] = $s['id']; $u = $d; return $u;
}

/* PINs are stored only as password hashes (pin_hash). Plain PINs from before are converted once, on first start. */
function pin_hash_of(string $pin): string { return password_hash($pin, PASSWORD_DEFAULT); }
function pin_ok(?array $d, string $pin): bool {
  if (!$d || $pin === '') { password_verify($pin, '$2y$10$Rg4bGjHC5eivuVCJPoYm9uIzZHchhZ6f.XTRAqyn3xOQp2tXzawbq'); return false; } // same time either way
  if (!empty($d['pin_hash'])) return password_verify($pin, (string)$d['pin_hash']);
  return isset($d['pin']) && hash_equals((string)$d['pin'], $pin);
}
function protect_pin(array $d): array {
  if (array_key_exists('pin', $d)) {
    $p = (string)$d['pin']; unset($d['pin']);
    if ($p !== '') { $d['pin_hash'] = pin_hash_of($p); $d['pin_default'] = in_array($p, ['0000', '1234', '1111'], true); $d['pin_set_at'] = gmdate('c'); }
  }
  return $d;
}
function migrate_pins(PDO $pdo): void {
  $st = $pdo->prepare('SELECT v FROM meta WHERE k=?'); $st->execute(['pins_hashed']); if ($st->fetchColumn()) return;
  $rows = $pdo->query("SELECT path, data FROM docs WHERE parent='users' AND deleted=0")->fetchAll(PDO::FETCH_ASSOC);
  $up = $pdo->prepare('UPDATE docs SET data=?, updated=? WHERE path=?'); $pdo->beginTransaction();
  foreach ($rows as $r) { $o = json_decode((string)$r['data'], true); if (!is_array($o) || !array_key_exists('pin', $o)) continue;
    $up->execute([json_encode(protect_pin($o), JSON_UNESCAPED_UNICODE), microtime(true), $r['path']]); }
  $pdo->prepare('INSERT OR REPLACE INTO meta(k,v) VALUES(?,?)')->execute(['pins_hashed', gmdate('c')]); $pdo->commit();
}

/* audit trail: append-only record of security-relevant actions */
function audit(?array $u, string $action, string $target = '', array $meta = []): void {
  try {
    $cid = $u['client_id'] ?? ($meta['client_id'] ?? null);
    db()->prepare('INSERT INTO audit(at,user_id,via_master,client_id,action,target,meta,ip) VALUES(?,?,?,?,?,?,?,?)')
      ->execute([microtime(true), $u['id'] ?? ($meta['user_id'] ?? null), $u['_via_master'] ?? null, $cid, $action, substr($target, 0, 200), $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, client_ip()]);
  } catch (Throwable $e) { error_log('tarazu audit: ' . $e->getMessage()); }
}

/* simple fixed-window rate limit; returns false once the limit is hit */
function rate_ok(string $k, int $max, int $window_s): bool {
  $now = microtime(true); $st = db()->prepare('SELECT n, window_start FROM rate WHERE k=?'); $st->execute([$k]); $r = $st->fetch(PDO::FETCH_ASSOC);
  if (!$r || (float)$r['window_start'] < $now - $window_s) { db()->prepare('INSERT OR REPLACE INTO rate(k,n,window_start) VALUES(?,1,?)')->execute([$k, $now]); return true; }
  if ((int)$r['n'] >= $max) return false;
  db()->prepare('UPDATE rate SET n=n+1 WHERE k=?')->execute([$k]); return true;
}

/* who may read and write what */
function can_read(?array $u, string $p, array $d): bool {
  $par = parent_of($p);
  if (!$u) {
    if ($p === 'config/auth') return true;
    $au = get_doc('config/auth') ?? [];
    if (($au['phone_required'] ?? false) === true || ($au['otp_required'] ?? false) === true) return $par === 'clients';
    return in_array($par, ['users', 'clients'], true) || $p === 'config/market';
  }
  if ($u['role'] === 'master') return true;
  $cid = $u['client_id'] ?? '';
  if ($u['role'] === 'panelist' && preg_match('#^clients/[^/]+/(rviews|outlooks|comments|pvotes)/([^/]+)$#', $p, $m)) return substr($m[2], -strlen('__' . $u['id'])) === '__' . $u['id'] && strpos($p, 'clients/' . $cid . '/') === 0;
  if ($par === 'users') return ($d['client_id'] ?? null) === $cid || ($d['role'] ?? '') === 'master';
  if ($par === 'clients') return $p === 'clients/' . $cid;
  if (in_array($par, ['reports', 'config'], true)) return true;
  return strpos($p, 'clients/' . $cid . '/') === 0;
}
function shape(?array $u, string $p, array $d): array {
  if (parent_of($p) === 'clients' && !$u) return array_intersect_key($d, array_flip(['name', 'logo', 'tagline']));
  if (parent_of($p) !== 'users') return $d;
  $mgr = $u && ($u['role'] === 'master' || ($u['role'] === 'owner' && ($d['client_id'] ?? '') === ($u['client_id'] ?? '') && ($d['role'] ?? '') !== 'master'));
  $d['has_pin'] = !empty($d['pin_hash']) || !empty($d['pin']);
  unset($d['pin'], $d['pin_hash']);
  if (!$mgr) { unset($d['pin_default'], $d['pin_set_at']); if (!$u || $u['role'] !== 'master' && ($u['id'] ?? '') !== substr($p, 6)) unset($d['phone'], $d['email']); }
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
    /* phone apps: Firebase (Android) and Apple push (iOS) */
    $st = db()->prepare('SELECT token, platform FROM native_push WHERE user_id=?'); $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) {
      try {
        $gone = $n['platform'] === 'android' ? send_fcm($n['token'], $title, mb_substr($body, 0, 180), $url) : send_apns($n['token'], $title, mb_substr($body, 0, 180), $url);
        if ($gone) db()->prepare('DELETE FROM native_push WHERE token=?')->execute([$n['token']]);
      } catch (Throwable $e) { error_log('tarazu native push: ' . $e->getMessage()); }
    }
  }
}
function route_of(string $url): string { return ltrim(preg_replace('#^\./#', '', $url), '/'); }
/* Firebase Cloud Messaging HTTP v1. FCM_SERVICE_ACCOUNT = path (outside the web root) to the service-account JSON. Returns true if the token is dead. */
function send_fcm(string $token, string $title, string $body, string $url): bool {
  if (!defined('FCM_SERVICE_ACCOUNT') || !is_file(FCM_SERVICE_ACCOUNT)) return false;
  $sa = json_decode((string)file_get_contents(FCM_SERVICE_ACCOUNT), true); if (!$sa) return false;
  $at = fcm_access_token($sa); if (!$at) return false;
  $msg = ['message' => ['token' => $token, 'notification' => ['title' => $title, 'body' => $body],
    'data' => ['route' => route_of($url)], 'android' => ['priority' => 'HIGH', 'notification' => ['channel_id' => 'tarazu', 'tag' => 'tarazu']]]];
  $ch = curl_init('https://fcm.googleapis.com/v1/projects/' . rawurlencode($sa['project_id']) . '/messages:send');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($msg), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $at]]);
  $res = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return $code === 404 || ($code === 400 && strpos($res, 'INVALID_ARGUMENT') !== false && strpos($res, 'token') !== false);
}
function fcm_access_token(array $sa): ?string {
  $st = db()->prepare('SELECT v FROM meta WHERE k=?'); $st->execute(['fcm_token']); $c = json_decode((string)$st->fetchColumn(), true);
  if ($c && ($c['exp'] ?? 0) > time() + 120) return $c['t'];
  $now = time(); $h = b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
  $c2 = b64u(json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
  if (!openssl_sign("$h.$c2", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) return null;
  $ch = curl_init('https://oauth2.googleapis.com/token');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$h.$c2." . b64u($sig)]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
  $j = json_decode((string)curl_exec($ch), true); curl_close($ch);
  if (empty($j['access_token'])) return null;
  db()->prepare('INSERT OR REPLACE INTO meta(k,v) VALUES(?,?)')->execute(['fcm_token', json_encode(['t' => $j['access_token'], 'exp' => $now + (int)($j['expires_in'] ?? 3600)])]);
  return $j['access_token'];
}
/* Apple Push Notification service over HTTP/2 with a token key (.p8). APNS_KEY_FILE, APNS_KEY_ID, APNS_TEAM_ID, APNS_TOPIC (bundle id). */
function send_apns(string $token, string $title, string $body, string $url): bool {
  if (!defined('APNS_KEY_FILE') || !is_file(APNS_KEY_FILE) || !defined('APNS_KEY_ID') || !defined('APNS_TEAM_ID')) return false;
  $st = db()->prepare('SELECT v FROM meta WHERE k=?'); $st->execute(['apns_jwt']); $c = json_decode((string)$st->fetchColumn(), true);
  if (!$c || ($c['iat'] ?? 0) < time() - 3000) {
    $h = b64u(json_encode(['alg' => 'ES256', 'kid' => APNS_KEY_ID])); $cl = b64u(json_encode(['iss' => APNS_TEAM_ID, 'iat' => time()]));
    openssl_sign("$h.$cl", $der, (string)file_get_contents(APNS_KEY_FILE), OPENSSL_ALGO_SHA256);
    $c = ['t' => "$h.$cl." . b64u(der_to_raw($der)), 'iat' => time()];
    db()->prepare('INSERT OR REPLACE INTO meta(k,v) VALUES(?,?)')->execute(['apns_jwt', json_encode($c)]);
  }
  $host = defined('APNS_SANDBOX') && APNS_SANDBOX ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
  $ch = curl_init($host . '/3/device/' . $token);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
    CURLOPT_POSTFIELDS => json_encode(['aps' => ['alert' => ['title' => $title, 'body' => $body], 'sound' => 'default'], 'route' => route_of($url)]),
    CURLOPT_HTTPHEADER => ['authorization: bearer ' . $c['t'], 'apns-topic: ' . (defined('APNS_TOPIC') ? APNS_TOPIC : 'live.tarazu.app'), 'apns-push-type: alert', 'apns-priority: 10']]);
  curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return $code === 410 || $code === 400;
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

function login_guard(array $keys): void {
  $now = microtime(true);
  foreach ($keys as $k => $lim) { $st = db()->prepare('SELECT until FROM fails WHERE k=?'); $st->execute([$k]); $x = (float)$st->fetchColumn();
    if ($x > $now) fail('Too many wrong attempts. Try again in ' . max(1, (int)ceil(($x - $now) / 60)) . ' minutes.', 429); }
}
function login_failed(array $keys): void {
  $now = microtime(true);
  foreach ($keys as $k => [$max, $secs]) {
    $st = db()->prepare('SELECT n FROM fails WHERE k=?'); $st->execute([$k]); $n = (int)$st->fetchColumn() + 1;
    db()->prepare('INSERT OR REPLACE INTO fails(k,n,until) VALUES(?,?,?)')->execute([$k, $n >= $max ? 0 : $n, $n >= $max ? $now + $secs : 0]);
  }
}
function can_manage_user(array $u, string $uid): bool {
  if ($u['role'] === 'master') return true;
  $d = get_doc('users/' . $uid);
  return $u['role'] === 'owner' && $d && ($d['client_id'] ?? '') === ($u['client_id'] ?? '') && ($d['role'] ?? '') !== 'master';
}
function mask_email(string $e): string { [$a, $b] = array_pad(explode('@', $e, 2), 2, ''); return substr($a, 0, 2) . str_repeat('•', max(1, strlen($a) - 2)) . '@' . $b; }
/* one-time code delivery, in order: WhatsApp through the Orai Cloud API (WA_API_KEY), WhatsApp through n8n (OTP_WEBHOOK),
   and email through the server's own mail (from OTP_FROM). Returns where the code went, masked. */
function wa_configured(): bool { return (defined('WA_API_KEY') && WA_API_KEY !== '') || (defined('OTP_WEBHOOK') && OTP_WEBHOOK !== ''); }
function send_whatsapp_code(string $ph, string $code): bool {
  if (!defined('WA_API_KEY') || WA_API_KEY === '' || !function_exists('curl_init')) return false;
  $body = ['type' => 'template', 'messaging_product' => 'whatsapp', 'to' => '91' . $ph,
    'template' => ['language' => ['policy' => 'deterministic', 'code' => defined('WA_TEMPLATE_LANG') ? WA_TEMPLATE_LANG : 'en_US'],
      'name' => defined('WA_TEMPLATE') && WA_TEMPLATE !== '' ? WA_TEMPLATE : 'chini_login',
      'components' => [
        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
        ['index' => 0, 'type' => 'button', 'sub_type' => 'url', 'parameters' => [['type' => 'text', 'text' => $code]]],
      ]]];
  $ch = curl_init(defined('WA_ENDPOINT') ? WA_ENDPOINT : 'https://orailap.azurewebsites.net/api/cloud/Dialog');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'API-KEY: Bearer ' . WA_API_KEY]]);
  $res = (string)curl_exec($ch); $code_http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $j = json_decode($res, true);
  $ok = $code_http >= 200 && $code_http < 300 && !(is_array($j) && (isset($j['error']) || (isset($j['success']) && $j['success'] === false)));
  if (!$ok) error_log('tarazu whatsapp otp: HTTP ' . $code_http . ' ' . substr(preg_replace('#\d{6}#', '******', $res), 0, 300));
  return $ok;
}
function send_otp(array $d, string $ph, string $code): array {
  $sent = [];
  if (send_whatsapp_code($ph, $code)) $sent[] = 'WhatsApp ••••' . substr($ph, -4);
  elseif (defined('OTP_WEBHOOK') && OTP_WEBHOOK !== '' && function_exists('curl_init')) {
    $ch = curl_init(OTP_WEBHOOK);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['phone' => '91' . $ph, 'code' => $code, 'name' => $d['name'] ?? '']),
      CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], n8n_auth_header()), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    curl_exec($ch); if ((int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) $sent[] = 'WhatsApp ••••' . substr($ph, -4); curl_close($ch);
  }
  $email = trim((string)($d['email'] ?? ''));
  if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $from = defined('OTP_FROM') ? OTP_FROM : 'no-reply@tarazu.live';
    $body = "Your Tarazu sign-in code is $code\r\n\r\nIt works for 5 minutes. If you did not try to sign in, tell your administrator.\r\n\r\nTarazu, Sugar Sale Intelligence System, powered by AgriMandi";
    $hdr = "From: Tarazu <$from>\r\nReply-To: $from\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: Tarazu";
    if (@mail($email, "Tarazu sign-in code: $code", $body, $hdr, '-f' . $from)) $sent[] = 'email ' . mask_email($email);
  }
  return $sent;
}
function n8n_auth_header(): array { return defined('N8N_KEY') && N8N_KEY !== '' ? ['X-Tarazu-Key: ' . N8N_KEY] : []; }

if (!defined('TARAZU_LIB')) try {
  switch ($op) {
    case 'sync': {
      $u = me(); $since = (float)($in['since'] ?? 0); $now = microtime(true);
      $st = db()->prepare('SELECT path, data, deleted FROM docs WHERE updated > ? ORDER BY updated'); $st->execute([$since]);
      $docs = [];
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['deleted'] === 1) { if ($since > 0) $docs[] = ['path' => $r['path'], 'deleted' => true]; continue; }
        $d = json_decode($r['data'], true) ?: []; $o = json_decode($r['data'], false);
        if (!can_read($u, $r['path'], $d)) continue;
        $docs[] = ['path' => $r['path'], 'data' => (parent_of($r['path']) === 'users' || (!$u && parent_of($r['path']) === 'clients')) ? shape($u, $r['path'], $d) : $o];
      }
      out(['now' => $now, 'docs' => $docs, 'signed_in' => (bool)$u]);
    }
    case 'login': {
      $uid = (string)($in['user_id'] ?? ''); $pin = (string)($in['pin'] ?? '');
      if (!preg_match('#^[A-Za-z0-9_\-]{1,64}$#', $uid)) fail('Unknown user', 401);
      $auth = get_doc('config/auth') ?? [];
      if (($auth['phone_required'] ?? false) === true || ($auth['otp_required'] ?? false) === true) fail('Sign in with your mobile number.', 403);
      usleep(250000);
      login_guard(['u:' . $uid => [5, 900], 'ip:' . client_ip() => [20, 900]]);
      $d = get_doc('users/' . $uid);
      if (!$d || ($d['active'] ?? true) === false || !pin_ok($d, $pin)) {
        login_failed(['u:' . $uid => [5, 900], 'ip:' . client_ip() => [20, 900]]); audit(null, 'login_fail', $uid, ['user_id' => $uid, 'how' => 'pin']);
        fail('That PIN does not match.', 401);
      }
      db()->prepare('DELETE FROM fails WHERE k=?')->execute(['u:' . $uid]);
      if (empty($d['pin_hash'])) put_doc('users/' . $uid, protect_pin($d)); // old plain PIN: convert on first use
      audit(['id' => $uid, 'client_id' => $d['client_id'] ?? null], 'login', $uid, ['how' => 'pin']);
      out(['token' => make_token($uid), 'user_id' => $uid, 'role' => $d['role'] ?? '']);
    }
    case 'impersonate': {
      $u = me(); if (!$u || ($u['role'] ?? '') !== 'master' || isset($u['_via_master'])) fail('Only master admin can do this.', 403);
      $uid = (string)($in['user_id'] ?? ''); if (!preg_match('#^[A-Za-z0-9_\-]{1,64}$#', $uid)) fail('Unknown user', 404);
      $d = get_doc('users/' . $uid); if (!$d || ($d['active'] ?? true) === false) fail('Unknown user', 404);
      audit($u, 'impersonate', $uid, ['client_id' => $d['client_id'] ?? null, 'as_role' => $d['role'] ?? '']);
      if (($d['role'] ?? '') === 'master') out(['token' => make_token($uid), 'role' => 'master']);
      out(['token' => make_token_as($uid, $u['id']), 'role' => $d['role'] ?? '', 'via_master' => true]);
    }
    case 'login_phone': {
      /* mobile number + PIN; when config/auth.otp_required is on, also a 6-digit one-time code sent by email / WhatsApp */
      $ph = norm_phone($in['phone'] ?? ''); $pin = (string)($in['pin'] ?? ''); $otp = preg_replace('#\D#', '', (string)($in['otp'] ?? ''));
      if (!preg_match('#^\d{10}$#', $ph)) fail('Enter your 10-digit mobile number.', 400);
      usleep(250000);
      $keys = ['p:' . $ph => [5, 900], 'ip:' . client_ip() => [20, 900]];
      login_guard($keys);
      $uid = null; $d = null;
      foreach (docs_under('users') as $id => $doc) if (($doc['active'] ?? true) !== false && norm_phone($doc['phone'] ?? '') === $ph) { $uid = $id; $d = $doc; break; }
      if (!$d || !pin_ok($d, $pin)) {
        login_failed($keys); audit(null, 'login_fail', 'phone:' . substr($ph, -4), ['user_id' => $uid, 'how' => 'phone']);
        fail('That number and PIN do not match.', 401);
      }
      /* otp_exempt: only for the app-store reviewer account, settable by master admin only (see can_write rule below) */
      if (((get_doc('config/auth') ?? [])['otp_required'] ?? false) === true && ($d['otp_exempt'] ?? false) !== true) {
        if ($otp === '') {
          if (!rate_ok('otp2:' . $ph, 10, 600) || !rate_ok('otpip2:' . client_ip(), 40, 3600)) fail('Too many codes requested. Try again in a few minutes.', 429);
          $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
          $sent = send_otp($d, $ph, $code);
          if (!$sent) fail(wa_configured() ? 'Your sign-in code could not be sent on WhatsApp. Try again in a minute, or ask your administrator to add your email address.' : 'Your sign-in code could not be sent. Ask your administrator to add your email address.', 503);
          db()->prepare('INSERT OR REPLACE INTO otp(phone,code_hash,expires,attempts,sent) VALUES(?,?,?,0,?)')->execute([$ph, hash_hmac('sha256', $ph . '|' . $code, APP_SECRET), microtime(true) + 300, microtime(true)]);
          audit(['id' => $uid, 'client_id' => $d['client_id'] ?? null], 'otp_sent', $uid, ['to' => $sent]);
          out(['otp_needed' => true, 'sent_to' => $sent]);
        }
        $st = db()->prepare('SELECT * FROM otp WHERE phone=?'); $st->execute([$ph]); $o = $st->fetch(PDO::FETCH_ASSOC);
        $good = $o && (float)$o['expires'] > microtime(true) && (int)$o['attempts'] < 5 && hash_equals((string)$o['code_hash'], hash_hmac('sha256', $ph . '|' . $otp, APP_SECRET));
        if (!$good) {
          if ($o) db()->prepare('UPDATE otp SET attempts=attempts+1 WHERE phone=?')->execute([$ph]);
          login_failed($keys); audit(['id' => $uid, 'client_id' => $d['client_id'] ?? null], 'login_fail', $uid, ['how' => 'otp']);
          fail($o && (float)$o['expires'] <= microtime(true) ? 'That code has expired. Request a new one.' : 'That code does not match.', 401);
        }
        db()->prepare('DELETE FROM otp WHERE phone=?')->execute([$ph]);
      }
      db()->prepare('DELETE FROM fails WHERE k=?')->execute(['p:' . $ph]);
      if (empty($d['pin_hash'])) put_doc('users/' . $uid, protect_pin($d));
      audit(['id' => $uid, 'client_id' => $d['client_id'] ?? null], 'login', $uid, ['how' => $otp !== '' ? 'phone+otp' : 'phone']);
      out(['token' => make_token($uid), 'user_id' => $uid, 'role' => $d['role'] ?? '']);
    }
    case 'otp_request': {
      /* step 1 of code sign-in: mobile number in, code out on WhatsApp (email as backup).
         The answer is the same whether or not the number is registered, so the user list cannot be probed. */
      if (((get_doc('config/auth') ?? [])['otp_required'] ?? false) !== true) fail('Sign in with your mobile number and PIN.', 409);
      $ph = norm_phone($in['phone'] ?? '');
      if (!preg_match('#^[6-9]\d{9}$#', $ph)) fail('Enter your 10-digit mobile number.', 400);
      if (!rate_ok('otp2:' . $ph, 10, 600) || !rate_ok('otpip2:' . client_ip(), 40, 3600)) fail('Too many codes requested. Try again in a few minutes.', 429);
      usleep(200000);
      $uid = null; $d = null;
      foreach (docs_under('users') as $id => $doc) if (($doc['active'] ?? true) !== false && norm_phone($doc['phone'] ?? '') === $ph) { $uid = $id; $d = $doc; break; }
      $generic = ['ok' => true, 'masked' => '+91 ' . substr($ph, 0, 2) . '•••••' . substr($ph, -3)];
      if (!$d) { audit(null, 'otp_unknown', 'phone:' . substr($ph, -4)); out($generic); }
      if (($d['otp_exempt'] ?? false) === true) out($generic + ['pin_instead' => true]);   // store-review account signs in with its PIN
      $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
      $sent = send_otp($d, $ph, $code);
      if (!$sent) { audit(['id' => $uid, 'client_id' => $d['client_id'] ?? null], 'otp_failed', $uid); fail('The code could not be sent right now. Please try again in a minute.', 503); }
      db()->prepare('INSERT OR REPLACE INTO otp(phone,code_hash,expires,attempts,sent) VALUES(?,?,?,0,?)')->execute([$ph, hash_hmac('sha256', $ph . '|' . $code, APP_SECRET), microtime(true) + 300, microtime(true)]);
      audit(['id' => $uid, 'client_id' => $d['client_id'] ?? null], 'otp_sent', $uid, ['to' => $sent]);
      out($generic);
    }
    case 'otp_verify': {
      /* step 2: mobile number + code in, session out */
      $ph = norm_phone($in['phone'] ?? ''); $otp = preg_replace('#\D#', '', (string)($in['otp'] ?? ''));
      if (!preg_match('#^\d{10}$#', $ph) || strlen($otp) !== 6) fail('Enter the 6-digit code.', 400);
      usleep(250000);
      $keys = ['p:' . $ph => [5, 900], 'ip:' . client_ip() => [20, 900]];
      login_guard($keys);
      $st = db()->prepare('SELECT * FROM otp WHERE phone=?'); $st->execute([$ph]); $o = $st->fetch(PDO::FETCH_ASSOC);
      $good = $o && (float)$o['expires'] > microtime(true) && (int)$o['attempts'] < 5 && hash_equals((string)$o['code_hash'], hash_hmac('sha256', $ph . '|' . $otp, APP_SECRET));
      $uid = null; $d = null;
      if ($good) foreach (docs_under('users') as $id => $doc) if (($doc['active'] ?? true) !== false && norm_phone($doc['phone'] ?? '') === $ph) { $uid = $id; $d = $doc; break; }
      if (!$good || !$d) {
        if ($o) db()->prepare('UPDATE otp SET attempts=attempts+1 WHERE phone=?')->execute([$ph]);
        login_failed($keys); audit(null, 'login_fail', 'phone:' . substr($ph, -4), ['how' => 'otp']);
        fail($o && (float)$o['expires'] <= microtime(true) ? 'That code has expired. Tap Resend code.' : 'That code does not match.', 401);
      }
      db()->prepare('DELETE FROM otp WHERE phone=?')->execute([$ph]);
      db()->prepare('DELETE FROM fails WHERE k=?')->execute(['p:' . $ph]);
      audit(['id' => $uid, 'client_id' => $d['client_id'] ?? null], 'login', $uid, ['how' => 'otp']);
      out(['token' => make_token($uid), 'user_id' => $uid, 'role' => $d['role'] ?? '']);
    }
    case 'logout': {
      $u = me(); if ($u) { db()->prepare('UPDATE sessions SET revoked=? WHERE id=?')->execute([microtime(true), $u['_session']]); audit($u, 'logout'); }
      if (!defined('TARAZU_LIB')) header('X-Tarazu-Token: revoked');
      out(['ok' => true]);
    }
    case 'logout_all': {
      $u = me(); if (!$u) fail('Please sign in again.', 401);
      $n = revoke_sessions($u['id'], $u['_session']); audit($u, 'logout_all', $u['id'], ['sessions' => $n]);
      out(['ok' => true, 'signed_out' => $n]);
    }
    case 'sessions': {
      /* own devices; owner sees their client's people; master sees everyone. Optional user_id. */
      $u = me(); if (!$u) fail('Please sign in again.', 401);
      $uid = (string)($in['user_id'] ?? $u['id']);
      if ($uid !== $u['id'] && !can_manage_user($u, $uid)) fail('You do not have permission to see this.', 403);
      $st = db()->prepare('SELECT id, created, last_seen, expires, client, ip, via_master FROM sessions WHERE user_id=? AND revoked IS NULL AND expires>? ORDER BY last_seen DESC LIMIT 50');
      $st->execute([$uid, microtime(true)]);
      $rows = array_map(function ($r) use ($u) { $r['current'] = $r['id'] === ($u['_session'] ?? ''); $r['ip'] = preg_replace('#\.\d+$#', '.x', (string)$r['ip']); return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
      out(['sessions' => $rows]);
    }
    case 'revoke_sessions': {
      $u = me(); if (!$u) fail('Please sign in again.', 401);
      $uid = (string)($in['user_id'] ?? ''); $sid = (string)($in['session_id'] ?? '');
      if ($uid !== $u['id'] && !can_manage_user($u, $uid)) fail('You do not have permission to do this.', 403);
      if ($sid !== '') { $st = db()->prepare('UPDATE sessions SET revoked=? WHERE id=? AND user_id=? AND revoked IS NULL'); $st->execute([microtime(true), $sid, $uid]); $n = $st->rowCount(); }
      else $n = revoke_sessions($uid, $uid === $u['id'] ? $u['_session'] : null);
      audit($u, 'revoke_sessions', $uid, ['sessions' => $n, 'client_id' => get_doc('users/' . $uid)['client_id'] ?? null]);
      out(['ok' => true, 'signed_out' => $n]);
    }
    case 'audit_list': {
      $u = me(); if (!$u || !in_array($u['role'], ['master', 'owner'], true)) fail('You do not have permission to see this.', 403);
      $before = (int)($in['before'] ?? PHP_INT_MAX); $lim = max(1, min(200, (int)($in['limit'] ?? 100)));
      if ($u['role'] === 'master') { $st = db()->prepare('SELECT * FROM audit WHERE id<? ORDER BY id DESC LIMIT ' . $lim); $st->execute([$before]); }
      else { $st = db()->prepare('SELECT * FROM audit WHERE id<? AND client_id=? ORDER BY id DESC LIMIT ' . $lim); $st->execute([$before, $u['client_id'] ?? '']); }
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      foreach ($rows as &$r) { $r['meta'] = $r['meta'] ? json_decode($r['meta'], true) : null; $r['ip'] = preg_replace('#\.\d+$#', '.x', (string)$r['ip']); }
      out(['entries' => $rows]);
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
      $is_user = parent_of($p) === 'users';
      if ($is_user && array_key_exists('otp_exempt', $in['data'] ?? []) && $u['role'] !== 'master') fail('Only master admin can change this.', 403);
      if ($is_user && isset($in['data']['pin']) && (string)$in['data']['pin'] !== '' && !preg_match('#^\d{4,6}$#', (string)$in['data']['pin'])) fail('PIN must be 4 to 6 digits');
      if (parent_of($p) === 'users' && is_array($new) && ($new['phone'] ?? '') !== '' && isset($in['data']['phone'])) {
        $ph = norm_phone($new['phone']); if (!preg_match('#^[6-9]\d{9}$#', $ph)) fail('Enter a 10-digit Indian mobile number.');
        foreach (docs_under('users') as $id => $doc) if ('users/' . $id !== $p && ($doc['active'] ?? true) !== false && norm_phone($doc['phone'] ?? '') === $ph) fail('That number already belongs to ' . ($doc['name'] ?? 'someone else') . '.');
      }
      if ($p === 'config/auth' && (($new['phone_required'] ?? false) === true || ($new['otp_required'] ?? false) === true)) {
        $mine = get_doc('users/' . $u['id']); if (norm_phone($mine['phone'] ?? '') === '') fail('Add your own mobile number first, so you are not locked out.');
      }
      if ($p === 'config/auth' && ($new['otp_required'] ?? false) === true && !(($old['otp_required'] ?? false) === true)) {
        $mine = get_doc('users/' . $u['id']);
        if (!filter_var((string)($mine['email'] ?? ''), FILTER_VALIDATE_EMAIL) && !wa_configured()) fail('Add your own email address first, so your sign-in code can reach you.');
      }
      if ($op === 'delete') del_doc($p);
      else {
        $dataObj = is_object($INOBJ) && isset($INOBJ->data) && is_object($INOBJ->data) ? $INOBJ->data : (object)$data;
        if ($op === 'update') { $st = db()->prepare('SELECT data FROM docs WHERE path=? AND deleted=0'); $st->execute([$p]); $raw = $st->fetchColumn();
          $obj = $raw ? json_decode($raw, false) : new stdClass(); if (!is_object($obj)) $obj = new stdClass(); foreach ($dataObj as $k => $v) $obj->$k = $v; }
        else $obj = $dataObj;
        if (isset($u['_via_master']) && strpos($p, 'clients/') === 0 && is_object($obj)) $obj->acted_by_master = $u['_via_master'];
        if ($is_user) {
          /* the screens never see pin_hash, so keep it unless a new PIN was typed; computed fields are dropped */
          $arr = json_decode(json_encode($obj), true) ?: [];
          unset($arr['has_pin'], $arr['pin_hash']);
          if (!isset($arr['pin']) || (string)$arr['pin'] === '') { unset($arr['pin']); foreach (['pin_hash', 'pin_default', 'pin_set_at'] as $k) if (isset($old[$k])) $arr[$k] = $old[$k]; }
          $arr = protect_pin($arr);
          $obj = (object)$arr;
        }
        put_doc($p, $obj);
      }
      if ($is_user) {
        $uid = substr($p, 6); $chg = [];
        if ($op === 'delete') $chg[] = 'deleted';
        else {
          if (isset($in['data']['pin']) && (string)$in['data']['pin'] !== '') $chg[] = 'pin';
          foreach (['role', 'active', 'phone', 'client_id', 'email'] as $k) if (array_key_exists($k, $in['data'] ?? []) && ($old[$k] ?? null) !== ($new[$k] ?? null)) $chg[] = $k;
          if (!$old) $chg[] = 'created';
        }
        if ($chg) {
          audit($u, 'user_change', $uid, ['fields' => $chg, 'client_id' => $new['client_id'] ?? ($old['client_id'] ?? null)]);
          /* a new PIN, a new role, deactivation or deletion signs that person out of every device */
          if (array_intersect($chg, ['pin', 'role', 'active', 'deleted', 'client_id'])) revoke_sessions($uid, $uid === $u['id'] ? ($u['_session'] ?? null) : null);
        }
      } elseif ($op === 'delete') audit($u, 'delete', $p);
      elseif (preg_match('#^clients/[^/]+/rounds/#', $p) && ($old['status'] ?? '') !== 'decided' && ($new['status'] ?? '') === 'decided') audit($u, 'round_decided', $p);
      elseif (strpos($p, 'config/') === 0) audit($u, 'config_change', $p);
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
      $ch = curl_init(N8N_BASE . '/webhook/' . (defined('HOOK_MARKET') ? HOOK_MARKET : 'tarazu-market-q8m4z2'));
      curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], n8n_auth_header()), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10]);
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
      $dir = DATA_DIR . '/files'; if (!is_dir($dir)) mkdir($dir, 0750, true);
      file_put_contents($dir . '/' . $name, $bin);
      audit($u, 'upload', $name, ['bytes' => strlen($bin)]);
      out(['url' => 'api.php?op=file&name=' . rawurlencode($name)]);
    }
    case 'file_link': {
      /* report PDFs are not public: a signed-in owner, manager or master gets a link that works for 10 minutes */
      $u = me(); if (!$u) fail('Please sign in again.', 401);
      if (!in_array($u['role'], ['master', 'owner', 'manager'], true)) fail('Reports are not available to your role.', 403);
      $name = basename(preg_replace('#^.*[?&]name=#', '', rawurldecode((string)($in['name'] ?? ''))));
      $name = preg_replace('#[^A-Za-z0-9_\-.]#', '_', $name);
      if (!is_file(DATA_DIR . '/files/' . $name) && !is_file(__DIR__ . '/files/' . $name)) fail('That file is not on the server.', 404);
      $exp = time() + 600;
      out(['url' => 'api.php?op=file&name=' . rawurlencode($name) . '&exp=' . $exp . '&sig=' . sign('file|' . $name . '|' . $exp)]);
    }
    case 'file': {
      $name = preg_replace('#[^A-Za-z0-9_\-.]#', '_', basename((string)($_GET['name'] ?? '')));
      $exp = (int)($_GET['exp'] ?? 0); $sig = (string)($_GET['sig'] ?? '');
      if ($exp < time() || !hash_equals(sign('file|' . $name . '|' . $exp), $sig)) { http_response_code(403); header('Content-Type: text/plain'); echo 'This link has expired. Open the report again from Tarazu.'; exit; }
      $f = is_file(DATA_DIR . '/files/' . $name) ? DATA_DIR . '/files/' . $name : __DIR__ . '/files/' . $name;
      if (!is_file($f)) { http_response_code(404); exit; }
      header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $name . '"'); header('Cache-Control: private, no-store');
      header('Content-Length: ' . filesize($f)); readfile($f); exit;
    }
    case 'flow': {
      /* The AI workflows (n8n) are reached only through here: signed-in users, per-person limits, and a shared key n8n checks.
         The webhook addresses never appear in the page. */
      $u = me(); if (!$u) fail('Please sign in again.', 401);
      $FLOWS = [
        'Brief In'      => ['hook' => defined('HOOK_BRIEF') ? HOOK_BRIEF : 'tarazu-brief-q8m4z2',   'roles' => ['master', 'owner', 'manager'], 'max' => 30, 'audit' => true],
        'Voice Note In' => ['hook' => defined('HOOK_VOICE') ? HOOK_VOICE : 'tarazu-voice-q8m4z2',   'roles' => ['master', 'owner', 'manager', 'panelist'], 'max' => 60, 'audit' => false],
        'Report In'     => ['hook' => defined('HOOK_REPORT') ? HOOK_REPORT : 'tarazu-report-q8m4z2', 'roles' => ['master'], 'max' => 20, 'audit' => true],
        'Market In'     => ['hook' => defined('HOOK_MARKET') ? HOOK_MARKET : 'tarazu-market-q8m4z2', 'roles' => ['master', 'owner', 'manager'], 'max' => 20, 'audit' => false],
      ];
      $t = (string)($_GET['trigger'] ?? ''); $f = $FLOWS[$t] ?? null; if (!$f) fail('Unknown workflow', 404);
      if (!in_array($u['role'], $f['roles'], true)) fail('This is not available to your role.', 403);
      if (strlen($RAW) > 20000000) fail('That file is too large.', 413);
      if (!rate_ok('flow:' . $t . ':' . $u['id'], $f['max'], 3600)) fail('Too many requests in the last hour. Please wait a little.', 429);
      if (function_exists('set_time_limit')) @set_time_limit(330);
      ignore_user_abort(true);
      $ch = curl_init(N8N_BASE . '/webhook/' . $f['hook']);
      curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $RAW, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 320, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'X-Tarazu-User: ' . $u['id'], 'X-Tarazu-Client: ' . ($u['client_id'] ?? '')], n8n_auth_header())]);
      $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
      if ($f['audit']) audit($u, 'ai_' . strtolower(strtok($t, ' ')), $t, ['status' => $code]);
      if (!is_string($res) || $code < 200 || $code >= 300) fail('The workflow did not answer (' . $code . ').', 502);
      http_response_code(200); echo $res; exit;
    }
    case 'push_native_register': {
      $u = me(); if (!$u) fail('Please sign in again.', 401);
      $pf = (string)($in['platform'] ?? ''); $tok = (string)($in['token'] ?? '');
      if (!in_array($pf, ['ios', 'android'], true) || !preg_match('#^[A-Za-z0-9:_\-]{20,4096}$#', $tok)) fail('Bad push token');
      db()->prepare('INSERT OR REPLACE INTO native_push(token,user_id,platform,created) VALUES(?,?,?,?)')->execute([$tok, $u['id'], $pf, microtime(true)]);
      out(['ok' => true]);
    }
    case 'health': out(['ok' => true, 'sqlite' => extension_loaded('pdo_sqlite'), 'openssl' => extension_loaded('openssl'), 'curl' => extension_loaded('curl'), 'data_dir_outside_web' => strpos(DATA_DIR, __DIR__) !== 0,
      'n8n_key' => defined('N8N_KEY') && N8N_KEY !== '', 'whatsapp_otp' => defined('WA_API_KEY') && WA_API_KEY !== '', 'fcm' => defined('FCM_SERVICE_ACCOUNT') && is_file(FCM_SERVICE_ACCOUNT), 'apns' => defined('APNS_KEY_FILE') && is_file(APNS_KEY_FILE)]);
    default: fail('Unknown operation', 404);
  }
} catch (Throwable $e) {
  error_log('tarazu: ' . $e->getMessage());
  fail('Server error. Please try again.', 500);
}
