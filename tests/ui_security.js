/* Browser checks for the security changes, on the local copy with the production CSP. Run: NODE_PATH=$(npm root -g) node tests/ui_security.js */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.TZ_BASE || 'http://127.0.0.1:8097';
let pass = 0, fail = 0;
const ok = (n, c, x) => { if (c) { pass++; console.log('  ok  ', n); } else { fail++; console.log('  FAIL', n, x || ''); } };
async function api(op, body, tok) {
  const r = await fetch(`${BASE}/api.php?op=${op}`, { method: 'POST', headers: { 'Content-Type': 'application/json', ...(tok ? { 'X-Token': tok } : {}) }, body: JSON.stringify(body || {}) });
  return r.json();
}
(async () => {
  const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  const master = (await api('login', { user_id: 'm1', pin: '1111' })).token;
  await api('update', { path: 'config/auth', data: { phone_required: true, otp_required: false } }, master);
  for (const vp of [{ name: 'phone', w: 390, h: 844 }, { name: 'desktop', w: 1366, h: 900 }]) {
    console.log(`\n${vp.name}`);
    const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h } });
    const page = await ctx.newPage();
    const errors = [], csp = [];
    page.on('console', m => { if (m.type() === 'error' && !/net::ERR_/.test(m.text())) (/Content Security Policy|CSP/i.test(m.text()) ? csp : errors).push(m.text()); });
    page.on('pageerror', e => errors.push(String(e)));
    await page.goto(`${BASE}/nirani/`); await page.waitForSelector('#ph-num', { timeout: 15000 });
    ok('phone sign-in shown, no name list', await page.locator('[data-act="pick-login"]').count() === 0);
    await page.fill('#ph-num', '9000000002'); await page.fill('#ph-pin', '0000'); await page.click('#phone-form button[type=submit]');
    await page.waitForFunction(() => !document.querySelector('#ph-num'), null, { timeout: 15000 });
    ok('owner signs in with phone + PIN', true);
    const tok = await page.evaluate(() => localStorage.getItem('tarazu.token'));
    ok('session token is the new kind', /^tz1_/.test(tok || ''), tok);
    await page.screenshot({ path: `/tmp/tz-ui-${vp.name}-home.png` });
    fs.writeFileSync(`/tmp/tz-ui-${vp.name}-nav.txt`, await page.evaluate(() => [...document.querySelectorAll('[data-act],[data-tab],nav a,button')].slice(0, 80).map(e => (e.dataset.act || e.dataset.tab || '') + ':' + e.textContent.trim().slice(0, 30)).join('\n')));
    await page.click('[data-act="go"]:has-text("Admin")'); await page.waitForTimeout(600);
    const pins = await page.$$eval('input[data-k="pin"]', els => els.map(e => ({ v: e.value, ph: e.placeholder })));
    ok('PIN boxes are empty (PINs never sent)', pins.length > 0 && pins.every(p => p.v === ''), JSON.stringify(pins).slice(0, 200));
    ok('PIN boxes show set / not set', pins.every(p => /set|Set PIN/.test(p.ph)));
    ok('default-PIN warning still works', await page.locator('text=default PIN').count() > 0);
    ok('Security card shown to owner', await page.locator('h2:has-text("Security")').count() === 1);
    await page.click('[data-act="sec-audit"]'); await page.waitForSelector('text=Signed in', { timeout: 8000 });
    ok('owner sees recent activity', await page.locator('td:has-text("Signed in")').count() > 0);
    const adv = page.locator('input[data-k="pin"][data-uu="p1"]').first();
    await adv.fill('6543'); await adv.press('Tab'); await page.waitForSelector('text=Saved', { timeout: 8000 });
    const r = await api('login_phone', { phone: '9000000004', pin: '6543' });
    ok('PIN typed in the box becomes the new PIN', !!r.token, JSON.stringify(r));
    await page.click('[data-act="logout"]'); await page.waitForSelector('#ph-num', { timeout: 8000 });
    const st = await api('sync', {}, tok); ok('Switch user ends the session on the server', st.signed_in === false);
    ok('no CSP violations', csp.length === 0, csp.slice(0, 3));
    ok('no script errors', errors.length === 0, errors.slice(0, 3));
    await ctx.close();
  }
  console.log('\none-time code');
  await api('update', { path: 'config/auth', data: { phone_required: true, otp_required: true } }, master);
  fs.writeFileSync('/tmp/tz-local/mail.txt', '');
  { const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } }); const page = await ctx.newPage();
    await page.goto(`${BASE}/nirani/`); await page.waitForSelector('#ph-num');
    await page.fill('#ph-num', '9000000002'); await page.fill('#ph-pin', '0000'); await page.click('#phone-form button[type=submit]');
    await page.waitForSelector('#ph-otp', { timeout: 10000 }); ok('code box appears after the PIN', true);
    ok("says where the code went", await page.locator("text=Sent to WhatsApp").count() === 1);
    await page.screenshot({ path: '/tmp/tz-ui-otp.png' });
    const code = (fs.readFileSync('/tmp/tz-local/mail.txt', 'utf8').match(/sign-in code is (\d{6})/) || [])[1];
    await page.fill('#ph-otp', '000001'); await page.click('#phone-form button[type=submit]'); await page.waitForTimeout(800);
    ok('wrong code shows an error', await page.locator('.notice.err').count() === 1);
    await page.fill('#ph-otp', code); await page.click('#phone-form button[type=submit]');
    await page.waitForFunction(() => !document.querySelector('#ph-num'), null, { timeout: 10000 }); ok('right code signs in', true);
    await ctx.close(); }
  await api('update', { path: 'config/auth', data: { otp_required: false } }, master);
  console.log('\nmaster');
  { const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } }); const page = await ctx.newPage();
    const errs = []; page.on('pageerror', e => errs.push(String(e)));
    await page.goto(`${BASE}/`); await page.waitForSelector('#ph-num');
    await page.fill('#ph-num', '9000000001'); await page.fill('#ph-pin', '1111'); await page.click('#phone-form button[type=submit]');
    await page.waitForFunction(() => !document.querySelector('#ph-num'), null, { timeout: 10000 });
    await page.waitForTimeout(800);
    ok('one-time code switch in master sign-in settings', await page.locator('[data-act="otp-required"]').count() === 1);
    ok('email column for sign-in codes', await page.locator('input[data-k="email"]').count() >= 5);
    ok('master PIN column shows no PINs', !(await page.locator('td.num:has-text("1111")').count()));
    ok('Security card on master page', await page.locator('h2:has-text("Security")').count() === 1);
    await page.screenshot({ path: '/tmp/tz-ui-master.png', fullPage: true });
    ok('no script errors on master page', errs.length === 0, errs.slice(0, 2));
    await ctx.close(); }
  await browser.close();
  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
