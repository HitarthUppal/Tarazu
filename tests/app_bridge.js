/* Runs the built app bundle (mobile/www) in Chrome with a fake Capacitor layer, sending tarazu.live calls to the
   local test server with the CORS rules the real server applies. Run after tests/setup_local.sh and npm run build:www. */
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const LOCAL = 'http://127.0.0.1:8097';
let pass = 0, fail = 0; const ok = (n, c, x) => { if (c) { pass++; console.log('  ok  ', n); } else { fail++; console.log('  FAIL', n, x || ''); } };
const FAKE_CAP = `
  window.__keychain = JSON.parse(sessionStorage.getItem('__kc') || '{}');
  window.__calls = [];
  const save = () => sessionStorage.setItem('__kc', JSON.stringify(window.__keychain));
  const L = {}; const on = (p, ev, fn) => { (L[p+ev] = L[p+ev] || []).push(fn); return Promise.resolve({ remove(){} }); };
  window.Capacitor = { isNativePlatform: () => true, getPlatform: () => 'android', Plugins: {
    NativeBiometric: {
      getCredentials: ({server}) => window.__keychain[server] ? Promise.resolve({username:'x', password: window.__keychain[server]}) : Promise.reject(new Error('none')),
      setCredentials: ({server, password}) => { window.__keychain[server] = password; save(); return Promise.resolve(); },
      deleteCredentials: ({server}) => { delete window.__keychain[server]; save(); return Promise.resolve(); },
      isAvailable: () => Promise.resolve({ isAvailable: true }),
      verifyIdentity: () => { window.__calls.push('biometric'); return Promise.resolve(); } },
    PushNotifications: {
      checkPermissions: () => Promise.resolve({ receive: 'granted' }), requestPermissions: () => Promise.resolve({ receive: 'granted' }),
      addListener: (ev, fn) => on('push', ev, fn),
      register: () => { window.__calls.push('push-register'); setTimeout(() => (L['pushregistration']||[]).forEach(f => f({ value: 'fcm-test-token-' + 'x'.repeat(30) })), 50); return Promise.resolve(); } },
    App: { addListener: (ev, fn) => on('app', ev, fn) },
    Browser: { open: ({url}) => { window.__calls.push('browser:' + url); return Promise.resolve(); } },
    SplashScreen: { hide: () => Promise.resolve() } } };
  window.__fireApp = (ev, arg) => (L['app'+ev]||[]).forEach(f => f(arg));`;
(async () => {
  const srv = spawn('python3', ['-m', 'http.server', '8099', '--bind', '127.0.0.1'], { cwd: __dirname + '/../mobile/www', stdio: 'ignore' });
  await new Promise(r => setTimeout(r, 800));
  const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  await ctx.addInitScript(FAKE_CAP);
  const page = await ctx.newPage();
  const errors = []; page.on('pageerror', e => errors.push(String(e)));
  const apiOrigins = new Set();
  // tarazu.live -> local server, with the real server's CORS behaviour (app origin is http://127.0.0.1:8099 here, so allow it the way capacitor://localhost is allowed)
  await page.route('https://tarazu.live/**', async route => {
    const req = route.request(); const u = new URL(req.url()); apiOrigins.add(req.headers()['origin']);
    const cors = { 'Access-Control-Allow-Origin': 'http://127.0.0.1:8099', 'Access-Control-Allow-Headers': 'Content-Type, X-Token, Authorization, X-Tarazu-Client', 'Access-Control-Allow-Methods': 'POST, GET, OPTIONS', 'Access-Control-Expose-Headers': 'X-Tarazu-Token' };
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: cors });
    const r = await fetch(LOCAL + u.pathname.replace(/^\/nirani/, '') + u.search, { method: req.method(), headers: { 'Content-Type': 'application/json', 'X-Token': req.headers()['x-token'] || '', 'X-Tarazu-Client': req.headers()['x-tarazu-client'] || '' }, body: req.method() === 'POST' ? req.postData() : undefined });
    const body = Buffer.from(await r.arrayBuffer());
    route.fulfill({ status: r.status, headers: { ...cors, 'Content-Type': r.headers.get('content-type') || 'application/json' }, body });
  });
  const tz = (op, body, tok) => fetch(`${LOCAL}/api.php?op=${op}`, { method: 'POST', headers: { 'Content-Type': 'application/json', ...(tok ? { 'X-Token': tok } : {}) }, body: JSON.stringify(body || {}) }).then(r => r.json());
  const master = (await tz('login', { user_id: 'm1', pin: '1111' })).token;
  await tz('update', { path: 'config/auth', data: { phone_required: true, otp_required: false } }, master);

  await page.goto('http://127.0.0.1:8099/nirani/'); await page.waitForSelector('#ph-num', { timeout: 15000 });
  ok('app opens on the Nirani sign-in', (await page.title()).includes('Nirani'));
  ok('app screens are bundled, not loaded from the website', page.url().startsWith('http://127.0.0.1:8099/'));
  await page.fill('#ph-num', '9000000003'); await page.fill('#ph-pin', '2222'); await page.click('#phone-form button[type=submit]');
  await page.waitForFunction(() => !document.querySelector('#ph-num'), null, { timeout: 15000 });
  ok('manager signs in through the app', true);
  const kc = await page.evaluate(() => window.__keychain), ls = await page.evaluate(() => Object.keys(localStorage));
  ok('session token saved in the Keychain/Keystore', /^tz1_/.test(kc['tarazu.live/session'] || ''), JSON.stringify(kc));
  ok('session token not in web storage', !ls.includes('tarazu.token'), ls);
  await page.waitForTimeout(900);
  ok('native push registered after sign-in', (await page.evaluate(() => window.__calls)).includes('push-register'));
  const { execFileSync } = require('child_process');
  const n = execFileSync('python3', [__dirname + '/q.py', "select count(*) from native_push where user_id='mg1'"]).toString().trim();
  ok('server stored the phone push token', n === '1', n);
  const cl = execFileSync('python3', [__dirname + '/q.py', "select client from sessions where user_id='mg1' order by created desc limit 1"]).toString().trim();
  ok('server sees the app as the client', cl.startsWith('app-android/'), cl);
  ok('API calls carry the app origin (CORS)', apiOrigins.has('http://127.0.0.1:8099'));

  await page.reload(); await page.waitForTimeout(1500);
  ok('stays signed in after the app restarts (token read back from Keychain)', await page.locator('#ph-num').count() === 0);
  ok('Face ID / fingerprint asked on start', (await page.evaluate(() => window.__calls)).includes('biometric'));
  await page.evaluate(() => { window.__fireApp('appStateChange', { isActive: false }); });
  ok('screen hidden in the app switcher', await page.locator('#tz-shield').isVisible());
  await page.evaluate(() => { window.__fireApp('appStateChange', { isActive: true }); });
  await page.waitForTimeout(200); ok('shield removed on quick return', !(await page.locator('#tz-shield').isVisible()));
  await page.screenshot({ path: '/tmp/tz-app-home.png' });
  await page.click('[data-act="logout"]'); await page.waitForSelector('#ph-num', { timeout: 8000 });
  const kc2 = await page.evaluate(() => window.__keychain);
  ok('sign out clears the Keychain', !kc2['tarazu.live/session']);
  ok('no script errors in the app', errors.length === 0, errors.slice(0, 3));
  await browser.close(); srv.kill();
  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
