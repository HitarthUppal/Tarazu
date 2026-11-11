import sqlite3, sys
r = sqlite3.connect('/tmp/tz-local/data/tarazu.sqlite').execute(sys.argv[1]).fetchone()
print(r[0] if r else '')
