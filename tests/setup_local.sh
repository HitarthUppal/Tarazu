#!/bin/bash
# Builds a throwaway local copy of the site with test data. Usage: tests/setup_local.sh [port]
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"; PORT=${1:-8097}; ROOT=/tmp/tz-local; rm -rf $ROOT; mkdir -p $ROOT/www $ROOT/data
cp -r "$HERE/../web/." $ROOT/www/
cat > $ROOT/www/config.php <<PHP
<?php
define('DATA_DIR', '$ROOT/data');
define('APP_SECRET', '$(openssl rand -hex 32)');
define('VAPID_PUBLIC', 'test');
define('VAPID_PRIVATE_PEM', '');
define('VAPID_SUBJECT', 'mailto:test@example.com');
define('N8N_BASE', 'http://127.0.0.1:8098');
define('N8N_KEY', 'test-shared-key');
define('WA_API_KEY', 'test-wa-key');
define('SALES_KEY', 'test-sales-key');
define('WA_ENDPOINT', 'http://127.0.0.1:8098/wa');
define('BACKUP_KEY', '$(openssl rand -hex 32)');
PHP
python3 - $ROOT/www/seed.json <<'PY'
import json,sys
u=lambda i,n,r,c,ph,pin,em='':{"path":f"users/{i}","data":{"name":n,"role":r,"client_id":c,"phone":ph,"pin":pin,"email":em,"active":True}}
docs=[u("m1","Test Master","master",None,"9000000001","1111","master@example.com"),
      u("o1","Test Owner","owner","nirani","9000000002","0000","owner@example.com"),
      u("mg1","Test Manager","manager","nirani","9000000003","2222"),
      u("p1","Advisor One","panelist","nirani","9000000004","3333"),
      u("x1","Other Owner","owner","other","9000000009","9999"),
      {"path":"clients/nirani","data":{"name":"Nirani Group","settings":{"rate":9}}},
      {"path":"clients/other","data":{"name":"Other Co"}},
      {"path":"config/auth","data":{"phone_required":False}}]
json.dump(docs,open(sys.argv[1],'w'))
PY
# fake n8n that checks the shared key
cat > $ROOT/n8n.php <<'PHP'
<?php header('Content-Type: application/json');
if (str_starts_with($_SERVER['REQUEST_URI'], '/wa')) {   // stand-in for the Orai WhatsApp API
  file_put_contents('/tmp/tz-local/wa.jsonl', json_encode(['key' => $_SERVER['HTTP_API_KEY'] ?? '', 'body' => json_decode(file_get_contents('php://input'), true)]) . "\n", FILE_APPEND);
  if (is_file('/tmp/tz-local/wa-fail')) { http_response_code(500); echo '{"error":"down"}'; exit; }
  echo '{"messages":[{"id":"wamid.test"}]}'; exit;
}
if (($_SERVER['HTTP_X_TAROZU_KEY'] ?? $_SERVER['HTTP_X_TARAZU_KEY'] ?? '') !== 'test-shared-key') { http_response_code(403); echo '{"error":"no key"}'; exit; }
echo json_encode(['ok' => true, 'path' => $_SERVER['REQUEST_URI'], 'user' => $_SERVER['HTTP_X_TARAZU_USER'] ?? null]);
PHP
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null || true; pkill -f "php -S 127.0.0.1:8098" 2>/dev/null || true
cd $ROOT && WEBROOT=$ROOT/www setsid php -d sendmail_path="$HERE/fake_sendmail.sh" -S 127.0.0.1:$PORT "$HERE/router.php" > $ROOT/server.log 2>&1 < /dev/null &
cd $ROOT && setsid php -S 127.0.0.1:8098 n8n.php > $ROOT/n8n.log 2>&1 < /dev/null &
sleep 1; echo "local site on http://127.0.0.1:$PORT"
