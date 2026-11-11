<?php
/* Tarazu scheduled job. Run every 15 minutes by cron: php cron.php
   Sends only the notifications switched on in Master admin. Also callable over HTTPS with ?key= for a manual check.
   Once a night (after 2 AM India time) it also:
   - backs up the database (consistent snapshot), compressed and, when BACKUP_KEY is set, encrypted (AES-256-GCM);
   - keeps the newest BACKUP_KEEP copies (default 30) in DATA_DIR/backups;
   - clears expired sessions, used one-time codes and old rate counters. */
declare(strict_types=1);
define('TARAZU_LIB', true);
require __DIR__ . '/api.php';
if (PHP_SAPI !== 'cli') {
  header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  if (!hash_equals(sign('cron'), (string)($_GET['key'] ?? ''))) { http_response_code(403); echo '{"error":"forbidden"}'; exit; }
}

function nightly_backup(): ?array {
  $dir = DATA_DIR . '/backups'; if (!is_dir($dir)) mkdir($dir, 0700, true);
  $stamp = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Ymd-His');
  $tmp = $dir . '/.snap-' . $stamp . '.sqlite';
  db()->exec('VACUUM INTO ' . db()->quote($tmp));            // consistent copy while the site keeps running
  $gz = gzencode((string)file_get_contents($tmp), 9); @unlink($tmp);
  if ($gz === false) throw new RuntimeException('compress failed');
  if (defined('BACKUP_KEY') && strlen(BACKUP_KEY) >= 32) {
    /* file layout: "TZB1" | 12-byte IV | 16-byte tag | ciphertext. Restore: see docs/runbook.md */
    $key = hash('sha256', BACKUP_KEY, true); $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($gz, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new RuntimeException('encrypt failed');
    $file = "$dir/tarazu-$stamp.sqlite.gz.enc"; file_put_contents($file, 'TZB1' . $iv . $tag . $ct);
  } else {
    $file = "$dir/tarazu-$stamp.sqlite.gz"; file_put_contents($file, $gz);
  }
  chmod($file, 0600);
  $keep = defined('BACKUP_KEEP') ? (int)BACKUP_KEEP : 30;
  $all = glob($dir . '/tarazu-*.sqlite.gz*') ?: []; rsort($all);
  foreach (array_slice($all, $keep) as $old) @unlink($old);
  return ['file' => basename($file), 'bytes' => filesize($file), 'kept' => min(count($all), $keep), 'encrypted' => str_ends_with($file, '.enc')];
}

function housekeeping(): array {
  $now = microtime(true);
  $a = db()->prepare('DELETE FROM sessions WHERE expires < ? OR (revoked IS NOT NULL AND revoked < ?)'); $a->execute([$now, $now - 30 * 86400]);
  $b = db()->prepare('DELETE FROM otp WHERE expires < ?'); $b->execute([$now - 3600]);
  $c = db()->prepare('DELETE FROM rate WHERE window_start < ?'); $c->execute([$now - 86400]);
  $d = db()->prepare('DELETE FROM inbox WHERE created < ?'); $d->execute([$now - 30 * 86400]);
  return ['sessions' => $a->rowCount(), 'otp' => $b->rowCount(), 'rate' => $c->rowCount(), 'inbox' => $d->rowCount()];
}

try {
  $r = run_schedule(false); flush_pushes();
  $ist = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
  $bk = 'backup:' . $ist->format('Y-m-d');
  if ((int)$ist->format('G') >= 2 && once($bk, true)) {          // retried every run until it succeeds
    try { $r['backup'] = nightly_backup(); once($bk, false); $r['housekeeping'] = housekeeping(); }
    catch (Throwable $e) { error_log('tarazu backup: ' . $e->getMessage()); $r['backup'] = ['error' => 'failed']; }
  }
  echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}
catch (Throwable $e) { error_log('tarazu cron: ' . $e->getMessage()); http_response_code(500); echo '{"error":"failed"}', "\n"; }
