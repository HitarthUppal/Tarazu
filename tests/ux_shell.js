/* UX shell checks + screenshots of every role, phone and desktop. Local only.
   Run: NODE_PATH=$(npm root -g) node tests/ux_shell.js */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const BASE = process.env.TZ_BASE || 'http://127.0.0.1:8097';
const OUT = '/tmp/tz-shots'; fs.mkdirSync(OUT, { recursive: true });
let pass = 0, fail = 0;
const ok = (n, c, x) => { if (c) { pass++; console.log('  ok  ', n); } else { fail++; console.log('  FAIL', n, x || ''); } };
function mint(uid) {
  return execFileSync('python3', ['-c', `
import sqlite3,secrets,hashlib,time
t='tz1_'+secrets.token_hex(32); n=time.time()
c=sqlite3.connect('/tmp/tz-local/data/tarazu.sqlite')
c.execute('insert into sessions(id,token_hash,user_id,created,last_seen,expires) values(?,?,?,?,?,?)',(secrets.token_hex(8),hashlib.sha256(t.encode()).hexdigest(),'${uid}',n,n,n+86400)); c.commit(); print(t)`]).toString().trim();
}
const ROLES = [['m1', 'master'], ['o1', 'owner'], ['mg1', 'manager'], ['p1', 'panelist']];
(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  // signed-out screen
  for (const vp of [{ name: 'phone', w: 390, h: 844 }, { name: 'desktop', w: 1366, h: 900 }]) {
    const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, deviceScaleFactor: 2 }); const page = await ctx.newPage();
    await page.goto(`${BASE}/nirani/`); await page.waitForSelector('#ph-num');
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUT}/signin-${vp.name}.png` });
    ok(`${vp.name} sign-in: Overpass font`, /Overpass/.test(await page.evaluate(() => getComputedStyle(document.body).fontFamily)));
    ok(`${vp.name} sign-in: Overpass actually loaded`, await page.evaluate(async () => { await document.fonts.ready; return document.fonts.check('16px Overpass'); }));
    await page.fill('#ph-num', '98765');
    ok(`${vp.name} sign-in: digit counter shows 5/10`, /5\/10/.test(await page.textContent('#ph-count')));
    await page.fill('#ph-num', '9876543210');
    ok(`${vp.name} sign-in: counter confirms 10 digits`, /10\/10/.test(await page.textContent('#ph-count')));
    await page.fill('#ph-num', '');
    ok(`${vp.name} sign-in: no layout toggle`, await page.locator('.vt, [data-act="vmode"]').count() === 0);
    await ctx.close();
  }
  for (const [uid, role] of ROLES) {
    for (const vp of [{ name: 'phone', w: 390, h: 844 }, { name: 'desktop', w: 1366, h: 900 }]) {
      const tok = mint(uid);
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, deviceScaleFactor: 2 }); const page = await ctx.newPage();
      const errs = []; page.on('pageerror', e => errs.push(String(e)));
      page.on('console', m => { if (m.type() === 'error' && !/net::ERR_|Failed to load resource/.test(m.text())) errs.push(m.text()); });
      await page.addInitScript(([t, u]) => { try { localStorage.setItem('tarazu.token', t); localStorage.setItem('tarazu.me', u); } catch (e) {} }, [tok, uid]);
      await page.goto(`${BASE}/nirani/`);
      await page.waitForSelector('body.authed', { timeout: 15000 }).catch(() => {});
      await page.waitForTimeout(900);
      const tag = `${role}-${vp.name}`;
      ok(`${tag}: signed in`, await page.evaluate(() => document.body.classList.contains('authed')));
      const body = await page.evaluate(() => document.body.innerText);
      ok(`${tag}: no demo, prototype or version wording`, !/Login demo|prototype|\bv2\b|\bv3\b|demo mode/i.test(body), (body.match(/Login demo|prototype|\bv2\b|\bv3\b|demo mode/i) || [])[0]);
      ok(`${tag}: page title shown`, (await page.textContent('#ab-title')).trim().length > 0);
      ok(`${tag}: account button`, await page.locator('#who [data-act="profile"]').isVisible());
      const tabs = await page.locator('nav.tabs button[data-act="go"]').count();
      ok(`${tag}: tabs have icons`, tabs > 0 && await page.locator('nav.tabs button[data-act="go"] svg').count() === tabs);
      const nav = await page.evaluate(() => { const r = document.querySelector('nav.tabs').getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height }; });
      if (vp.name === 'desktop') ok(`${tag}: sidebar on the left`, nav.x === 0 && nav.w < 300 && nav.h > 600, JSON.stringify(nav));
      else ok(`${tag}: tab bar at the bottom`, nav.y + nav.h >= vp.h - 1 && nav.w === vp.w, JSON.stringify(nav));
      if (vp.name === 'phone') {
        const small = await page.evaluate(() => [...document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,textarea')].filter(e => e.offsetParent && parseFloat(getComputedStyle(e).fontSize) < 16).length);
        ok(`${tag}: inputs are 16px (no iOS zoom)`, small === 0, small);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        ok(`${tag}: no sideways scrolling`, overflow <= 1, overflow);
      }
      if (role === 'owner' || role === 'manager') {
        ok(`${tag}: headline figures at the top`, await page.evaluate(() => { const k = document.querySelector('#main .kpis'), c = document.querySelector('#main .card'); return !!k && !!c && !!(k.compareDocumentPosition(c) & Node.DOCUMENT_POSITION_FOLLOWING); }));
        ok(`${tag}: four figures`, await page.locator('#main .kpi').count() === 4);
      }
      await page.screenshot({ path: `${OUT}/${tag}-home.png` });
      // every tab renders
      const routes = await page.$$eval('nav.tabs button[data-act="go"]', bs => bs.map(b => [b.dataset.route, b.textContent.trim()]));
      for (const [r, label] of routes.slice(1)) {
        await page.click(`nav.tabs button[data-route="${r}"]`); await page.waitForTimeout(500);
        ok(`${tag}: ${label} tab renders`, (await page.textContent('#main')).trim().length > 20);
        if (r === 'sales') {
          ok(`${tag}: sales has one period picker, no chip row`, await page.locator('#s-period').count() === 1 && await page.locator('[data-act="sp"][aria-pressed]').count() === 0);
          ok(`${tag}: sales shows a record count`, await page.locator('#main .count').count() === 1);
          await page.selectOption('#s-period', '1y'); await page.waitForTimeout(300);
          ok(`${tag}: period picker applies`, await page.$eval('#s-period', e => e.value) === '1y');
        }
        if (vp.name === 'phone') await page.screenshot({ path: `${OUT}/${tag}-${r}.png` });
      }
      // account sheet
      await page.click('#who [data-act="profile"]'); await page.waitForTimeout(300);
      ok(`${tag}: account sheet opens with Sign out`, await page.locator('#sheet [data-act="logout"]').isVisible());
      await page.screenshot({ path: `${OUT}/${tag}-account.png` });
      await page.keyboard.press('Escape');
      ok(`${tag}: Escape closes it`, await page.locator('#sheet').isHidden());
      const fab = await page.locator('#fab').isVisible();
      if (fab) console.log(`        (${tag} main action: ${(await page.textContent('#fab')).trim()})`);
      ok(`${tag}: no script errors`, errs.length === 0, errs.slice(0, 3));
      await ctx.close();
    }
  }
  await browser.close();
  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})();
