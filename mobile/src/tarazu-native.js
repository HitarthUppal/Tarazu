/*
 * Tarazu native bridge.
 * Loaded first inside the iOS / Android app, before the Tarazu web app itself.
 * - Points API and file calls at the live server (the screens are bundled in the app).
 * - Keeps the session token in the phone's secure storage (Keychain / Keystore), not in web storage.
 * - Registers for native push (APNs on iOS, FCM on Android) and hands the token to the server.
 * - Locks the app behind Face ID / fingerprint after it has been in the background.
 * - Opens outside links in the system browser.
 */
(function () {
  'use strict';
  var Cap = window.Capacitor;
  if (!Cap || !Cap.isNativePlatform || !Cap.isNativePlatform()) return;

  var API_ORIGIN = 'https://tarazu.live';
  var API_BASE = API_ORIGIN + '/nirani/';
  var LOCK_AFTER_MS = 60 * 1000;            // lock if away longer than a minute
  var TOKEN_SERVER = 'tarazu.live';         // key under which the biometric plugin stores the session

  var P = Cap.Plugins;
  var platform = Cap.getPlatform();         // 'ios' | 'android'

  window.TARAZU_NATIVE = { platform: platform, apiBase: API_BASE, version: '__APP_VERSION__' };

  // ---- 1. No service worker inside the app (the app bundle is the cache) ----
  if ('serviceWorker' in navigator) {
    try {
      Object.defineProperty(navigator, 'serviceWorker', { value: undefined, configurable: true });
    } catch (e) { /* ignore */ }
  }

  // ---- 2. Session token in secure storage ----
  var token = null;
  var tokenReady = (async function () {
    try {
      var c = await P.NativeBiometric.getCredentials({ server: TOKEN_SERVER });
      token = c && c.password ? c.password : null;
    } catch (e) { token = null; }
  })();

  async function saveToken(t) {
    token = t || null;
    try {
      if (t) await P.NativeBiometric.setCredentials({ username: 'session', password: t, server: TOKEN_SERVER });
      else await P.NativeBiometric.deleteCredentials({ server: TOKEN_SERVER });
    } catch (e) { /* storage unavailable: token lives in memory only */ }
  }
  window.TARAZU_NATIVE.saveToken = saveToken;
  window.TARAZU_NATIVE.getToken = function () { return token; };

  // ---- 3. Route relative API / file calls to the server, with the token ----
  function absolutise(u) {
    if (typeof u !== 'string') return u;
    if (/^(https?:|data:|blob:|capacitor:)/i.test(u)) return u;
    if (u.charAt(0) === '/') return API_ORIGIN + u;
    return API_BASE + u.replace(/^\.\//, '');
  }
  function isOurs(u) { return typeof u === 'string' && u.indexOf(API_ORIGIN + '/') === 0; }

  var nativeFetch = window.fetch.bind(window);
  window.fetch = async function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url);
    var abs = absolutise(url);
    if (!isOurs(abs)) return nativeFetch(input, init);
    await tokenReady;
    init = Object.assign({}, init || {});
    var h = new Headers(init.headers || (typeof input !== 'string' && input.headers) || {});
    if (token && !h.has('Authorization')) h.set('Authorization', 'Bearer ' + token);
    h.set('X-Tarazu-Client', 'app-' + platform + '/' + window.TARAZU_NATIVE.version);
    init.headers = h;
    init.credentials = 'omit';
    if (typeof input !== 'string') {
      init.method = init.method || input.method;
      if (init.body === undefined && input.method && input.method !== 'GET') init.body = await input.clone().blob();
    }
    var res = await nativeFetch(abs, init);
    // The server hands back a fresh token on sign-in and rotation.
    var nt = res.headers.get('X-Tarazu-Token');
    if (nt !== null) await saveToken(nt === 'revoked' ? null : nt);
    if (res.status === 401 && token) await saveToken(null);
    return res;
  };

  // Media (voice notes, logos) referenced by relative path.
  var srcDesc = Object.getOwnPropertyDescriptor(HTMLMediaElement.prototype, 'src');
  if (srcDesc && srcDesc.set) {
    Object.defineProperty(HTMLMediaElement.prototype, 'src', {
      get: srcDesc.get,
      set: function (v) { srcDesc.set.call(this, absolutise(v)); },
      configurable: true
    });
  }

  // ---- 4. Outside links open in the system browser ----
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    var href = a.getAttribute('href');
    if (/^https?:/i.test(href) && !isOurs(href)) {
      e.preventDefault();
      P.Browser.open({ url: href });
    } else if (/^(tel:|mailto:|whatsapp:)/i.test(href)) {
      e.preventDefault();
      window.open(href, '_system');
    }
  }, true);

  // ---- 5. Native push ----
  async function registerPush() {
    var PN = P.PushNotifications;
    if (!PN) return;
    try {
      var perm = await PN.checkPermissions();
      if (perm.receive === 'prompt' || perm.receive === 'prompt-with-rationale') perm = await PN.requestPermissions();
      if (perm.receive !== 'granted') return;
      await PN.addListener('registration', function (t) {
        window.fetch('api.php?action=push_native_register', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ platform: platform, token: t.value })
        }).catch(function () {});
      });
      await PN.addListener('pushNotificationActionPerformed', function (a) {
        var d = (a && a.notification && a.notification.data) || {};
        if (d.route && typeof window.tarazuOpenRoute === 'function') window.tarazuOpenRoute(d.route);
      });
      await PN.register();
    } catch (e) { /* push not configured on this build */ }
  }
  window.TARAZU_NATIVE.registerPush = registerPush;

  // ---- 6. Biometric lock ----
  var hiddenAt = 0, locked = false;
  function lockEnabled() {
    try { return localStorage.getItem('tz_bio_lock') !== 'off'; } catch (e) { return true; }
  }
  function showShield(on) {
    var s = document.getElementById('tz-shield');
    if (!s) {
      s = document.createElement('div');
      s.id = 'tz-shield';
      s.setAttribute('style', 'position:fixed;inset:0;z-index:2147483647;background:#0E3B2E;color:#fff;display:none;' +
        'align-items:center;justify-content:center;flex-direction:column;font:600 18px system-ui;gap:16px');
      s.innerHTML = '<div>Tarazu is locked</div><button id="tz-unlock" style="font:600 16px system-ui;padding:12px 28px;' +
        'border-radius:10px;border:0;background:#fff;color:#0E3B2E">Unlock</button>';
      document.documentElement.appendChild(s);
      s.querySelector('#tz-unlock').addEventListener('click', unlock);
    }
    s.style.display = on ? 'flex' : 'none';
  }
  async function unlock() {
    try {
      var avail = await P.NativeBiometric.isAvailable({ useFallback: true });
      if (!avail.isAvailable) { locked = false; showShield(false); return; }
      await P.NativeBiometric.verifyIdentity({
        reason: 'Unlock Tarazu', title: 'Unlock Tarazu', subtitle: 'Sale prices and decisions are private',
        useFallback: true, maxAttempts: 3
      });
      locked = false; showShield(false);
    } catch (e) { showShield(true); }
  }
  async function maybeLock() {
    if (!lockEnabled() || !token) return;
    locked = true; showShield(true); await unlock();
  }
  if (P.App) {
    P.App.addListener('appStateChange', function (st) {
      if (!st.isActive) { hiddenAt = Date.now(); showShield(lockEnabled() && !!token); }
      else if (hiddenAt && Date.now() - hiddenAt > LOCK_AFTER_MS) maybeLock();
      else if (!locked) showShield(false);
    });
  }

  // ---- 7. Start up ----
  window.addEventListener('DOMContentLoaded', async function () {
    await tokenReady;
    if (token) { maybeLock(); registerPush(); }
    try { P.SplashScreen && P.SplashScreen.hide(); } catch (e) {}
  });
})();
