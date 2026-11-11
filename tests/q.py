import sqlite3, sys
con = sqlite3.connect('/tmp/tz-local/data/tarazu.sqlite')
cur = con.execute(sys.argv[1]); con.commit()
r = cur.fetchone() if cur.description else None
print(r[0] if r else '')
