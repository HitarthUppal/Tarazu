/* Landing page and site layout checks, on the local copy with the production CSP.
   Run: tests/setup_local.sh, then NODE_PATH=$(npm root -g) node tests/landing.js */
const { chromium } = require('playwright');
const BASE = process.env.TZ_BASE || 'http://127.0.0.1:8097';
let pass = 0, fail = 0;
const ok = (n, c, x) => { if (c) { pass++; console.log('  ok  ', n); } else { fail++; console.log('  FAIL', n, x || ''); } };

(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  for (const vp of [{ name: 'phone', w: 390, h: 844 }, { name: 'desktop', w: 1366, h: 900 }]) {
    for (const scheme of ['light', 'dark']) {
      const tag = `${vp.name} ${scheme}`;
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, colorScheme: scheme });
      const page = await ctx.newPage();
      const errs = []; page.on('pageerror', e => errs.push(String(e)));
      page.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
      const r = await page.goto(`${BASE}/`); await page.waitForLoadState('networkidle');
      ok(`${tag}: / serves the landing page`, r.status() === 200 && /Sell sugar at the right moment/.test(await page.textContent('h1')));
      ok(`${tag}: no errors or CSP violations`, errs.length === 0, errs.slice(0, 3));
      ok(`${tag}: no sideways scrolling`, await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      const hrefs = await page.$$eval('a', as => as.map(a => a.getAttribute('href')));
      ok(`${tag}: Nirani sign-in buttons point to nirani/`, hrefs.filter(h => h === 'nirani/').length >= 2);
      ok(`${tag}: admin button points to admin/`, hrefs.includes('admin/'));
      ok(`${tag}: no em dashes in copy`, !(await page.textContent('body')).includes('—'));
      const imgs = await page.$$eval('img', is => is.map(i => [i.getAttribute('src'), i.naturalWidth]));
      ok(`${tag}: every image loads`, imgs.every(([, w]) => w > 0), imgs);
      ok(`${tag}: brand font loaded`, await page.evaluate(async () => { await document.fonts.ready; return document.fonts.check('700 16px Overpass'); }));
      await page.screenshot({ path: `/tmp/tz-landing-${vp.name}-${scheme}.png`, fullPage: true });
      await ctx.close();
    }
  }

  console.log('\nroutes');
  { const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } }); const page = await ctx.newPage();
    const errs = []; page.on('pageerror', e => errs.push(String(e)));
    await page.goto(`${BASE}/`); await page.click('a.btn[href="admin/"]');
    await page.waitForSelector('#ph-num', { timeout: 15000 });
    ok('admin button opens the master sign-in at /admin/', new URL(page.url()).pathname === '/admin/');
    ok('/admin/ is the master admin screen (client cards, Sign in to Tarazu)', /Sign in to Tarazu/.test(await page.innerText('body')) && await page.locator('a.client-card').count() === 1);
    const card = await page.getAttribute('a.client-card', 'href');
    ok('/admin/ client card links to /nirani/, not /admin/nirani/', card === '/nirani/', card);
    ok('/admin/ has no script errors', errs.length === 0, errs.slice(0, 2));
    await page.goto(`${BASE}/`); await page.click('.door.main a.btn');
    await page.waitForSelector('#ph-num', { timeout: 15000 });
    ok('Nirani sign-in opens /nirani/', new URL(page.url()).pathname === '/nirani/');
    ok('/nirani/ shows the client sign-in', /Sign in/.test(await page.innerText('body')) && !/Master admin sign in/i.test(await page.innerText('body')));
    await ctx.close(); }
  { const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } }); const page = await ctx.newPage();
    await page.goto(`${BASE}/nirani/`); await page.waitForSelector('#ph-num');
    await page.fill('#ph-num', '9000000001'); await page.fill('#ph-pin', '1111'); await page.click('#phone-form button[type=submit]');
    await page.waitForURL(u => new URL(u).pathname === '/admin/', { timeout: 15000 }).catch(() => {});
    ok('master signing in at /nirani/ lands on /admin/, not the landing page', new URL(page.url()).pathname === '/admin/', page.url());
    await ctx.close(); }
  { const r = await fetch(`${BASE}/nirani`, { redirect: 'manual' });
    ok('/nirani redirects to /nirani/', r.status === 301 && r.headers.get('location') === '/nirani/'); }
  { const r = await fetch(`${BASE}/admin`, { redirect: 'manual' });
    ok('/admin redirects to /admin/', r.status === 301 && r.headers.get('location') === '/admin/'); }
  { const r = await fetch(`${BASE}/admin/api.php?op=health`); ok('API answers under /admin/', r.status === 200 && (await r.json()).ok); }

  await browser.close();
  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})();
