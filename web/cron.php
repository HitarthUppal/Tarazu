<?php
/* Tarazu scheduled job. Run every 15 minutes by cron: php cron.php
   Sends only the notifications switched on in Master admin. Also callable over HTTPS with ?key= for a manual check. */
declare(strict_types=1);
define('TARAZU_LIB', true);
require __DIR__ . '/api.php';
if (PHP_SAPI !== 'cli') {
  header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  if (!hash_equals(sign('cron'), (string)($_GET['key'] ?? ''))) { http_response_code(403); echo '{"error":"forbidden"}'; exit; }
}
try { $r = run_schedule(false); flush_pushes(); echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; }
catch (Throwable $e) { error_log('tarazu cron: ' . $e->getMessage()); http_response_code(500); echo '{"error":"failed"}', "\n"; }
