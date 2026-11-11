#!/usr/bin/env python3
"""Security tests for web/api.php against the local copy (tests/setup_local.sh). Run: python3 tests/test_api_security.py"""
import json, re, sqlite3, subprocess, time, urllib.request, urllib.error, os, glob, gzip, hashlib, sys
BASE = os.environ.get('TZ_BASE', 'http://127.0.0.1:8097')
ROOT = '/tmp/tz-local'
passed = failed = 0
def check(name, cond, extra=''):
    global passed, failed
    if cond: passed += 1; print('  ok  ', name)
    else: failed += 1; print('  FAIL', name, extra)

def call(op, body=None, token=None, headers=None, method='POST', raw=False, qs=''):
    h = {'Content-Type': 'application/json'}
    if token: h['X-Token'] = token
    h.update(headers or {})
    data = None if method in ('GET', 'OPTIONS') else json.dumps(body or {}).encode()
    req = urllib.request.Request(f'{BASE}/api.php?op={op}{qs}', data=data, headers=h, method=method)
    try:
        r = urllib.request.urlopen(req, timeout=30); code, hd, txt = r.status, dict(r.headers), r.read()
    except urllib.error.HTTPError as e:
        code, hd, txt = e.code, dict(e.headers), e.read()
    if raw: return code, hd, txt
    try: j = json.loads(txt or b'{}')
    except Exception: j = {'_raw': txt[:200]}
    return code, hd, j

def get(path, headers=None):
    req = urllib.request.Request(BASE + path, headers=headers or {})
    try: r = urllib.request.urlopen(req, timeout=15); return r.status, r.read()
    except urllib.error.HTTPError as e: return e.code, e.read()

def login(uid, pin): return call('login', {'user_id': uid, 'pin': pin})
def db(): return sqlite3.connect(f'{ROOT}/data/tarazu.sqlite')

print('Signed-out access')
c, _, j = call('sync', {})
users = [d for d in j['docs'] if d['path'].startswith('users/')]
check('signed-out sync shows names only while phone sign-in is off', users and all(set(d['data']) <= {'name','role','client_id','active'} for d in users), users[:1])
rows = db().execute("select data from docs where parent='users'").fetchall()
check('no plain PIN left in the database after first start', all('"pin":' not in r[0] for r in rows) and all('pin_hash' in r[0] for r in rows))

print('Sign-in and sessions')
c, h, j = login('o1', '9999'); check('wrong PIN refused', c == 401)
c, h, j = login('o1', '0000'); OWNER = j.get('token', '')
check('right PIN gives a tz1_ session token', c == 200 and OWNER.startswith('tz1_') and len(OWNER) == 68)
check('token also sent in X-Tarazu-Token for the apps', h.get('X-Tarazu-Token') == OWNER)
check('token stored only as a hash', db().execute('select count(*) from sessions where token_hash=?', (hashlib.sha256(OWNER.encode()).hexdigest(),)).fetchone()[0] == 1 and db().execute("select count(*) from sessions where token_hash like 'tz1_%'").fetchone()[0] == 0)
c, _, j = call('sync', {}, OWNER); check('owner signed in', j.get('signed_in') is True)
ou = {d['path']: d['data'] for d in j['docs'] if d['path'].startswith('users/')}
check('owner never receives PINs or hashes', all('pin' not in u and 'pin_hash' not in u for u in ou.values()))
check('owner sees has_pin and default-PIN flags', ou['users/o1'].get('has_pin') is True and ou['users/o1'].get('pin_default') is True)
check('owner cannot see the other client', 'users/x1' not in ou and not any(d['path'] == 'clients/other' for d in j['docs']))
c, _, j = call('sync', {}, 'MS4yfDE3OTk5OTk5OTk=.deadbeef'); check('old-style signed tokens rejected', j.get('signed_in') is False)
c, _, j = call('sync', {}, headers={'Authorization': 'Bearer ' + OWNER}); check('Bearer header accepted (phone apps)', j.get('signed_in') is True)
for i in range(5): login('mg1', '0001')
c, _, j = login('mg1', '2222'); check('lockout after 5 wrong PINs, even with the right one', c == 429, c)
db().execute("delete from fails").connection.commit() if False else None
con = db(); con.execute('delete from fails'); con.commit()

print('PIN changes')
c, _, j = login('p1', '3333'); P1 = j['token']
c, _, j = call('update', {'path': 'users/p1', 'data': {'pin': '4444'}}, OWNER); check('owner resets an advisor PIN', c == 200, j)
c, _, j = call('sync', {}, P1); check('advisor signed out everywhere after PIN reset', j.get('signed_in') is False)
check('old PIN no longer works', login('p1', '3333')[0] == 401)
c, _, j = login('p1', '4444'); P1 = j['token']; check('new PIN works', c == 200)
c, _, j = call('set', {'path': 'users/p1', 'data': {'name': 'Advisor One', 'role': 'panelist', 'client_id': 'nirani', 'phone': '9000000004', 'active': True}}, OWNER)
check('full save without a PIN keeps the PIN', c == 200 and login('p1', '4444')[0] == 200)
c, _, j = call('update', {'path': 'users/p1', 'data': {'pin': '12ab'}}, OWNER); check('bad PIN refused', c == 400)
c, _, j = call('update', {'path': 'users/x1', 'data': {'pin': '1234'}}, OWNER); check('owner cannot touch another client\'s people', c == 403)
c, _, j = call('update', {'path': 'users/o1', 'data': {'pin': '5555'}}, P1); check('advisor cannot change PINs', c == 403)

print('Sign out')
c, _, j = login('mg1', '2222'); MG = j['token']; c, _, j = login('mg1', '2222'); MG2 = j['token']
c, _, j = call('sessions', {}, MG); check('manager sees own devices', c == 200 and len(j['sessions']) >= 2)
c, h, j = call('logout', {}, MG); check('sign out revokes the session', call('sync', {}, MG)[2].get('signed_in') is False and h.get('X-Tarazu-Token') == 'revoked')
check('other device still signed in', call('sync', {}, MG2)[2].get('signed_in') is True)
c, _, j = login('mg1', '2222'); MG3 = j['token']; c, _, j = call('logout_all', {}, MG3)
check('sign out other devices', j.get('signed_out', 0) >= 1 and call('sync', {}, MG2)[2].get('signed_in') is False and call('sync', {}, MG3)[2].get('signed_in') is True)
c, _, j = call('revoke_sessions', {'user_id': 'x1'}, OWNER); check('owner cannot sign out another client\'s people', c == 403)
c, _, j = call('revoke_sessions', {'user_id': 'mg1'}, OWNER); check('owner can sign out own people', c == 200 and call('sync', {}, MG3)[2].get('signed_in') is False)

print('Master and phone-only mode')
c, _, j = login('m1', '1111'); MASTER = j['token']
c, _, j = call('impersonate', {'user_id': 'p1'}, MASTER); AS = j.get('token', '')
c, _, j = call('sync', {}, AS); check('master can open an account (12 h session)', j.get('signed_in') is True)
check('opening an account is audited', db().execute("select count(*) from audit where action='impersonate' and target='p1'").fetchone()[0] == 1)
c, _, j = call('update', {'path': 'config/auth', 'data': {'phone_required': True}}, MASTER); check('master turns on phone sign-in', c == 200)
c, _, j = call('sync', {})
check('signed-out sync now shows no people', not any(d['path'].startswith('users/') for d in j['docs']))
cl = [d for d in j['docs'] if d['path'].startswith('clients/')]
check('signed-out sees only client names', cl and all(set(d['data']) <= {'name', 'logo', 'tagline'} for d in cl), cl)
check('name-picker sign-in refused in phone mode', login('o1', '0000')[0] == 403)
c, _, j = call('login_phone', {'phone': '9000000002', 'pin': '0000'}); check('phone + PIN sign-in', c == 200 and j['token'].startswith('tz1_'))

print('One-time codes')
c, _, j = call('update', {'path': 'config/auth', 'data': {'otp_required': True}}, OWNER); check('owner cannot change sign-in settings', c == 403)
c, _, j = call('update', {'path': 'config/auth', 'data': {'otp_required': True}}, MASTER); check('master turns on one-time codes', c == 200, j)
open(f'{ROOT}/mail.txt', 'w').close()
c, _, j = call('login_phone', {'phone': '9000000002', 'pin': '0000'}); check('PIN accepted, code requested', c == 200 and j.get('otp_needed') and 'token' not in j, j)
check('code sent to WhatsApp and masked email', any('email ow' in x for x in j.get('sent_to', [])) and any('WhatsApp' in x for x in j.get('sent_to', [])), j)
mail = open(f'{ROOT}/mail.txt').read(); code = (re.findall(r'sign-in code is (\d{6})', mail) or [''])[-1]
check('email carries a 6-digit code', len(code) == 6)
c, _, j = call('login_phone', {'phone': '9000000002', 'pin': '0000', 'otp': '000000' if code != '000000' else '111111'}); check('wrong code refused', c == 401)
c, _, j = call('login_phone', {'phone': '9000000002', 'pin': '9999', 'otp': code}); check('right code with wrong PIN refused', c == 401)
c, _, j = call('login_phone', {'phone': '9000000002', 'pin': '0000', 'otp': code}); check('PIN + code signs in', c == 200 and j.get('token', '').startswith('tz1_'), j)
c, _, j = call('login_phone', {'phone': '9000000002', 'pin': '0000', 'otp': code}); check('a code works only once', c != 200 or j.get('otp_needed'))
open(f'{ROOT}/wa.jsonl', 'w').close()
c, _, j = call('login_phone', {'phone': '9000000004', 'pin': '4444'}); check('no email: code goes on WhatsApp', c == 200 and j.get('otp_needed') and any('WhatsApp ••••0004' == x for x in j.get('sent_to', [])), j)
wa = [json.loads(l) for l in open(f'{ROOT}/wa.jsonl') if l.strip()]
b = wa[-1]['body'] if wa else {}
check('WhatsApp request uses the API key header', wa and wa[-1]['key'] == 'Bearer test-wa-key')
check('WhatsApp request: template chini_login to 91 + number', b.get('to') == '919000000004' and b.get('template', {}).get('name') == 'chini_login' and b.get('messaging_product') == 'whatsapp', b)
comps = b.get('template', {}).get('components', [])
wcode = comps[0]['parameters'][0]['text'] if comps else ''
check('code in message body and in the copy-code button', len(wcode) == 6 and comps[1]['sub_type'] == 'url' and comps[1]['parameters'][0]['text'] == wcode, comps)
c, _, j = call('login_phone', {'phone': '9000000004', 'pin': '4444', 'otp': wcode}); check('WhatsApp code signs in', c == 200 and j.get('token', '').startswith('tz1_'), j)
open(f'{ROOT}/wa-fail', 'w').close(); con = db(); con.execute('delete from rate'); con.commit()
c, _, j = call('login_phone', {'phone': '9000000004', 'pin': '4444'}); check('WhatsApp down and no email: clear message, no sign-in', c == 503 and 'WhatsApp' in j.get('error', ''), j)
os.remove(f'{ROOT}/wa-fail')
c, _, j = call('update', {'path': 'users/p1', 'data': {'otp_exempt': True}}, OWNER); check('owner cannot exempt anyone from codes', c == 403)
c, _, j = call('update', {'path': 'users/p1', 'data': {'otp_exempt': True}}, MASTER); check('master can mark the store-review account', c == 200)
c, _, j = call('login_phone', {'phone': '9000000004', 'pin': '4444'}); check('marked account signs in without a code', c == 200 and j.get('token', '').startswith('tz1_'), j)
con = db(); con.execute('delete from fails'); con.execute('delete from rate'); con.commit()
call('update', {'path': 'config/auth', 'data': {'otp_required': False, 'phone_required': False}}, MASTER)

print('Code-only sign-in (Get OTP)')
call('update', {'path': 'config/auth', 'data': {'otp_required': True}}, MASTER)
con = db(); con.execute('delete from rate'); con.execute('delete from fails'); con.commit()
open(f'{ROOT}/wa.jsonl', 'w').close()
c, _, j = call('otp_request', {'phone': '9000000003'}); check('Get OTP for a registered number', c == 200 and j.get('ok') and j.get('masked', '').endswith('003'), j)
wa = [json.loads(l) for l in open(f'{ROOT}/wa.jsonl') if l.strip()]
code = wa[-1]['body']['template']['components'][0]['parameters'][0]['text'] if wa else ''
c, _, j = call('otp_request', {'phone': '9888888888'}); check('unregistered number gets the same answer (no probing)', c == 200 and set(j) == {'ok', 'masked'}, j)
check('no message sent to an unregistered number', len([l for l in open(f'{ROOT}/wa.jsonl') if l.strip()]) == 1)
c, _, j = call('otp_verify', {'phone': '9000000003', 'otp': '000000' if code != '000000' else '111111'}); check('wrong code refused', c == 401)
c, _, j = call('otp_verify', {'phone': '9000000003', 'otp': code}); check('right code signs in without a PIN', c == 200 and j.get('token', '').startswith('tz1_') and j.get('user_id') == 'mg1', j)
c, _, j = call('otp_verify', {'phone': '9000000003', 'otp': code}); check('code works once only', c == 401)
c, _, j = call('otp_verify', {'phone': '9888888888', 'otp': '123456'}); check('unregistered number cannot sign in', c == 401)
c, _, j = call('otp_request', {'phone': '9000000004'}); check('store-review account told to use its PIN', c == 200 and j.get('pin_instead') is True, j)
for _ in range(9): call('otp_request', {'phone': '9000000002'})
c, _, j = call('otp_request', {'phone': '9000000002'}); check('10th code in 10 minutes still allowed', c == 200, c)
c, _, j = call('otp_request', {'phone': '9000000002'}); check('11th code in 10 minutes blocked (anti-spam)', c == 429, c)
c, _, j = call('sync', {}); check('signed-out sync shows no people while codes are on', not any(d['path'].startswith('users/') for d in j['docs']))
call('update', {'path': 'config/auth', 'data': {'otp_required': False}}, MASTER)
c, _, j = call('otp_request', {'phone': '9000000003'}); check('Get OTP refused while codes are off', c == 409)
con = db(); con.execute('delete from rate'); con.execute('delete from fails'); con.commit()

print('AI workflows')
c, _, j = call('flow', {'x': 1}, qs='&trigger=Brief%20In'); check('signed-out AI call refused', c == 401)
c, _, j = login('p1', '4444'); P1 = j['token']
c, _, j = call('flow', {'x': 1}, P1, qs='&trigger=Brief%20In'); check('advisor cannot run the brief', c == 403)
c, _, j = call('flow', {'x': 1}, P1, qs='&trigger=Voice%20Note%20In'); check('advisor voice note goes through with the shared key', c == 200 and j.get('ok') and j.get('user') == 'p1', j)
c, _, j = call('flow', {'x': 1}, OWNER, qs='&trigger=Evil'); check('unknown workflow refused', c == 404)
con = db(); con.execute("insert or replace into rate(k,n,window_start) values('flow:Brief In:o1',30,?)", (time.time(),)); con.commit()
c, _, j = call('flow', {'x': 1}, OWNER, qs='&trigger=Brief%20In'); check('hourly AI limit enforced', c == 429)
page = get('/nirani/')[1].decode()
check('page no longer contains the n8n address or webhook paths', 'n8n.cloud' not in page and 'q8m4z2' not in page)

print('Report files')
pdf = b'%PDF-1.4 test'
import base64
c, _, j = call('upload', {'name': 'Nov 2026.pdf', 'data': base64.b64encode(pdf).decode()}, OWNER); check('only master uploads reports', c == 403)
c, _, j = call('upload', {'name': 'Nov 2026.pdf', 'data': base64.b64encode(pdf).decode()}, MASTER); url = j.get('url', '')
check('upload stored outside the web root', c == 200 and os.path.isfile(f'{ROOT}/data/files/Nov_2026.pdf') and not os.path.exists(f'{ROOT}/www/files/Nov_2026.pdf'), j)
c, _, j = call('file_link', {'name': url}, P1); check('advisors cannot open reports', c == 403)
c, _, j = call('file_link', {'name': url}, OWNER); link = j.get('url', '')
s, body = get('/' + link); check('signed link opens the PDF', s == 200 and body == pdf)
s, body = get('/' + re.sub(r'sig=\w+', 'sig=' + '0' * 64, link)); check('tampered link refused', s == 403)
s, body = get('/api.php?op=file&name=Nov_2026.pdf'); check('unsigned link refused', s == 403)
s, body = get('/files/anything.pdf'); check('old public files folder blocked', s == 403)

print('Phone app access (CORS)')
c, h, _ = call('sync', method='OPTIONS', headers={'Origin': 'capacitor://localhost', 'Access-Control-Request-Method': 'POST'}, raw=True)
check('iOS app origin allowed', c == 204 and h.get('Access-Control-Allow-Origin') == 'capacitor://localhost')
c, h, _ = call('sync', {}, headers={'Origin': 'https://localhost'}, raw=True); check('Android app origin allowed', h.get('Access-Control-Allow-Origin') == 'https://localhost')
c, h, _ = call('sync', {}, headers={'Origin': 'https://evil.example'}, raw=True); check('other websites not allowed', 'Access-Control-Allow-Origin' not in h)
c, _, j = call('push_native_register', {'platform': 'android', 'token': 'f' * 40}, P1); check('phone push token registered', c == 200)

print('Audit trail')
c, _, j = call('audit_list', {}, P1); check('advisors cannot read the audit log', c == 403)
c, _, j = call('audit_list', {}, OWNER); acts = {e['action'] for e in j.get('entries', [])}
check('owner sees own client activity', {'login', 'user_change'} <= acts, acts)
check('owner does not see other clients', all(e['client_id'] in ('nirani', None) for e in j['entries']) and all(e['client_id'] == 'nirani' for e in j['entries']))
c, _, j = call('audit_list', {}, MASTER); check('master sees failed sign-ins', any(e['action'] == 'login_fail' for e in j['entries']))

print('Nightly backup')
env = dict(os.environ)
r = subprocess.run(['php', '-d', 'date.timezone=UTC', f'{ROOT}/www/cron.php'], capture_output=True, text=True, cwd=f'{ROOT}/www')
out = json.loads(r.stdout.strip().splitlines()[-1]) if r.stdout.strip() else {}
files = glob.glob(f'{ROOT}/data/backups/tarazu-*.sqlite.gz.enc')
check('encrypted backup written', bool(files) and out.get('backup', {}).get('encrypted') is True, r.stdout[-300:] + r.stderr[-300:])
if files:
    raw = open(files[0], 'rb').read(); check('backup has TZB1 header and is not readable as plain SQLite', raw[:4] == b'TZB1' and b'SQLite format' not in raw)
    key = re.search(r"BACKUP_KEY', '([0-9a-f]+)'", open(f'{ROOT}/www/config.php').read()).group(1)
    dec = subprocess.run(['php', '-r', f'$r=file_get_contents("{files[0]}"); $k=hash("sha256","{key}",true); echo openssl_decrypt(substr($r,32),"aes-256-gcm",$k,OPENSSL_RAW_DATA,substr($r,4,12),substr($r,16,16));'], capture_output=True)
    plain = gzip.decompress(dec.stdout); open('/tmp/tz-restore.sqlite', 'wb').write(plain)
    n = sqlite3.connect('/tmp/tz-restore.sqlite').execute("select count(*) from docs where parent='users'").fetchone()[0]
    check('backup decrypts and restores', n >= 5, n)

print(f'\n{passed} passed, {failed} failed')
sys.exit(1 if failed else 0)
