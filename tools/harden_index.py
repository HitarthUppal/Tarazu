#!/usr/bin/env python3
"""Security changes to web/index.html. Idempotent: each edit asserts the original text exists once (or was already applied)."""
import sys, pathlib
p = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else 'web/index.html')
s = p.read_text(encoding='utf-8')
MARK = '/* tarazu-security v1 */'
if MARK in s:
    print('already applied'); sys.exit(0)

def rep(old, new, count=1):
    global s
    n = s.count(old)
    assert n == count, f'expected {count} of {old[:70]!r}, found {n}'
    s = s.replace(old, new)

# 1. The n8n address no longer appears in the page.
rep('window.TARAZU_HOSTED={api:"api.php",n8n:"https://hitarthuppal1.app.n8n.cloud"};',
    'window.TARAZU_HOSTED={api:"api.php"};')

# 2. AI workflows go through api.php?op=flow (signed in, rate limited, shared key to n8n).
rep('const HOOKS = {"Market In":"tarazu-market-q8m4z2","Brief In":"tarazu-brief-q8m4z2","Voice Note In":"tarazu-voice-q8m4z2","Report In":"tarazu-report-q8m4z2"};',
    MARK + '\nconst HOOKS = {"Market In":1,"Brief In":1,"Voice Note In":1,"Report In":1};')
rep('''    const r = await fetch(HOSTED.n8n + "/webhook/" + HOOKS[triggerName], {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify(body)});
    const j = await r.json().catch(() => null);
    if(!r.ok || !j) throw {code:"flow_error", message:"The workflow did not answer (" + r.status + ")."};''',
'''    if(!HOOKS[triggerName]) throw {code:"flow_error", message:"Unknown workflow."};
    const r = await fetch(HOSTED.api + "?op=flow&trigger=" + encodeURIComponent(triggerName), {method:"POST", headers:{"Content-Type":"application/json", "X-Token":store.get("tarazu.token")||""}, body:JSON.stringify(body)});
    const j = await r.json().catch(() => null);
    if(r.status === 401){ store.set("tarazu.token", null); throw {code:"flow_error", message:"Please sign in again."}; }
    if(!r.ok || !j) throw {code:"flow_error", message:(j && j.error) || ("The workflow did not answer (" + r.status + ").")};''')

# 3. PINs are never shown: boxes are empty with a placeholder; typing a new one sets it.
pin_v2 = 'value="${esc(u.pin||"")}" style="width:90px;text-align:left">${u.pin === "0000" ? `<span class="sub" style="color:var(--jaggery)">default PIN</span>` : ""}` : esc(u.pin)}'
rep(pin_v2, 'value="" placeholder="${u.has_pin ? "•••• (set)" : "Set PIN"}" style="width:110px;text-align:left">${u.pin_default ? `<span class="sub" style="color:var(--jaggery)">default PIN</span>` : ""}` : (u.has_pin ? "set" : "not set")}')
rep('data-k="pin" inputmode="numeric" maxlength="6" value="${esc(u.pin||"")}" style="width:90px">',
    'data-k="pin" inputmode="numeric" maxlength="6" autocomplete="off" value="" placeholder="${u.has_pin ? "•••• (set)" : "Set PIN"}" style="width:110px">', 2)
rep('u.client_id === S.client && u.pin === "0000").length; return `<p class="small muted">Change a PIN by typing a new one (4 to 6 digits); it saves when you leave the box and works from the next sign-in. New people start on 0000.',
    'u.client_id === S.client && u.pin_default).length; return `<p class="small muted">PINs are stored encrypted and are never shown. To reset one, type a new PIN (4 to 6 digits); it saves when you leave the box and signs that person out of every device. New people start on 0000.')
rep('<td class="num">${esc(u.pin||"")}</td></tr>`).join("")}', '<td class="num">${u.has_pin ? (u.pin_default ? "default" : "set") : "none"}</td></tr>`).join("")}')
rep('Numbers save when you leave the field. PINs stay as they are for now; SMS one-time codes come later.</p></section>`;',
    'Numbers save when you leave the field.</p></section>`;')
# empty PIN box on leave = no change
rep('if(t.dataset.k === "pin" && !/^\\d{4,6}$/.test(v)){ toast("PIN must be 4 to 6 digits"); return; }',
    'if(t.dataset.k === "pin" && v === "") return; if(t.dataset.k === "pin" && !/^\\d{4,6}$/.test(v)){ toast("PIN must be 4 to 6 digits"); return; }')

# 4. One-time sign-in code: switch in the sign-in card, second step on the phone sign-in form.
rep('''      <button class="switch" data-act="phone-required" aria-pressed="${req}">${S.draft.confirmPhoneReq ? "Tap to confirm" : req ? "On" : "Off"}</button></div>''',
'''      <button class="switch" data-act="phone-required" aria-pressed="${req}">${S.draft.confirmPhoneReq ? "Tap to confirm" : req ? "On" : "Off"}</button></div>
    <div class="toggle-row"><div><b>Require a one-time code</b><div class="small muted">${otpRequired() ? "On: after the PIN, a 6-digit code is sent to the person's email (and WhatsApp once connected). It works for 5 minutes." : "Off: number and PIN are enough. Turn this on once everyone has an email address saved."}</div></div>
      <button class="switch" data-act="otp-required" aria-pressed="${otpRequired()}">${S.draft.confirmOtpReq ? "Tap to confirm" : otpRequired() ? "On" : "Off"}</button></div>
    ${S.draft.confirmOtpReq ? `<div class="notice err small">${people.filter(u => !/^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$/.test(u.email||"")).length} people have no email address and will not be able to sign in until WhatsApp codes are connected. Tap again to switch it on.</div>` : ""}''')
rep('<th>Mobile number</th><th>PIN</th></tr></thead>', '<th>Mobile number</th><th>Email (for sign-in codes)</th><th>PIN</th></tr></thead>')
rep('value="${esc(u.phone||"")}" style="width:140px;text-align:left"></td><td class="num">',
    'value="${esc(u.phone||"")}" style="width:140px;text-align:left"></td><td><input data-uu="${esc(u.id)}" data-k="email" type="email" inputmode="email" maxlength="120" placeholder="name@company.com" value="${esc(u.email||"")}" style="width:200px;text-align:left"></td><td class="num">')
rep('const phoneRequired = () => S.auth && S.auth.phone_required === true;',
    'const phoneRequired = () => S.auth && S.auth.phone_required === true; const otpRequired = () => S.auth && S.auth.otp_required === true;')
rep('''      <label>PIN<input id="ph-pin" type="password" inputmode="numeric" autocomplete="current-password" maxlength="6" placeholder="4 to 6 digits"></label>''',
'''      <label>PIN<input id="ph-pin" type="password" inputmode="numeric" autocomplete="current-password" maxlength="6" placeholder="4 to 6 digits"></label>
      ${S.draft.otpFor ? `<label>Sign-in code<input id="ph-otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6 digits" autofocus></label><div class="small muted">Sent to ${esc((S.draft.otpSentTo||[]).join(" and "))}. It works for 5 minutes.</div>` : ""}''')
rep('''    if(HOSTED){ try{ const j = await S.db._call("login_phone", {phone:ph, pin}); store.set("tarazu.token", j.token); await S.db._resync(); u = S.users.find(x => x.id === j.user_id) || null; }catch(err){ why = err.message || ""; } }''',
'''    if(HOSTED){ try{
        const otpEl = $("#ph-otp"), otp = otpEl ? otpEl.value.replace(/\\D/g,"") : "";
        const usePin = pin || (S.draft.otpFor === ph ? S.draft.otpPin : "");
        const j = await S.db._call("login_phone", otp ? {phone:ph, pin:usePin, otp} : {phone:ph, pin:usePin});
        if(j.otp_needed){ S.draft.otpFor = ph; S.draft.otpPin = usePin; S.draft.otpSentTo = j.sent_to || []; S.err.login = ""; render(); setTimeout(() => { const pe = $("#ph-pin"); if(pe) pe.value = usePin; const oe = $("#ph-otp"); if(oe) oe.focus(); }, 0); return; }
        S.draft.otpPin = null; store.set("tarazu.token", j.token); await S.db._resync(); u = S.users.find(x => x.id === j.user_id) || null; }catch(err){ why = err.message || ""; } }''')
# email field saves like phone
rep('''  if(t.dataset.uu && t.dataset.k === "phone"){''',
'''  if(t.dataset.uu && t.dataset.k === "email"){ const v = t.value.trim();
    if(v && !/^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$/.test(v)){ toast("Enter a valid email address"); return; }
    save("users/"+t.dataset.uu, {email: v}, true).then(() => toast(v ? "Email saved" : "Email removed")).catch(err => toast(err.message||"Could not save")); return; }
  if(t.dataset.uu && t.dataset.k === "phone"){''')
# otp switch handler, next to the phone switch
rep('''    if(want && !d.confirmPhoneReq){ d.confirmPhoneReq = true; render(); return; }''',
'''    if(want && !d.confirmPhoneReq){ d.confirmPhoneReq = true; render(); return; }''')

# 5. Signing out ends the session on the server too.
rep('''  if(a === "logout"){ store.set("tarazu.me", null); store.set("tarazu.token", null);''',
'''  if(a === "logout"){ serverSignOut(store.get("tarazu.token")); store.set("tarazu.me", null); store.set("tarazu.token", null);''')
rep('''  if(a === "master-signout"){ store.set("tarazu.mtoken", null);''',
'''  if(a === "master-signout"){ serverSignOut(store.get("tarazu.mtoken")); store.set("tarazu.mtoken", null);''')

# 6. Report PDFs open through a short-lived signed link.
rep('${r.pdf_url?`<a class="small" href="${esc(r.pdf_url)}" target="_blank" rel="noopener">PDF</a>`:""}',
    '${r.pdf_url?`<button type="button" class="ghost sm" data-act="open-pdf" data-url="${esc(r.pdf_url)}">PDF</button>`:""}')
rep('${r.pdf_url?`<a href="${esc(r.pdf_url)}" target="_blank" rel="noopener">PDF</a> · `:""}',
    '${r.pdf_url?`<button type="button" class="ghost sm" data-act="open-pdf" data-url="${esc(r.pdf_url)}">PDF</button> · `:""}')

# 7. Security card for master and owner: sign out other devices, recent security activity.
rep('''  ${signInCard()}
  ${notifyCard()}''', '''  ${signInCard()}
  ${securityCard()}
  ${notifyCard()}''')
rep('''    <h3>Holding cost</h3>''', '''    ${S.me && S.me.role === "owner" ? securityCard() : ""}
    <h3>Holding cost</h3>''')

helpers = r'''
/* ---------- security: server sign-out, report links, activity log ---------- */
function serverSignOut(tok){ if(!HOSTED || !tok) return; try{ fetch(HOSTED.api + "?op=logout", {method:"POST", headers:{"Content-Type":"application/json", "X-Token":tok}, body:"{}", keepalive:true}).catch(() => {}); }catch(e){} }
function absUrl(u){ const base = window.TARAZU_NATIVE ? window.TARAZU_NATIVE.apiBase : location.href; return new URL(u, base).href; }
function openOutside(url){ if(window.TARAZU_NATIVE && window.Capacitor && window.Capacitor.Plugins.Browser) return window.Capacitor.Plugins.Browser.open({url}); window.open(url, "_blank", "noopener"); }
async function openPdf(raw){
  if(!HOSTED){ openOutside(raw); return; }
  const w = window.TARAZU_NATIVE ? null : window.open("about:blank", "_blank");
  try{ const j = await S.db._call("file_link", {name: raw}); const url = absUrl(j.url); if(w){ w.location = url; } else openOutside(url); }
  catch(err){ if(w) w.close(); toast(err.message || "Could not open the report"); }
}
const AUDIT_LABEL = {login:"Signed in", login_fail:"Failed sign-in", otp_sent:"Sign-in code sent", logout:"Signed out", logout_all:"Signed out of all devices", impersonate:"Master admin opened account", user_change:"Person changed", revoke_sessions:"Devices signed out", delete:"Deleted", round_decided:"Round decided", config_change:"Settings changed", upload:"Report uploaded", ai_brief:"AI brief run", ai_report:"Report read by AI"};
function securityCard(){
  if(!HOSTED || !S.me) return "";
  const rows = S.auditRows || null, nm = id => (S.users.find(u => u.id === id) || {}).name || id || "Unknown";
  return `<section class="card"><h2>Security</h2>
    <div class="row" style="gap:8px;flex-wrap:wrap"><button class="ghost" data-act="sec-logout-all">Sign out my other devices</button><button class="ghost" data-act="sec-audit">${rows ? "Refresh activity" : "Show recent activity"}</button></div>
    <p class="small muted">Changing someone's PIN or role, or removing them, signs them out everywhere straight away.</p>
    ${rows ? `<div class="tablewrap"><table><thead><tr><th>When</th><th>Who</th><th>What</th><th>Detail</th></tr></thead><tbody>${rows.map(r => `<tr><td class="small">${esc(new Date(r.at*1000).toLocaleString("en-IN",{day:"numeric",month:"short",hour:"numeric",minute:"2-digit"}))}</td><td>${esc(r.user_id ? nm(r.user_id) : "–")}${r.via_master ? ` <span class="chip warn">via ${esc(nm(r.via_master))}</span>` : ""}</td><td>${esc(AUDIT_LABEL[r.action] || r.action)}</td><td class="small muted">${esc(r.action === "user_change" ? nm(r.target) + ": " + ((r.meta && r.meta.fields) || []).join(", ") : (r.target || ""))}</td></tr>`).join("") || `<tr><td colspan="4" class="muted">Nothing yet.</td></tr>`}</tbody></table></div>` : ""}
  </section>`;
}
async function securityClick(a, b, d){
  if(a === "open-pdf"){ await openPdf(b.dataset.url); return true; }
  if(a === "sec-logout-all"){ try{ const j = await S.db._call("logout_all", {}); toast(j.signed_out ? `Signed out ${j.signed_out} other device${j.signed_out === 1 ? "" : "s"}` : "No other devices were signed in"); }catch(err){ toast(err.message || "Could not sign out"); } return true; }
  if(a === "sec-audit"){ try{ const j = await S.db._call("audit_list", {limit:60}); S.auditRows = j.entries || []; render(); }catch(err){ toast(err.message || "Could not load activity"); } return true; }
  if(a === "otp-required"){ const want = !otpRequired();
    if(want && !d.confirmOtpReq){ d.confirmOtpReq = true; render(); return true; }
    d.confirmOtpReq = false; await save("config/auth", {otp_required:want, updated_at:new Date().toISOString(), updated_by:S.me.id}).then(() => toast(want ? "One-time codes are on" : "One-time codes are off")).catch(err => toast(err.message||"Could not save")); render(); return true; }
  return false;
}
'''
rep('async function v3Click(a, b, d){\n', helpers + 'async function v3Click(a, b, d){\n  if(await securityClick(a, b, d)) return true;\n')
p.write_text(s, encoding='utf-8')
print('applied')
