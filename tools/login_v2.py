#!/usr/bin/env python3
"""Sign-in screen v2: mobile number + WhatsApp code (or + PIN when codes are off). Removes the name list and Login demo."""
import sys, pathlib, re
p = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else 'web/index.html')
s = p.read_text(encoding='utf-8')
MARK = '/* tarazu-login v2 */'
if MARK in s: print('already applied'); sys.exit(0)
def rep(old, new, count=1):
    global s
    n = s.count(old); assert n == count, f'expected {count} of {old[:70]!r}, found {n}'; s = s.replace(old, new)

# 1. no Login demo button
rep('<button id="login-demo" class="sm" data-act="login-demo" hidden style="min-height:32px;padding:4px 10px;font-size:13px">Login demo</button>', '')
rep('''  const ld = $("#login-demo"); if(ld) ld.hidden = !!S.me || phoneRequired() || S.route.name === "phone-login";
  if(!S.me){ setTabs(null); m.innerHTML = (phoneRequired() || S.route.name === "phone-login") ? phoneLoginView() : loginView(); return; }''',
'''  if(!S.me){ setTabs(null); m.innerHTML = phoneLoginView(); if(S.draft.focusOtp){ S.draft.focusOtp = false; setTimeout(() => { const e = $("#ph-otp"); if(e) e.focus(); }, 0); } return; }''')

# 2. the sign-in screen
a = s.index('function phoneLoginView(){'); b = s.index('async function finishLogin', a)
view = r'''function phoneLoginView(){ /* tarazu-login v2 */
  const otp = otpRequired(), step2 = otp && S.draft.otpFor && !S.draft.otpPin;
  const pinStep = !otp || S.draft.otpPinInstead;
  const clientCards = SITE.client ? "" : `<div class="client-pick"><h3>Clients</h3>${SITE.routed.map(slug => { const c = S.clients.find(x => x.id === slug); return `<a class="client-card" href="${esc(siteUrl(slug))}"><img src="${esc(slug)}-logo.png" alt="${esc((c && c.name) || "Nirani Group")}"><b>Open →</b></a>`; }).join("")}</div>`;
  const wait = Math.max(0, Math.ceil(((S.draft.otpAt || 0) + 30000 - Date.now()) / 1000));
  const form = step2
    ? `<form id="otp-verify-form" class="list">
        <div class="row between" style="align-items:center"><span>Code sent to <b>${esc(S.draft.otpMasked || "")}</b> on WhatsApp</span><button type="button" class="ghost sm" data-act="otp-change">Change number</button></div>
        <label>6-digit code<input id="ph-otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="••••••" style="letter-spacing:6px;font-size:22px;text-align:center"></label>
        <button class="primary" type="submit">Sign in</button>
        <button type="button" class="ghost" data-act="otp-resend" ${wait ? "disabled" : ""}>${wait ? `Resend code in ${wait}s` : "Resend code"}</button>
      </form>`
    : `<form id="${pinStep ? "phone-form" : "otp-request-form"}" class="list">
        <label>Mobile number<div class="phone-in"><span>+91</span><input id="ph-num" inputmode="tel" autocomplete="tel-national" maxlength="16" placeholder="98765 43210" value="${esc(S.draft.ph||"")}"></div></label>
        ${pinStep ? `<label>PIN<input id="ph-pin" type="password" inputmode="numeric" autocomplete="current-password" maxlength="6" placeholder="4 to 6 digits"></label>` : ""}
        <button class="primary" type="submit">${pinStep ? "Sign in" : "Get OTP"}</button>
      </form>`;
  return `<div class="login-wrap">
  <section class="hero"><div class="hero-brand">${SITE.client ? `<img class="client-hero-logo" src="${esc(SITE.client)}-logo.png" alt="Nirani Sugars"><span class="hero-sep" aria-hidden="true"></span>` : ""}<div class="mark">तराज़ू<small>Tarazu</small></div></div><p class="muted">Sugar Sale Intelligence System</p><p class="powered-line">Powered by <img class="am-logo lg" src="agrimandi-logo.png" alt="AgriMandi" onerror="this.replaceWith(Object.assign(document.createElement('em'),{textContent:'AgriMandi'}))"></p>
    <ul class="login-points"><li>Sign in with the mobile number your administrator registered.</li><li>${otp ? "A 6-digit code arrives on WhatsApp. It works for 5 minutes." : "Use the PIN your administrator gave you."}</li><li>This device stays signed in for 30 days, until you sign out.</li></ul></section>
  ${clientCards}<section class="card login-card"><h2>${SITE.client ? "Sign in" : "Sign in to Tarazu"}</h2>
    ${form}
    ${S.err.login?`<div class="notice err">${esc(S.err.login)}</div>`:""}
    <p class="small muted">Not registered? Ask your administrator to add your number.</p>
  </section></div>`;
}
'''
s = s[:a] + view + s[b:]

# 3. form handling: request code, verify code
rep('''  if(f.id === "phone-form"){''', '''  if(f.id === "otp-request-form"){ const ph = normPhone($("#ph-num").value); S.draft.ph = $("#ph-num").value;
    if(!/^[6-9]\\d{9}$/.test(ph)){ S.err.login = "Enter your 10-digit mobile number."; render(); return; }
    const btn = f.querySelector("button[type=submit]"); if(btn){ btn.disabled = true; btn.textContent = "Sending…"; }
    try{ const j = await S.db._call("otp_request", {phone:ph});
      S.err.login = ""; S.draft.otpFor = ph; S.draft.otpMasked = j.masked; S.draft.otpAt = Date.now();
      if(j.pin_instead){ S.draft.otpPinInstead = true; S.draft.otpFor = null; render(); return; }
      S.draft.focusOtp = true; render(); otpTick();
    }catch(err){ S.err.login = err.message || "Could not send the code."; render(); }
    return; }
  if(f.id === "otp-verify-form"){ const code = ($("#ph-otp").value || "").replace(/\\D/g,"");
    if(code.length !== 6){ S.err.login = "Enter the 6-digit code."; render(); return; }
    const btn = f.querySelector("button[type=submit]"); if(btn){ btn.disabled = true; btn.textContent = "Checking…"; }
    let u = null, why = "";
    try{ const j = await S.db._call("otp_verify", {phone:S.draft.otpFor, otp:code}); store.set("tarazu.token", j.token); await S.db._resync(); u = S.users.find(x => x.id === j.user_id) || null; }
    catch(err){ why = err.message || "That code does not match."; }
    if(u) await finishLogin(u); else { S.err.login = why || "Could not sign in."; S.draft.focusOtp = true; render(); } return; }
  if(f.id === "phone-form"){''')

# 4. buttons: change number, resend
rep('''async function securityClick(a, b, d){''', '''function otpTick(){ clearTimeout(otpTick._t); if(S.me || !S.draft.otpFor) return; const left = (S.draft.otpAt || 0) + 30000 - Date.now(); const btn = document.querySelector('[data-act="otp-resend"]');
  if(btn){ const w = Math.max(0, Math.ceil(left / 1000)); btn.disabled = w > 0; btn.textContent = w ? `Resend code in ${w}s` : "Resend code"; }
  if(left > 0) otpTick._t = setTimeout(otpTick, 1000); }
async function securityClick(a, b, d){
  if(a === "otp-change"){ S.draft.otpFor = null; S.draft.otpPinInstead = false; S.err.login = ""; render(); setTimeout(() => { const e = $("#ph-num"); if(e) e.focus(); }, 0); return true; }
  if(a === "otp-resend"){ try{ const j = await S.db._call("otp_request", {phone:S.draft.otpFor}); S.draft.otpAt = Date.now(); S.draft.otpMasked = j.masked; S.err.login = ""; toast("New code sent on WhatsApp"); S.draft.focusOtp = true; render(); otpTick(); }catch(err){ S.err.login = err.message || "Could not send the code."; render(); } return true; }''')
p.write_text(s, encoding='utf-8'); print('applied')
