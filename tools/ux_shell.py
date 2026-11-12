#!/usr/bin/env python3
"""UX shell: app bar with page title and profile sheet, icon tab bar (phone) / sidebar (desktop),
floating primary action, skeleton loading, 16px inputs on phones. Idempotent."""
import sys, pathlib
p = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else 'web/index.html')
s = p.read_text(encoding='utf-8')
MARK = '/* tarazu-shell v1 */'
if MARK in s:
    print('already applied'); sys.exit(0)

def rep(old, new, count=1):
    global s
    n = s.count(old)
    assert n == count, f'expected {count} of {old[:70]!r}, found {n}'
    s = s.replace(old, new)

# ---------- markup ----------
rep('<span id="who"></span></div>\n  </header>',
    '<span id="who"></span></div>\n    <div class="ab-title" id="ab-title"></div>\n  </header>')
rep('''<section class="hero"><div class="mark">तराज़ू<small>Tarazu</small></div>
      <p class="muted">Sugar Sale Intelligence System. Loading the group's shared data…</p></section>''',
    '''<div class="skel" aria-label="Loading Tarazu"><div class="sk sk-h"></div><div class="sk sk-card"></div><div class="sk sk-card"></div><div class="sk sk-row"></div></div>''')
rep('<div class="toast" id="toast" hidden></div>',
    '<div class="toast" id="toast" hidden></div>\n<button class="fab" id="fab" hidden></button>\n<div class="sheet-wrap" id="sheet" hidden><div class="sheet-bg" data-act="sheet-close"></div><div class="sheet" role="dialog" aria-modal="true" aria-label="Your account" id="sheet-body"></div></div>')

# ---------- CSS ----------
CSS = r'''
/* tarazu-shell v1 */
.ab-title{display:none}
body:not(.authed) header.bar{display:none}
body.authed header.bar .brand .sub,body.authed header.bar .tz-word{display:none}
.avatar{width:38px;height:38px;min-height:38px;padding:0;border-radius:50%;border:0;background:var(--cane);color:var(--on-cane);font:800 14px/38px var(--body);letter-spacing:.3px;text-align:center;cursor:pointer;flex:none}
.avatar:focus-visible{outline:2px solid var(--cane);outline-offset:2px}
#who .via{font-size:12px}
/* phone */
body.phone.authed header.bar{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:10px;min-height:56px;padding-block:8px;flex-wrap:nowrap}
body.phone.authed header.bar .brand{grid-column:1}
body.phone.authed header.bar .client-logo{height:26px;width:auto}
body.phone.authed .ab-title{display:block;grid-column:2;grid-row:1;font:800 20px/1.1 var(--body);letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
body.phone.authed header.bar .hdr-r{grid-column:3;grid-row:1;flex-wrap:nowrap}
body.phone input,body.phone select,body.phone textarea{font-size:16px !important}
body.phone button{min-height:44px}
body.phone nav.tabs{gap:0;padding:4px max(4px,env(safe-area-inset-right,0px)) calc(4px + env(safe-area-inset-bottom,0px)) max(4px,env(safe-area-inset-left,0px));box-shadow:0 -1px 0 var(--line)}
nav.tabs button{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;position:relative;min-height:56px;border-radius:12px;padding:6px 4px}
nav.tabs .ti svg{width:24px;height:24px;display:block}
nav.tabs .tl{font-size:12px;font-weight:600;line-height:1.1}
nav.tabs button[aria-current="page"]{background:transparent}
nav.tabs button[aria-current="page"] .ti{background:var(--cane-soft);border-radius:999px;padding:2px 16px}
nav.tabs .badge{position:absolute;top:4px;left:calc(50% + 8px);min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:var(--danger);color:#fff;font:700 11px/18px var(--body)}
.nav-brand{display:none}
/* desktop */
body.desk.authed{padding-left:calc(248px + 24px)}
body.desk.authed header.bar{min-height:64px}
body.desk.authed header.bar .brand{display:none}
body.desk.authed .ab-title{display:block;font:800 24px/1.1 var(--body);letter-spacing:-.3px;margin-right:auto;order:-1}
body.desk.authed header.bar{flex-wrap:nowrap}
body.desk nav.tabs{top:0;bottom:0;right:auto;width:248px;flex-direction:column;justify-content:flex-start;align-items:stretch;gap:4px;padding:20px 14px;border-top:0;border-right:1px solid var(--line);overflow-y:auto}
body.desk nav.tabs button{flex:none;max-width:none;flex-direction:row;justify-content:flex-start;gap:12px;min-height:46px;padding:10px 14px;font-size:15px}
body.desk nav.tabs .tl{font-size:15px}
body.desk nav.tabs button[aria-current="page"]{background:var(--cane-soft)}
body.desk nav.tabs button[aria-current="page"] .ti{background:transparent;padding:0}
body.desk nav.tabs .badge{position:static;margin-left:auto}
body.desk .nav-brand{display:flex;flex-direction:column;gap:4px;padding:0 6px 18px;margin-bottom:8px;border-bottom:1px solid var(--line)}
.nav-brand .tz-word b{font-size:32px}
.nav-brand small{font-size:12px;color:var(--muted)}
.nav-brand img.cl{height:30px;width:auto;align-self:flex-start;margin-bottom:6px}
/* floating action */
.fab{position:fixed;z-index:7;right:max(16px,env(safe-area-inset-right,0px));bottom:calc(84px + env(safe-area-inset-bottom,0px));display:inline-flex;align-items:center;gap:8px;min-height:52px;padding:0 20px 0 16px;border:0;border-radius:16px;background:var(--cane);color:var(--on-cane);font:700 15px var(--body);box-shadow:0 6px 18px rgba(0,0,0,.18)}
.fab svg{width:22px;height:22px}
body.desk .fab{bottom:28px;right:32px}
/* account sheet */
.sheet-wrap{position:fixed;inset:0;z-index:20}
.sheet-bg{position:absolute;inset:0;background:rgba(10,20,14,.42)}
.sheet{position:absolute;left:0;right:0;bottom:0;max-height:88vh;overflow-y:auto;background:var(--card);border-radius:20px 20px 0 0;padding:10px 20px calc(20px + env(safe-area-inset-bottom,0px));display:flex;flex-direction:column;gap:14px;box-shadow:0 -8px 30px rgba(0,0,0,.2)}
.sheet::before{content:"";width:40px;height:4px;border-radius:2px;background:var(--line);align-self:center}
body.desk .sheet{left:auto;right:24px;top:72px;bottom:auto;width:360px;border-radius:16px}
body.desk .sheet::before{display:none}
.sheet .who{display:flex;gap:12px;align-items:center}
.sheet .who .avatar{width:48px;height:48px;font-size:17px;line-height:48px}
.sheet .who b{font-size:17px}
.sheet .row-btn{display:flex;align-items:center;justify-content:space-between;width:100%;min-height:48px;padding:10px 4px;border:0;border-top:1px solid var(--line);border-radius:0;background:transparent;font:600 15px var(--body);color:var(--ink);text-align:left}
.sheet .row-btn span:last-child{color:var(--muted);font-weight:500;font-size:14px}
.sheet .row-btn.danger{color:var(--danger)}
.sheet .pw{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);justify-content:center;padding-top:6px}
.sheet .pw img{height:18px}
/* skeleton */
.skel{display:flex;flex-direction:column;gap:14px;padding-top:20px}
.sk{background:linear-gradient(90deg,var(--line) 0%,var(--zebra) 50%,var(--line) 100%);background-size:200% 100%;animation:skp 1.2s ease-in-out infinite;border-radius:12px}
.sk-h{height:28px;width:45%}.sk-card{height:120px}.sk-row{height:52px}
@keyframes skp{0%{background-position:100% 0}100%{background-position:-100% 0}}
@media (prefers-reduced-motion: reduce){.sk{animation:none}}
'''
rep('</style>', CSS + '</style>', 1)

# ---------- JS ----------
JS = r'''
/* tarazu-shell v1: icons, page title, account sheet, floating action */
const ICON = {
  home:'<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
  mills:'<path d="M3 21V10l5 3V10l5 3V6h3l1 4h3v11z"/><path d="M7 17h2M12 17h2M17 17h1"/>',
  today:'<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
  rounds:'<path d="M12 3v18M5 7h14"/><path d="M5 7l-3 7a4 3 0 0 0 6 0z"/><path d="M19 7l-3 7a4 3 0 0 0 6 0z"/>',
  sales:'<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
  admin:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
  market:'<path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/>',
  master:'<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
  client:'<path d="M4 21V5l8-2v18M12 9h8v12M8 8v.01M8 12v.01M8 16v.01M16 13v.01M16 17v.01"/>',
  plus:'<path d="M12 5v14M5 12h14"/>',
  arrow:'<path d="M5 12h14M13 6l6 6-6 6"/>'
};
const svg = k => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICON[k] || ICON.home}</svg>`;
const TAB_ICON = {Home:"home", Mills:"mills", Today:"today", Rounds:"rounds", Sales:"sales", Admin:"admin", Market:"market", Master:"master", Client:"client"};
const TITLES = {round:"Round", poll:"Poll", "poll-new":"New poll", comment:"Comments", admin:"Admin", client:"Client", master:"Master admin", market:"Market", sales:"Sales", rounds:"Rounds"};
function initials(n){ return String(n || "?").split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join("").toUpperCase() || "?"; }
function navBrand(){
  return `<div class="nav-brand">${SITE.client ? `<img class="cl" src="${esc(SITE.client)}-logo.png" alt="">` : ""}<span class="tz-word"><b>तराज़ू</b><i>Tarazu</i></span><small>Sugar Sale Intelligence System</small></div>`;
}
function pageTitle(){
  const cur = S.route.tab || S.route.name;
  if(S.route.name === "round"){ const r = S.rounds.find(x => x.id === S.route.id); return r ? (r.title || "Round") : "Round"; }
  const t = (S.tabItems || []).find(([n]) => n === S.route.name) || (S.tabItems || []).find(([n]) => n === cur);
  return t ? t[1] : (TITLES[S.route.name] || "Tarazu");
}
function fabSpec(){
  if(!S.me || S.route.name === "round") return null;
  const role = S.me.role;
  if(role === "manager" && S.route.name !== "home") return {act:"new-round", icon:"plus", label:"New round"};
  if(role === "panelist"){
    const r = S.rounds.find(x => isOpen(x) && !S.rviews.find(v => v.round_id === x.id && v.panelist_id === S.me.id));
    if(r) return {act:"open", id:r.id, icon:"arrow", label:"Give your view"};
  }
  if(role === "owner"){
    const r = S.rounds.find(x => x.status !== "decided" && !isOpen(x));
    if(r) return {act:"open", id:r.id, icon:"arrow", label:"Decide"};
  }
  return null;
}
function sheetHtml(){
  const u = S.me; if(!u) return "";
  const native = !!window.TARAZU_NATIVE;
  let lock = "On"; try{ lock = localStorage.getItem("tz_bio_lock") === "off" ? "Off" : "On"; }catch(e){}
  const webPush = HOSTED && !native && "Notification" in window && "serviceWorker" in navigator;
  const secRoute = isMaster() ? "master" : (u.role === "owner" ? "admin" : null);
  return `<div class="who"><span class="avatar" aria-hidden="true">${esc(initials(u.name))}</span><div><b>${esc(u.name)}</b><div class="small muted">${esc(roleLabel(u.role))}${S.client && !isMaster() ? " · " + esc(clientName(S.client)) : ""}</div></div></div>
    ${viaMaster() ? `<div class="notice small">You are viewing as ${esc(u.name)}. <button class="sm" data-act="back-master">Back to master</button></div>` : ""}
    <div>
      ${webPush ? `<button class="row-btn" data-act="push"><span>Notifications on this device</span><span>${pushLabel().replace("🔔 ", "")}</span></button>` : ""}
      ${native ? `<button class="row-btn" data-act="bio-lock"><span>Lock with Face ID or fingerprint</span><span>${lock}</span></button>` : ""}
      ${secRoute ? `<button class="row-btn" data-act="go" data-route="${secRoute}"><span>Security and activity</span><span>›</span></button>` : ""}
      <button class="row-btn" data-act="sec-logout-all"><span>Sign out my other devices</span><span>›</span></button>
      <button class="row-btn danger" data-act="logout"><span>Sign out</span><span></span></button>
    </div>
    <div class="pw">Powered by <img src="agrimandi-logo.png" alt="AgriMandi"></div>`;
}
function openSheet(){ const w = $("#sheet"); $("#sheet-body").innerHTML = sheetHtml(); w.hidden = false; const f = w.querySelector("button"); if(f) f.focus(); }
function closeSheet(){ const w = $("#sheet"); if(w) w.hidden = true; }
document.addEventListener("keydown", e => { if(e.key === "Escape") closeSheet(); });
document.addEventListener("click", e => {
  const b = e.target.closest && e.target.closest("#sheet [data-act]");
  if(b && b.dataset.act !== "bio-lock") closeSheet();
  const pb = e.target.closest && e.target.closest("button.primary, .fab");
  if(pb && window.TARAZU_NATIVE && window.Capacitor && window.Capacitor.Plugins.Haptics){ try{ window.Capacitor.Plugins.Haptics.impact({style:"LIGHT"}); }catch(err){} }
}, true);
function shellAfter(){
  document.body.classList.toggle("authed", !!S.me);
  const title = $("#ab-title"); if(title) title.textContent = S.me ? pageTitle() : "";
  if(S.me){ document.title = pageTitle() + " · Tarazu"; }
  const who = $("#who");
  if(who && S.me) who.innerHTML = `${viaMaster() ? `<span class="chip warn via">Viewing as ${esc(S.me.name)}</span>` : ""}<button class="avatar" data-act="profile" aria-label="Your account: ${esc(S.me.name)}">${esc(initials(S.me.name))}</button>`;
  const fab = $("#fab"), f = S.me ? fabSpec() : null;
  if(fab){
    fab.hidden = !f;
    if(f){ fab.dataset.act = f.act; if(f.id) fab.dataset.id = f.id; else delete fab.dataset.id; fab.innerHTML = svg(f.icon) + `<span>${esc(f.label)}</span>`; }
  }
  const sh = $("#sheet"); if(sh && !sh.hidden){ if(!S.me) closeSheet(); else $("#sheet-body").innerHTML = sheetHtml(); }
}
'''
rep('function setTabs(items){', JS + '\nfunction setTabs(items){')
rep('''  t.innerHTML = items.map(([name,label,badge]) => `<button data-act="go" data-route="${name}" ${cur===name?'aria-current="page"':""}>${esc(label)}${badge?` <span class="badge">${badge}</span>`:""}</button>`).join("");''',
    '''  S.tabItems = items;
  t.innerHTML = navBrand() + items.map(([name,label,badge]) => `<button data-act="go" data-route="${name}" ${cur===name?'aria-current="page"':""}><span class="ti">${svg(TAB_ICON[label] || name)}</span><span class="tl">${esc(label)}</span>${badge?`<span class="badge" aria-label="${badge} waiting">${badge}</span>`:""}</button>`).join("");''')
rep('function render(){\n  if(siteGuard()) return;', 'function render(){ renderCore(); shellAfter(); }\nfunction renderCore(){\n  if(siteGuard()) return;')
# actions for the sheet
rep('async function securityClick(a, b, d){', '''async function securityClick(a, b, d){
  if(a === "profile"){ openSheet(); return true; }
  if(a === "sheet-close"){ closeSheet(); return true; }
  if(a === "bio-lock"){ try{ const off = localStorage.getItem("tz_bio_lock") === "off"; localStorage.setItem("tz_bio_lock", off ? "on" : "off"); }catch(e){} $("#sheet-body").innerHTML = sheetHtml(); return true; }''')
p.write_text(s, encoding='utf-8')
print('applied')
