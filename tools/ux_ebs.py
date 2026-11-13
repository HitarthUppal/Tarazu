#!/usr/bin/env python3
"""Ideas taken from eBuySugar: headline figures with month-on-month change, record counts,
one compact period filter, empty states that say what to do next, sign-in digit counter. Idempotent."""
import sys, pathlib
p = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else 'web/index.html')
s = p.read_text(encoding='utf-8')
MARK = '/* tarazu-ebs v1 */'
if MARK in s:
    print('already applied'); sys.exit(0)
def rep(old, new, count=1):
    global s
    n = s.count(old)
    assert n == count, f'expected {count} of {old[:80]!r}, found {n}'
    s = s.replace(old, new)

# ---------- 1. headline figures ----------
JS = r'''
/* tarazu-ebs v1 */
function monthStats(mk){
  const rows = salesRows().filter(r => r.date.slice(0,7) === mk);
  const qty = rows.reduce((a,r) => a + r.qty, 0), val = rows.reduce((a,r) => a + r.qty * r.rate, 0);
  return {qty, avg: qty ? val / qty : 0, n: rows.length};
}
function prevMonth(mk){ const [y,m] = mk.split("-").map(Number); return m === 1 ? `${y-1}-12` : `${y}-${String(m-1).padStart(2,"0")}`; }
function deltaChip(now, before, label){
  if(!now || !before) return `<span class="kd muted">&nbsp;</span>`;
  const pct = (now - before) / before * 100, r = Math.round(pct);
  if(Math.abs(pct) < 0.5) return `<span class="kd flat">Same as ${esc(label)}</span>`;
  return `<span class="kd ${pct > 0 ? "up" : "down"}">${pct > 0 ? "▲" : "▼"} ${Math.abs(r)}% vs ${esc(label)}</span>`;
}
function kpiStrip(){
  const mk = new Date(Date.now() + IST_MS).toISOString().slice(0,7), pk = prevMonth(mk);
  const cur = monthStats(mk), prev = monthStats(pk), t = totals(millsSorted());
  const pl = new Date(pk + "-15T00:00:00Z").toLocaleDateString("en-IN", {month:"short", timeZone:"UTC"});
  const spot = refSpot();
  return `<section class="kpis" aria-label="This month at a glance">
    <div class="kpis-h"><span>${esc(monthLabel(mk))}</span><span class="muted">${esc(clientName(S.client))}</span></div>
    <div class="kpi-grid">
      <div class="kpi"><div class="kl">Sold this month</div><div class="kn num">${cur.qty ? q(cur.qty) : "0"}<small> qtl</small></div>${deltaChip(cur.qty, prev.qty, pl)}</div>
      <div class="kpi"><div class="kl">Average price</div><div class="kn num">${cur.avg ? inr(cur.avg) : "–"}</div>${deltaChip(cur.avg, prev.avg, pl)}</div>
      <div class="kpi"><div class="kl">Balance quota</div><div class="kn num">${q(t.bq)}<small> qtl</small></div><span class="kd muted">${millsSorted().length} mills</span></div>
      <div class="kpi"><div class="kl">S. Karnataka spot</div><div class="kn num">${spot ? inr(spot) : "–"}</div><span class="kd muted">M/30, ₹/qtl</span></div>
    </div></section>`;
}
function countLine(n, word, extra){ return `<p class="count">${n} ${word}${n === 1 ? "" : "s"}${extra ? " · " + extra : ""}</p>`; }
'''
rep('function ownerHomeV3(){', JS + '\nfunction ownerHomeV3(){')
rep('''  return `<div class="layout"><div class="main">
    ${latestRoundCard()}
    ${pollsCardMgmt()}
    ${commentsFeed()}
    ${moodCardV3()}
    <div class="row between"><h1>Group position</h1><span class="small muted">${esc(clientName(S.client))} · ${esc(monthLabel(mk))}</span></div>
    <div class="stat"><div><div class="n num">${q(t.bq)}</div><div class="l">Balance quota, qtl</div></div><div><div class="n num">${q(t.sold)}</div><div class="l">Sold this month, qtl</div></div>
      <div><div class="n num">${t.avg ? inr(t.avg) : "–"}</div><div class="l">Average sold price</div></div><div><div class="n num">${refSpot() ? inr(refSpot()) : "–"}</div><div class="l">S. Karnataka M/30 spot</div></div></div>''',
    '''  return `<div class="layout"><div class="main">
    ${kpiStrip()}
    ${latestRoundCard()}
    ${pollsCardMgmt()}
    ${commentsFeed()}
    ${moodCardV3()}''')
rep('''<h2>Advisory rounds awaiting you</h2><button class="ghost" data-act="go" data-route="rounds">All rounds</button></div>
      ${open.length ?''', '''<h2>Advisory rounds awaiting you${open.length ? ` <span class="chip">${open.length}</span>` : ""}</h2><button class="ghost" data-act="go" data-route="rounds">All rounds</button></div>
      ${open.length ?''')
rep('''`<div class="empty">No open rounds. The mill manager starts one from the Mills screen.</div>`}</section>
    <section class="card"><div class="row between"><h2>Sales this month</h2>''',
    '''`<div class="empty">Nothing waiting for you. When the mill manager posts a round, it appears here and you get a notification.</div>`}</section>
    <section class="card"><div class="row between"><h2>Sales this month</h2>''')
rep('''  return `<div class="layout"><div class="main">
    ${last && last.decision ? decisionCardMgr(last) : ""}''', '''  return `<div class="layout"><div class="main">
    ${kpiStrip()}
    ${last && last.decision ? decisionCardMgr(last) : ""}''')

# ---------- 2 + 4. rounds list: count and next-step empty state ----------
rep('''    ${rs.length?`<div class="${V2?"rgrid":"list"}">${rs.map(roundItem).join("")}</div>`:`<div class="empty">No rounds yet.</div>`}`;''',
    '''    ${rs.length ? countLine(rs.length, "round", `${rs.filter(isOpen).length} open`) : ""}
    ${rs.length?`<div class="${V2?"rgrid":"list"}">${rs.map(roundItem).join("")}</div>`:`<div class="empty">${S.me.role === "manager" ? "No rounds yet. Tap <b>Post a new round</b> to send your first one to the panel." : S.me.role === "panelist" ? "No rounds yet. You get a notification when the mill manager asks for your view." : "No rounds yet. The mill manager posts them from the Mills screen, and you get a notification for each one."}</div>`}`;''')
rep('''if(!r) return `<div class="empty">Round not found.</div>`;''',
    '''if(!r) return `<div class="empty">This round is no longer available. It may have been deleted.<div style="margin-top:12px"><button data-act="go" data-route="rounds">Back to rounds</button></div></div>`;''')

# ---------- 3. one compact period filter ----------
rep('''  <section class="card"><div class="seg">${SPERIODS.map(([k,l]) => `<button type="button" data-act="sp" data-k="${k}" aria-pressed="${f.P === k}">${l}</button>`).join("")}</div>
    <div class="row">${f.P === "custom"''',
    '''  ${countLine(rows.length, "sale", rows.length ? "this period" : "")}
  <section class="card"><div class="row filt">
      <label>Period<select id="s-period">${SPERIODS.map(([k,l]) => `<option value="${k}" ${f.P === k ? "selected" : ""}>${({"1m":"Last month","3m":"Last 3 months","6m":"Last 6 months","1y":"Last 12 months","3y":"Last 3 years","all":"All time","custom":"Custom dates"})[k] || l}</option>`).join("")}</select></label>
    ${f.P === "custom"''')
rep('''<label style="min-width:220px">Mill<select id="s-mill">''', '''<label>Mill<select id="s-mill">''')
rep('''  if(t.id === "s-mill"){ d.smill = t.value; render(); return true; }''',
    '''  if(t.id === "s-mill"){ d.smill = t.value; render(); return true; }
  if(t.id === "s-period"){ d.sp = t.value; render(); return true; }''')
rep('''  : `<div class="empty">No sales in this period. The manager records sales from the Mills screen, or the owner imports the sales CSV in Admin.</div>`}`;''',
    '''  : `<div class="empty">No sales in this period.${f.P !== "all" ? ` <div style="margin-top:12px"><button data-act="sp" data-k="all">Show all time</button></div>` : ""}<div class="small" style="margin-top:10px">The mill manager records sales from the Mills screen, or the owner imports the sales CSV in Admin.</div></div>`}`;''')

# ---------- 6. sign-in: WhatsApp wording and digit counter ----------
rep('''<label>Mobile number<div class="phone-in"><span>+91</span><input id="ph-num" inputmode="tel" autocomplete="tel-national" maxlength="16" placeholder="98765 43210" value="${esc(S.draft.ph||"")}"></div></label>''',
    '''<label>${pinStep ? "Mobile number" : "WhatsApp number"}<div class="phone-in"><span>+91</span><input id="ph-num" inputmode="tel" autocomplete="tel-national" maxlength="16" placeholder="98765 43210" value="${esc(S.draft.ph||"")}"></div></label>
        <div class="ph-count" id="ph-count" aria-live="polite">${phCount(S.draft.ph || "")}</div>''')
rep('function phoneLoginView(){ /* tarazu-login v2 */', '''function phCount(v){
  const n = String(v).replace(/\\D/g, "").replace(/^(91|0)(?=\\d{10}$)/, "").length, k = Math.min(n, 10);
  return `<span class="dots" aria-hidden="true">${Array.from({length:10}, (_, i) => `<i class="${i < k ? "on" : ""}"></i>`).join("")}</span><span class="${n === 10 ? "ok" : n > 10 ? "bad" : ""}">${n === 10 ? "✓ 10/10" : n + "/10"}</span>`;
}
document.addEventListener("input", e => { if(e.target && e.target.id === "ph-num"){ const c = $("#ph-count"); if(c) c.innerHTML = phCount(e.target.value); } });
function phoneLoginView(){ /* tarazu-login v2 */''')

# ---------- CSS ----------
CSS = r'''
/* tarazu-ebs v1 */
.kpis{display:flex;flex-direction:column;gap:8px}
.kpis-h{display:flex;justify-content:space-between;align-items:baseline;font-weight:700;font-size:15px}
.kpis-h .muted{font-weight:500;font-size:13px}
.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
body.phone .kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
.kpi{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px;display:flex;flex-direction:column;gap:4px;min-width:0}
.kpi:first-child{border-color:var(--cane);box-shadow:inset 0 0 0 1px var(--cane)}
.kl{font-size:12px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--muted)}
.kn{font:800 24px/1.1 var(--body);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kn small{font-size:13px;font-weight:600;color:var(--muted)}
.kd{font-size:12px;font-weight:700}
.kd.up{color:var(--cane)}.kd.down{color:var(--danger)}.kd.flat{color:var(--muted)}.kd.muted{font-weight:500;color:var(--muted)}
.count{margin:-6px 0 0;font-size:13px;color:var(--muted);font-weight:600}
.row.filt{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end}
.ph-count{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:var(--muted);margin-top:-4px}
.ph-count .dots{display:flex;gap:5px}
.ph-count .dots i{width:7px;height:7px;border-radius:50%;background:var(--line)}
.ph-count .dots i.on{background:var(--cane)}
.ph-count .ok{color:var(--cane);font-weight:700}.ph-count .bad{color:var(--danger);font-weight:700}
.empty button{min-height:40px}
'''
rep('</style>', CSS + '</style>')
p.write_text(s, encoding='utf-8'); print('applied')
