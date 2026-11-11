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

  // ---- 2. Session tokens live in the Keychain (iOS) / Keystore (Android), never in web storage ----
  // The web app reads and writes them through localStorage; for these keys the calls are redirected to memory,
  // backed by secure storage. The app's own scripts are held back until the tokens are loaded (see 7).
  var SECURE = { 'tarazu.token': 'tarazu.live/session', 'tarazu.mtoken': 'tarazu.live/master-session' };
  var mem = {};
  function loadSecure() {
    return Promise.all(Object.keys(SECURE).map(function (k) {
      return P.NativeBiometric.getCredentials({ server: SECURE[k] })
        .then(function (c) { if (c && c.password) mem[k] = c.password; })
        .catch(function () {});
    }));
  }
  function persist(k, v) {
    try {
      if (v) P.NativeBiometric.setCredentials({ username: k, password: v, server: SECURE[k] }).catch(function () {});
      else P.NativeBiometric.deleteCredentials({ server: SECURE[k] }).catch(function () {});
    } catch (e) {}
    if (k === 'tarazu.token' && v) setTimeout(registerPush, 500);
  }
  var proto = Storage.prototype, oGet = proto.getItem, oSet = proto.setItem, oDel = proto.removeItem;
  proto.getItem = function (k) { if (this === window.localStorage && SECURE[k]) return mem[k] || null; return oGet.apply(this, arguments); };
  proto.setItem = function (k, v) { if (this === window.localStorage && SECURE[k]) { mem[k] = String(v); persist(k, mem[k]); return; } return oSet.apply(this, arguments); };
  proto.removeItem = function (k) { if (this === window.localStorage && SECURE[k]) { delete mem[k]; persist(k, null); return; } return oDel.apply(this, arguments); };
  function hasSession() { return !!(mem['tarazu.token'] || mem['tarazu.mtoken']); }

  // ---- 3. Relative API and file calls go to the live server ----
  function absolutise(u) {
    if (typeof u !== 'string') return u;
    if (/^(https?:|data:|blob:|capacitor:|about:)/i.test(u)) return u;
    if (u.charAt(0) === '/') return API_ORIGIN + u;
    return API_BASE + u.replace(/^\.\//, '');
  }
  function isOurs(u) { return typeof u === 'string' && u.indexOf(API_ORIGIN + '/') === 0; }
  function isApi(u) { return typeof u === 'string' && /(^|\/)api\.php(\?|$)/.test(u); }

  var nativeFetch = window.fetch.bind(window);
  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url);
    if (!isApi(url) && !isOurs(url)) return nativeFetch(input, init);
    init = Object.assign({}, init || {});
    var h = new Headers(init.headers || (typeof input !== 'string' && input.headers) || {});
    h.set('X-Tarazu-Client', 'app-' + platform + '/' + window.TARAZU_NATIVE.version);
    init.headers = h; init.credentials = 'omit';
    return nativeFetch(absolutise(url), init);
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
  var pushStarted = false;
  async function registerPush() {
    var PN = P.PushNotifications;
    if (!PN || !mem['tarazu.token']) return;
    if (pushStarted) { try { await PN.register(); } catch (e) {} return; }
    pushStarted = true;
    if (platform === 'android') {
      try { await PN.createChannel({ id: 'tarazu', name: 'Tarazu updates', description: 'Rounds, advisor views, polls and decisions', importance: 4, visibility: 0, vibration: true }); } catch (e) {}
    }
    try {
      var perm = await PN.checkPermissions();
      if (perm.receive === 'prompt' || perm.receive === 'prompt-with-rationale') perm = await PN.requestPermissions();
      if (perm.receive !== 'granted') return;
      await PN.addListener('registration', function (t) {
        window.fetch('api.php?op=push_native_register', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Token': mem['tarazu.token'] || '' },
          body: JSON.stringify({ platform: platform, token: t.value })
        }).catch(function () {});
      });
      await PN.addListener('pushNotificationActionPerformed', function (a) {
        var d = (a && a.notification && a.notification.data) || {};
        if (d.route && d.route.charAt(0) === '#') location.hash = d.route;
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
    if (!lockEnabled() || !hasSession()) return;
    locked = true; showShield(true); await unlock();
  }
  if (P.App) {
    P.App.addListener('appStateChange', function (st) {
      if (!st.isActive) { hiddenAt = Date.now(); showShield(lockEnabled() && hasSession()); }
      else if (hiddenAt && Date.now() - hiddenAt > LOCK_AFTER_MS) maybeLock();
      else if (!locked) showShield(false);
    });
  }

  // ---- 7. Start up: load the secure tokens, then run the app's scripts in order ----
  function runHeldScripts() {
    var held = Array.prototype.slice.call(document.querySelectorAll('script[type="text/tarazu-boot"]'));
    held.forEach(function (old) {
      var s = document.createElement('script');
      s.text = old.textContent;
      old.parentNode.replaceChild(s, old);
    });
  }
  function start() {
    loadSecure().then(function () {
      runHeldScripts();
      if (hasSession()) { maybeLock(); registerPush(); }
      try { P.SplashScreen && P.SplashScreen.hide(); } catch (e) {}
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
