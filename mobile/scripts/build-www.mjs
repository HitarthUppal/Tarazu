/* Builds mobile/www from web/: the same screens as tarazu.live, packed inside the app.
   - tarazu-native.js loads first; the page's own scripts are held (type="text/tarazu-boot") until the
     secure session tokens are read from the Keychain / Keystore.
   - No service worker (the app bundle is the cache); push comes from the native plugin.
   - The Nirani app opens at /nirani/ (capacitor appStartPath), so a copy lives there with its assets. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const web = path.resolve(here, '../../web');
const out = path.resolve(here, '../www');
const pkg = JSON.parse(fs.readFileSync(path.resolve(here, '../package.json'), 'utf8'));
const ASSETS = ['icon-192.png', 'icon-512.png', 'nirani-logo.png', 'agrimandi-logo.png', 'manifest.webmanifest'];

fs.rmSync(out, { recursive: true, force: true });
fs.mkdirSync(path.join(out, 'nirani'), { recursive: true });

let html = fs.readFileSync(path.join(web, 'index.html'), 'utf8');
const scripts = (html.match(/<script>/g) || []).length;
if (scripts !== 3) throw new Error(`expected 3 inline scripts in web/index.html, found ${scripts}: check the hold-back step`);
html = html.replace('<script>if("serviceWorker" in navigator) window.addEventListener("load",()=>navigator.serviceWorker.register("sw.js").catch(()=>{}));</script>', '');
html = html.replace(/<script>/g, '<script type="text/tarazu-boot">');
html = html.replace('<head>', '<head>\n<script src="/tarazu-native.js"></script>');
if (!html.includes('/tarazu-native.js')) throw new Error('could not inject the native bridge');

const bridge = fs.readFileSync(path.resolve(here, '../src/tarazu-native.js'), 'utf8').replace('__APP_VERSION__', pkg.version);
fs.writeFileSync(path.join(out, 'tarazu-native.js'), bridge);
for (const dir of ['', 'nirani']) {
  fs.writeFileSync(path.join(out, dir, 'index.html'), html);
  fs.cpSync(path.join(web, 'fonts'), path.join(out, dir, 'fonts'), { recursive: true });
  for (const a of ASSETS) if (fs.existsSync(path.join(web, a))) fs.copyFileSync(path.join(web, a), path.join(out, dir, a));
}
console.log(`www built: ${fs.readdirSync(out).length} entries, app ${pkg.version}`);
