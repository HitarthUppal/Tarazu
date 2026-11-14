#!/usr/bin/env python3
"""Sales feed from Nirani's software (op=sales_push). Local server only. Run after tests/setup_local.sh and tests/seed_month.py."""
import json, sqlite3, urllib.request, urllib.error, datetime as dt
BASE = 'http://127.0.0.1:8097/nirani/api.php?op=sales_push'
DB = '/tmp/tz-local/data/tarazu.sqlite'
KEY = 'test-sales-key'
ok = fail = 0
def check(name, cond, extra=''):
    global ok, fail
    if cond: ok += 1; print('  ok  ', name)
    else: fail += 1; print('  FAIL', name, extra)
def push(body, key=KEY):
    req = urllib.request.Request(BASE, data=json.dumps(body).encode(), headers={'Content-Type': 'application/json', **({'X-Tarazu-Sales-Key': key} if key is not None else {})})
    try:
        with urllib.request.urlopen(req) as r: return r.status, json.loads(r.read())
    except urllib.error.HTTPError as e: return e.code, json.loads(e.read() or b'{}')
def db(): return sqlite3.connect(DB)
def mill(mid): return json.loads(db().execute("select data from docs where path=?", (f'clients/nirani/mills/{mid}',)).fetchone()[0])
today = (dt.datetime.now(dt.timezone.utc) + dt.timedelta(hours=5, minutes=30)).strftime('%Y-%m-%d')
code1 = mill('u1')['code']

st, j = push({'client': 'nirani', 'sales': [{'ref': 'X', 'date': today, 'mill_code': code1, 'qty': 1, 'rate': 4000}]}, key=None)
check('no key is refused', st == 401, (st, j))
st, j = push({'client': 'nirani', 'sales': [{'ref': 'X', 'date': today, 'mill_code': code1, 'qty': 1, 'rate': 4000}]}, key='wrong')
check('wrong key is refused', st == 401, (st, j))
st, j = push({'client': 'nobody', 'sales': [{'ref': 'X'}]})
check('unknown client is refused', st == 400, (st, j))
st, j = push({'client': 'nirani', 'sales': []})
check('empty list is refused', st == 400, (st, j))

before = db().execute("select count(*) from docs where path like 'clients/nirani/ledger/erp_%' and deleted=0").fetchone()[0]
rows = [{'ref': 'INV-1001', 'date': today, 'mill_code': code1, 'qty': 1500, 'rate': 3925, 'buyer': 'Bengaluru trader'},
        {'ref': 'INV-1002', 'date': today, 'mill_code': code1, 'qty': 500, 'rate': 3945},
        {'ref': 'INV-1003', 'date': today, 'mill_code': 'NOPE', 'qty': 100, 'rate': 3900},
        {'ref': 'INV-1004', 'date': '14/11/2026', 'mill_code': code1, 'qty': 100, 'rate': 3900},
        {'ref': 'INV-1005', 'date': today, 'mill_code': code1, 'qty': 100, 'rate': 39},
        {'ref': '', 'date': today, 'mill_code': code1, 'qty': 100, 'rate': 3900}]
st, j = push({'client': 'nirani', 'sales': rows})
check('valid rows saved, bad rows reported', st == 200 and j['saved'] == 2 and len(j['skipped']) == 4, j)
reasons = {s['ref']: s['reason'] for s in j.get('skipped', [])}
check('unknown mill code is explained', 'unknown mill_code' in reasons.get('INV-1003', ''), reasons)
check('bad date is explained', 'YYYY-MM-DD' in reasons.get('INV-1004', ''), reasons)
check('rate in the wrong unit is explained', 'per quintal' in reasons.get('INV-1005', ''), reasons)
after = db().execute("select count(*) from docs where path like 'clients/nirani/ledger/erp_%' and deleted=0").fetchone()[0]
check('two ledger rows written', after - before == 2, (before, after))

st, j = push({'client': 'nirani', 'sales': [{'ref': 'INV-1001', 'date': today, 'mill_code': code1, 'qty': 1500, 'rate': 3925}]})
again = db().execute("select count(*) from docs where path like 'clients/nirani/ledger/erp_%' and deleted=0").fetchone()[0]
check('sending the same reference again does not duplicate', st == 200 and again == after, (again, after))

m = mill('u1'); month = today[:7]
led = [json.loads(r[0]) for r in db().execute("select data from docs where path like 'clients/nirani/ledger/%' and deleted=0")]
mine = [x for x in led if x.get('mill_id') == 'u1' and str(x.get('date', ''))[:7] == month]
q = sum(x['qty'] for x in mine); v = sum(x['qty'] * x['rate'] for x in mine)
check("mill's sold quantity matches the month's ledger", m['sold_qty'] == round(q), (m['sold_qty'], q))
check("mill's average price matches the month's ledger", m['sold_avg_price'] == round(v / q), (m['sold_avg_price'], v / q))

st, j = push({'client': 'nirani', 'sales': [{'ref': 'INV-1002', 'cancelled': True}]})
gone = db().execute("select count(*) from docs where path like 'clients/nirani/ledger/erp_%' and deleted=0").fetchone()[0]
check('a cancelled invoice is removed', st == 200 and j['removed'] == 1 and gone == after - 1, (j, gone))
check('sold quantity drops after the cancellation', mill('u1')['sold_qty'] == round(q - 500), (mill('u1')['sold_qty'], q - 500))
a = db().execute("select count(*) from audit where action='sales_push'").fetchone()[0]
check('every push is in the audit log', a >= 3, a)
st, j = push({'client': 'nirani', 'sales': [{'ref': str(i), 'date': today, 'mill_code': code1, 'qty': 1, 'rate': 4000} for i in range(1001)]})
check('more than 1000 rows per request is refused', st == 413, st)
print(f'\n{ok} passed, {fail} failed'); raise SystemExit(1 if fail else 0)
