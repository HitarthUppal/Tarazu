/* Checks for the 14 Nov changes: read every 4 days, no Market mood, no manual sales, action-first homes,
   one Market tab, phone tables. Needs tests/setup_local.sh then tests/seed_month.py.
   Run: NODE_PATH=$(npm root -g) node tests/cadence.js */
const { chromium } = require('playwright'); const { execFileSync } = require('child_process');
const BASE = process.env.TZ_BASE || 'http://127.0.0.1:8097';
let pass = 0, fail = 0; const ok = (n, c, x) => { if (c) { pass++; console.log('  ok  ', n); } else { fail++; console.log('  FAIL', n, x === undefined ? '' : JSON.stringify(x)); } };
function mint(uid) { return execFileSync('python3', ['-c', `
import sqlite3,secrets,hashlib,time
t='tz1_'+secrets.token_hex(32); n=time.time()
c=sqlite3.connect('/tmp/tz-local/data/tarazu.sqlite')
c.execute('insert into sessions(id,token_hash,user_id,created,last_seen,expires) values(?,?,?,?,?,?)',(secrets.token_hex(8),hashlib.sha256(t.encode()).hexdigest(),'${uid}',n,n,n+86400)); c.commit(); print(t)`]).toString().trim(); }
async function open(b, uid, w = 390, hash = '') {
  const ctx = await b.newContext({ viewport: { width: w, height: 844 } }); const p = await ctx.newPage(); p.errs = [];
  p.on('pageerror', e => p.errs.push(String(e)));
  await p.addInitScript(([t, u]) => { localStorage.setItem('tarazu.token', t); localStorage.setItem('tarazu.me', u); }, [mint(uid), uid]);
  await p.goto(`${BASE}/nirani/${hash}`); await p.waitForTimeout(1300); return p;
}
const text = p => p.textContent('#main');
const inputsFit = p => p.evaluate(() => [...document.querySelectorAll('#main input:not([type=hidden]):not([type=checkbox]):not([type=range])')]
  .filter(e => e.offsetParent).filter(e => { const r = e.getBoundingClientRect(); return r.right > window.innerWidth + 1 || r.left < -1; }).length);
const tabs = p => p.$$eval('nav.tabs button[data-act="go"]', bs => bs.map(b => b.querySelector('.tl').textContent));
(async () => {
  const b = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });

  // owner
  let p = await open(b, 'o1'); let t = await text(p);
  ok('owner: Decision needed comes first', await p.evaluate(() => { const c = document.querySelector('#main .card'); return !!c && c.classList.contains('needs'); }));
  ok('owner: Decide button opens the waiting round', await p.locator('#main .card.needs [data-act="open"][data-id="r4"]').count() === 1);
  ok('owner: Market mood is gone', !/Market mood/.test(t));
  ok('owner: Latest panel read is shown', /Latest panel read/.test(t));
  ok('owner: no repeated cards', !/Advisory rounds awaiting you|Sales this month/.test(t), t.match(/Advisory rounds awaiting you|Sales this month/));
  ok('owner: no market block on home', !/Domestic sugar news|ChiniMandi Magazine/.test(t));
  ok('owner: Market tab exists', (await tabs(p)).includes('Market'), await tabs(p));
  ok('owner: month comparison says which days', /vs 1–\d+ /.test(t) || !/vs /.test(t), (t.match(/vs [^ ]+ [A-Za-z]+/) || [])[0]);
  await p.click('nav.tabs button[data-route="market"]'); await p.waitForTimeout(500);
  ok('owner: Market tab holds the market content', /Domestic sugar news/.test(await text(p)));
  ok('owner: no script errors', p.errs.length === 0, p.errs); await p.context().close();

  // owner deciding on a phone
  p = await open(b, 'o1', 390, '#round=r4');
  ok('owner round: decision boxes fit on a phone', await inputsFit(p) === 0, await inputsFit(p));
  ok('owner round: no market block under the round', !/Domestic sugar news/.test(await text(p)));
  await p.context().close();

  // manager
  p = await open(b, 'mg1'); t = await text(p);
  ok('manager: Record a sale is gone', !/Record a sale/.test(t) && await p.locator('#sale-form').count() === 0);
  ok('manager: one card per mill on a phone', await p.locator('.mcard').count() === 10, await p.locator('.mcard').count());
  ok('manager: wish boxes fit on a phone', await inputsFit(p) === 0, await inputsFit(p));
  ok('manager: sold figures cannot be typed', await p.locator('input[data-k="sold_qty"], input[data-k="sold_avg_price"]').count() === 0);
  const close = await p.$eval('#rd-valid', e => new Date(e.value).getTime());
  const hrs = (close - Date.now()) / 3600e3; ok('manager: a new round stays open about 24 hours', hrs > 23.5 && hrs < 24.5, hrs);
  ok('manager: Market tab exists', (await tabs(p)).includes('Market'), await tabs(p));
  ok('manager: no script errors', p.errs.length === 0, p.errs); await p.context().close();
  p = await open(b, 'mg1', 1366); ok('manager desktop: full mill table with sold columns read-only', await p.locator('#main table.compact input[data-k="wish_to_sell"]').count() === 10 && await p.locator('input[data-k="sold_qty"]').count() === 0); await p.context().close();

  // advisor
  p = await open(b, 'p4'); t = await text(p);
  ok('advisor: tab is called Home', (await tabs(p))[0] === 'Home', await tabs(p));
  ok('advisor: read names its period', /Your read for \d+/.test(t), (t.match(/Your read for [^W]{0,20}/) || [])[0]);
  ok('advisor: asks about the next days, not today', /for the next 4 days/.test(t));
  ok('advisor: Your recent outlooks is gone', !/recent outlooks/i.test(t));
  ok('advisor: open round still asks for a view', /Give your view/.test(t));
  const badge0 = await p.textContent('nav.tabs button[data-route="home"] .badge').catch(() => '0');
  await p.click('#outlook-form [data-act="stance"][data-k="hold"]'); await p.waitForTimeout(200);
  await p.click('#outlook-form button[type="submit"]'); await p.waitForTimeout(900);
  t = await text(p);
  ok('advisor: read is sent and says when the next is due', /Next read due/.test(t), t.slice(0, 300));
  const badge1 = await p.textContent('nav.tabs button[data-route="home"] .badge').catch(() => '0');
  ok('advisor: badge goes down after sending the read', Number(badge1) === Number(badge0) - 1, [badge0, badge1]);
  const saved = execFileSync('python3', ['-c', `import sqlite3,json;c=sqlite3.connect('/tmp/tz-local/data/tarazu.sqlite');print([r[0] for r in c.execute("select path from docs where path like 'clients/nirani/outlooks/%__p4' and deleted=0 order by path desc limit 1")])`]).toString();
  ok('advisor: read is stored against the period start', /outlooks\/2026-11-(14|18|22|26|30)__p4|outlooks\/20\d\d-\d\d-\d\d__p4/.test(saved), saved.trim());
  ok('advisor: no script errors', p.errs.length === 0, p.errs); await p.context().close();
  p = await open(b, 'p4', 390, '#round=r0');
  await p.click('details:has(> summary:has-text("particular mill")) > summary').catch(() => {});
  await p.waitForTimeout(300);
  ok('advisor round: per-mill boxes fit on a phone', await inputsFit(p) === 0, await inputsFit(p));
  await p.context().close();

  // owner sees the new read, and can change the cycle
  p = await open(b, 'o1'); t = await text(p);
  ok('owner: the new read shows in Latest panel read', /Latest panel read[\s\S]*Vikas Joshi[\s\S]*Hold/.test(t));
  await p.click('nav.tabs button[data-route="admin"]'); await p.waitForTimeout(500);
  ok('owner admin: panel read settings present', await p.locator('#read-form').count() === 1);
  await p.fill('#rd-every', '3'); await p.click('#read-form button[type="submit"]'); await p.waitForTimeout(700);
  ok('owner admin: cycle saved', /Current read:/.test(await text(p)) && JSON.parse(execFileSync('python3', ['-c', `import sqlite3;c=sqlite3.connect('/tmp/tz-local/data/tarazu.sqlite');print(c.execute("select data from docs where path='clients/nirani/config/mill'").fetchone()[0])`]).toString()).read_every === 3);
  await p.fill('#rd-every', '4'); await p.click('#read-form button[type="submit"]'); await p.waitForTimeout(500);
  ok('owner admin: no script errors', p.errs.length === 0, p.errs); await p.context().close();

  await b.close(); console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})();
