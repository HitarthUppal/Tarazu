<?php
/* Local test router mirroring web/.htaccess (php -S). */
$u = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/nirani$#', $u)) { header('Location: /nirani/', true, 301); exit; }
if (preg_match('#^/nirani/(.*)$#', $u, $m)) $u = '/' . ($m[1] === '' ? 'index.html' : $m[1]);
if (preg_match('#^/files(/|$)#', $u)) { http_response_code(403); exit; }
if (preg_match('#^/(config\.php|seed\.json|.*\.sqlite.*)$#', $u)) { http_response_code(403); exit; }
if ($u === '/' ) $u = '/index.html';
$f = getenv('WEBROOT') . $u;
if (!is_file($f)) { http_response_code(404); exit; }
if (str_ends_with($f, '.php')) { chdir(dirname($f)); require $f; exit; }
$types = ['html' => 'text/html; charset=utf-8', 'js' => 'text/javascript', 'png' => 'image/png', 'webmanifest' => 'application/manifest+json', 'woff2' => 'font/woff2'];
if (str_ends_with($f, '.html')) {
  /* same Content-Security-Policy as web/.htaccess, so tests catch anything the policy would break */
  preg_match('#Content-Security-Policy "([^"]+)"#', file_get_contents(__DIR__ . '/../web/.htaccess'), $m);
  header('Content-Security-Policy: ' . $m[1]);
}
header('Content-Type: ' . ($types[pathinfo($f, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
readfile($f);
