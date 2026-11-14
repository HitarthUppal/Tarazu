#!/usr/bin/env python3
"""Seed the LOCAL test database with a believable fortnight for Nirani, for walkthroughs and screenshots.
Never run against production. Usage: python3 tests/seed_month.py [/tmp/tz-local/data/tarazu.sqlite]"""
import sqlite3, json, sys, time, random, datetime as dt
DB = sys.argv[1] if len(sys.argv) > 1 else '/tmp/tz-local/data/tarazu.sqlite'
assert DB.startswith('/tmp/'), 'local databases only'
random.seed(7)
c = sqlite3.connect(DB)
IST = dt.timezone(dt.timedelta(hours=5, minutes=30))
now = dt.datetime.now(IST)
def iso(d): return d.astimezone(dt.timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.000Z')
def put(path, data):
    parent = path.rsplit('/', 1)[0]
    c.execute('insert or replace into docs(path,parent,data,updated,deleted) values(?,?,?,?,0)', (path, parent, json.dumps(data), time.time()))
C = 'clients/nirani/'
# people
for i, n in enumerate(['Ramesh Patil', 'Sunil Kulkarni', 'Anita Desai', 'Vikas Joshi', 'Mohan Gowda'], 1):
    put(f'users/p{i}', {'name': n, 'role': 'panelist', 'client_id': 'nirani', 'phone': f'90000001{i:02d}', 'active': True, 'otp_exempt': True, 'pin_default': False})
put('users/o1', {**json.loads(c.execute("select data from docs where path='users/o1'").fetchone()[0]), 'name': 'Vishal Nirani'})
put('users/mg1', {**json.loads(c.execute("select data from docs where path='users/mg1'").fetchone()[0]), 'name': 'Prakash Hegde'})
# mills
mills = [('Kedarnath Sugar', 'Bagalkot', 'KA'), ('Saibapa Sugars', 'Mudhol', 'KA'), ('Nirani Sugars', 'Mudhol', 'KA'), ('Shri Sai Priya', 'Hipparagi', 'KA'),
         ('MRN Cane Power', 'Kulali', 'KA'), ('Badami Sugars', 'Badami', 'KA'), ('Ugar Unit 2', 'Athani', 'KA'), ('Sangli Unit', 'Sangli', 'MH'),
         ('Kolhapur Unit', 'Kolhapur', 'MH'), ('Bidar Unit', 'Bidar', 'KA')]
ms = []
for i, (n, loc, st) in enumerate(mills, 1):
    stock = random.randint(40, 160) * 1000; quota = random.randint(15, 60) * 1000; sold = random.randint(4, quota // 1000 - 5) * 1000
    m = {'unit_no': i, 'name': n, 'code': f'{67000 + i}', 'location': loc, 'state': st, 'grade': 'S', 'stock_wh': stock, 'quota': quota,
         'sold_qty': sold, 'sold_avg_price': random.randint(3880, 3960), 'wish_to_sell': random.choice([0, 2000, 3000, 5000, 8000]), 'updated_at': iso(now)}
    put(C + f'mills/u{i}', m); ms.append((f'u{i}', m))
# daily outlooks for 12 days (the feature being retired), and rounds every 4 days
stances = ['sell', 'some', 'hold']
for d in range(12, 0, -1):
    day = (now - dt.timedelta(days=d)).strftime('%Y-%m-%d')
    for p in range(1, 6):
        if random.random() < .8:
            s = random.choice(stances)
            put(C + f'outlooks/{day}__p{p}', {'day': day, 'panelist_id': f'p{p}', 'stance': s, 'sell_pct': 100 if s == 'sell' else 0 if s == 'hold' else 50,
                                              'direction': random.choice(['up', 'flat', 'down']), 'note': '', 'created_at': iso(now - dt.timedelta(days=d)), 'updated_at': iso(now - dt.timedelta(days=d))})
def round_doc(days_ago, status, band):
    t = now - dt.timedelta(days=days_ago, hours=3)
    return {'title': 'Round of ' + t.strftime('%-d %b'), 'created_by': 'mg1', 'created_at': iso(t), 'valid_until': iso(t + dt.timedelta(hours=6)) if status != 'open' else iso(now + dt.timedelta(hours=5)),
            'band_min': band[0], 'band_max': band[1], 'ask_rate': sum(band) // 2, 'floor_rate': band[0], 'grade': 'M/30', 'note': 'Cane payments due on the 20th.' if status == 'open' else '', 'status': status,
            'mills': [{'id': k, 'unit_no': m['unit_no'], 'name': m['name'], 'code': m['code'], 'state': m['state'], 'stock_wh': m['stock_wh'], 'quota': m['quota'], 'sold_qty': m['sold_qty'],
                       'sold_avg_price': m['sold_avg_price'], 'balance_quota': m['quota'] - m['sold_qty'], 'wish_to_sell': m['wish_to_sell']} for k, m in ms]}
rounds = [('r12', 12, 'decided', (3880, 3930)), ('r8', 8, 'decided', (3900, 3950)), ('r4', 4, 'closed', (3920, 3970)), ('r0', 0, 'open', (3930, 3980))]
for rid, ago, st, band in rounds:
    r = round_doc(ago, st, band)
    if st == 'decided':
        r['decision'] = {'mills': {k: {'qty': m['wish_to_sell'] // 2, 'rate': band[0] + 20} for k, m in ms if m['wish_to_sell']}, 'total_qty': sum(m['wish_to_sell'] // 2 for _, m in ms),
                         'avg_rate': band[0] + 20, 'note': 'Sell half, hold the rest for the festival pick-up.', 'by': 'o1', 'at': iso(now - dt.timedelta(days=ago - 0.3))}
    put(C + f'rounds/{rid}', r)
    for p in range(1, 6):
        if st == 'open' and p > 3: continue
        s = random.choice(stances); at = now - dt.timedelta(days=ago, hours=1)
        put(C + f'rviews/{rid}__p{p}', {'round_id': rid, 'panelist_id': f'p{p}', 'stance': s, 'sell_pct': 100 if s == 'sell' else 0 if s == 'hold' else random.choice([25, 50, 75]),
                                         'target_rate': random.randint(band[0] // 10, band[1] // 10) * 10, 'direction': random.choice(['up', 'flat', 'down']), 'rest': 'hold', 'lots': 4, 'interval_days': 3,
                                         'horizon_months': 1, 'expected_move': None, 'overrides': {}, 'reasons': {'festival': 'up'}, 'conviction': random.choice(['low', 'medium', 'high']),
                                         'note': 'Buyers quiet in Bengaluru this week.' if p == 2 else '', 'voice': None, 'updated_at': iso(at), 'versions': [{'sell_pct': 50, 'target_rate': band[0], 'at': iso(at), 'kind': 'first', 'change_reason': ''}]})
# a poll and a comment
put(C + 'polls/pl1', {'question': 'Will the December quota be higher than November?', 'options': ['Higher', 'Same', 'Lower'], 'note': '', 'created_by': 'o1', 'created_at': iso(now - dt.timedelta(days=1)), 'closes_at': iso(now + dt.timedelta(days=1)), 'round_id': None})
for p, ch in [('p1', 'Higher'), ('p3', 'Same')]:
    put(C + f'pvotes/pl1__{p}', {'poll_id': 'pl1', 'panelist_id': p, 'choice': ch, 'comment': '', 'at': iso(now - dt.timedelta(hours=10))})
put(C + 'comments/cm1', {'panelist_id': 'p2', 'text': 'Maharashtra mills are offering at 3,900 for prompt lifting. Expect pressure on our band.', 'voice': None, 'created_at': iso(now - dt.timedelta(hours=20)), 'round_id': None})
# manual sales ledger (the manager-entry feature being retired) and imported monthly sales history
for i in range(8):
    k, m = random.choice(ms); d = now - dt.timedelta(days=random.randint(1, 13))
    put(C + f'ledger/l{i}', {'date': d.strftime('%Y-%m-%d'), 'mill_id': k, 'mill_code': m['code'], 'mill_name': m['name'], 'qty': random.choice([1000, 2000, 3000]), 'rate': random.randint(3890, 3960), 'grade': 'M/30', 'source': 'manual', 'by': 'mg1', 'created_at': iso(d)})
for mo in range(1, 13):
    y, mth = now.year, now.month - mo
    while mth < 1: mth += 12; y -= 1
    for k, m in ms[:5]:
        put(C + f'sales/{y}-{mth:02d}__{k}', {'month': f'{y}-{mth:02d}', 'mill': m['name'], 'qty': random.randint(5, 20) * 1000, 'avg_price': random.randint(3500, 3950)})
put('config/auth', {'phone_required': True, 'otp_required': False})
c.commit(); print('seeded', DB)
