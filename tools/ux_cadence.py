#!/usr/bin/env python3
"""Cadence change (14 Nov 2026): advisors give a market read every N days (default 4) instead of daily;
Market mood grid replaced by a Latest panel read card; rounds stay open 24 hours. Idempotent."""
import sys, pathlib
p = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else 'web/index.html')
s = p.read_text(encoding='utf-8')
MARK = '/* tarazu-cadence v1 */'
if MARK in s:
    print('already applied'); sys.exit(0)
def rep(old, new, count=1):
    global s
    n = s.count(old)
    assert n == count, f'expected {count} of {old[:90]!r}, found {n}'
    s = s.replace(old, new)

# ---------- read period helpers ----------
HELPERS = r'''
/* tarazu-cadence v1: the panel read comes every N days */
const READ_ANCHOR_DEFAULT = "2026-11-14";
function readEvery(){ const n = Math.round(num((S.settings || {}).read_every)); return n >= 1 && n <= 14 ? n : 4; }
function readAnchor(){ const a = (S.settings || {}).read_anchor; return /^\d{4}-\d{2}-\d{2}$/.test(a || "") ? a : READ_ANCHOR_DEFAULT; }
function readPeriod(t){
  const n = readEvery(), A = Date.parse(readAnchor() + "T00:00:00Z"), D = Date.parse(cycleDay(t) + "T00:00:00Z"), step = n * 864e5;
  const st = A + Math.floor((D - A) / step) * step, f = x => new Date(x).toISOString().slice(0,10);
  return {start:f(st), end:f(st + (n - 1) * 864e5), next:f(st + step), prev:f(st - step), n};
}
function shortDay(d){ return new Date(d + "T12:00:00Z").toLocaleDateString("en-IN", {day:"numeric", month:"short", timeZone:"UTC"}); }
function periodLabel(p){ if(p.n === 1) return shortDay(p.start); const a = new Date(p.start + "T12:00:00Z"), b = new Date(p.end + "T12:00:00Z");
  return a.getUTCMonth() === b.getUTCMonth() ? `${a.getUTCDate()}–${shortDay(p.end)}` : `${shortDay(p.start)} – ${shortDay(p.end)}`; }
function readsFor(start){ return S.outlooks.filter(o => o.day === start); }
function myRead(start){ return S.outlooks.find(o => o.day === start && o.panelist_id === S.me.id); }

/* owner and manager: the latest read from each advisor */
function panelReadCard(){
  const p = readPeriod(), ps = panelists(); if(!ps.length) return "";
  const cur = readsFor(p.start), prev = readsFor(p.prev);
  const use = cur.length ? cur : prev, shown = cur.length ? p : {...p, start:p.prev, end:new Date(Date.parse(p.start + "T00:00:00Z") - 864e5).toISOString().slice(0,10)};
  const lean = leanOf(use.map(o => ({stance:o.stance}))), pLean = cur.length ? leanOf(prev.map(o => ({stance:o.stance}))) : null;
  const avg = use.length ? Math.round(use.reduce((a, o) => a + (has(o.sell_pct) ? num(o.sell_pct) : o.stance === "sell" ? 100 : o.stance === "hold" ? 0 : 50), 0) / use.length) : null;
  const rows = ps.map(u => { const o = use.find(x => x.panelist_id === u.id); const txt = o && ((o.voice && o.voice.english) || o.note);
    return `<div class="pr-row"><b>${esc(u.name)}</b>${o ? `<span class="row" style="gap:6px">${stChip(o.stance)}${o.stance === "some" && has(o.sell_pct) ? `<span class="small">${Math.round(num(o.sell_pct))}%</span>` : ""}${o.direction ? `<span class="small">${DIR_ARROW[o.direction]} ${DIR_LABEL[o.direction]}</span>` : ""}</span>${txt ? `<p class="small">${esc(txt)}</p>` : ""}` : `<span class="small muted">No read yet</span>`}</div>`; }).join("");
  return `<section class="card"><div class="row between"><h2>Latest panel read</h2><span class="small muted">${esc(periodLabel(shown))}</span></div>
    ${use.length ? `<p style="margin:0"><b>${use.length} of ${ps.length}</b> in${lean ? ` · leaning ${lean === "split" ? "<b>split</b>" : stChip(lean)}` : ""}${avg != null ? ` · would sell <b>${avg}%</b> on average` : ""}${pLean && pLean !== lean ? ` <span class="small muted">(last read: ${pLean === "split" ? "split" : STANCE_LABEL[pLean]})</span>` : ""}</p>` : `<p class="small muted" style="margin:0">No reads yet for ${esc(periodLabel(p))}. Advisors are asked on ${esc(shortDay(p.start))}.</p>`}
    ${use.length ? `<div class="pr-list">${rows}</div>` : ""}
    ${!cur.length && prev.length ? `<p class="small muted" style="margin:0">Showing the previous read. The new one is due from ${esc(shortDay(p.start))}.</p>` : `<p class="small muted" style="margin:0">Next read due ${esc(shortDay(p.next))}.</p>`}</section>`;
}
'''
rep('function moodByDay(){', HELPERS + '\nfunction moodByDay(){')

# ---------- owner home: grid out, read card in ----------
rep('    ${moodCardV3()}', '    ${panelReadCard()}')

# ---------- advisor badge counts the period read, independent of rounds ----------
rep('const needO = V2 && !S.rounds.some(isOpen) && !S.outlooks.find(o => o.day === cycleDay() && o.panelist_id === S.me.id) ? 1 : 0;',
    'const needO = V2 && panelists().length && !myRead(readPeriod().start) ? 1 : 0;')
rep('setTabs(V3 ? [["home","Today", pending + needO + needP],["market","Market"],["sales","Sales"]]',
    'setTabs(V3 ? [["home","Home", pending + needO + needP],["market","Market"],["sales","Sales"]]')

# ---------- advisor home ----------
rep('''  const needs = open.filter(r => !mineV(r.id)), day = cycleDay(), mineO = S.outlooks.find(o => o.day === day && o.panelist_id === S.me.id);
  let top;
  if(needs.length) top = needs.map(r =>''', '''  const needs = open.filter(r => !mineV(r.id)), per = readPeriod(), day = per.start, mineO = myRead(day);
  let top = "";
  if(needs.length) top = needs.map(r =>''')
rep('''<button data-act="open" data-id="${esc(open[0].id)}">Open the round</button></section>`;
  else top = outlookBlock(day, mineO);''', '''<button data-act="open" data-id="${esc(open[0].id)}">Open the round</button></section>`;
  const readBlock = outlookBlock(day, mineO);''')
rep('''  const myO = S.outlooks.filter(o => o.panelist_id === S.me.id && o.day !== day).sort((a,b) => String(b.day).localeCompare(String(a.day))).slice(0,5);
  return `<div class="layout"><div class="main"><h1>Today</h1>${V3 ? v3AdvisorTop() : ""}${top}${V3 ? advisorCommentCard() : ""}
    ${myO.length ? `<h2>Your recent outlooks</h2><div class="ledger">${myO.map(o => `<div><span>${esc(fmtDay(o.day))}</span><span class="v">${stChip(o.stance)} ${o.direction?DIR_ARROW[o.direction]:""}</span></div>`).join("")}</div>` : ""}''',
    '''  const readFirst = !mineO && !needs.length;
  return `<div class="layout"><div class="main">${V3 ? v3AdvisorTop() : ""}${readFirst ? readBlock + top : top + readBlock}${V3 ? advisorCommentCard() : ""}''')

# ---------- the read form ----------
rep('''  if(mine && !d.editOutlook) return `<section class="card done"><div class="eyebrow">Today's outlook · sent</div><h2>Thanks, the owner has your read</h2>''',
    '''  const per = readPeriod();
  if(mine && !d.editOutlook) return `<section class="card done"><div class="eyebrow">Your read for ${esc(periodLabel(per))} · sent</div><h2>Thanks, the owner has your read</h2><p class="small muted" style="margin:0">Next read due ${esc(shortDay(per.next))}. You can change this one until then.</p>''')
rep('''  return `<form id="outlook-form" class="card today"><div class="eyebrow">Today's outlook · ${esc(fmtDay(day))}</div>
    <h2>What's your read on the market today, and what should the mills do?</h2>''',
    '''  return `<form id="outlook-form" class="card today"><div class="eyebrow">Your read for ${esc(periodLabel(per))}</div>
    <h2>What's your read on the market for the next ${per.n === 1 ? "day" : per.n + " days"}, and what should the mills do?</h2>''')
rep('<button class="primary" type="submit">${mine?"Update my outlook":"Send my outlook"}</button>',
    '<button class="primary" type="submit">${mine?"Update my read":"Send my read"}</button>')
rep('const d = S.draft, day = d.forOutlook || cycleDay(); S.err.view = "";', 'const d = S.draft, day = d.forOutlook || readPeriod().start; S.err.view = "";')
rep('toast(mine ? "Outlook updated" : "Outlook sent to the owner");', 'toast(mine ? "Read updated" : "Read sent to the owner");')

# ---------- Admin: how often the read comes ----------
FORM = '''<form id="settings-form" class="grid2"><label>Interest on working capital (% a year)<input id="st-rate" inputmode="decimal" value="${st.rate}"></label><label>Storage and insurance (₹/qtl a month)<input id="st-store" inputmode="decimal" value="${st.storage}"></label><button type="submit">Save rates</button></form>'''
rep(FORM, FORM + '''
    <h3>Panel read</h3><form id="read-form" class="grid2"><label>Advisors give a read every (days)<input id="rd-every" type="number" min="1" max="14" value="${readEvery()}"></label><label>Counting from<input id="rd-anchor" type="date" value="${readAnchor()}"></label><button type="submit">Save</button></form>
    <p class="small muted">Current read: ${esc(periodLabel(readPeriod()))}. Advisors get a reminder at noon on its first day, and again two days later if they haven't answered.</p>''', count=2)
rep('  if(f.id === "settings-form"){', '''  if(f.id === "read-form"){ const n = Math.round(pnum($("#rd-every").value)), a = $("#rd-anchor").value;
    if(!(n >= 1 && n <= 14)){ toast("Choose between 1 and 14 days"); return; } if(!/^\\d{4}-\\d{2}-\\d{2}$/.test(a)){ toast("Choose a start date"); return; }
    await saveC("config/mill", {...S.settings, read_every:n, read_anchor:a}).then(() => toast(`Panel read every ${n} day${n > 1 ? "s" : ""}`)).catch(err => toast(err.message || "Could not save")); return; }
  if(f.id === "settings-form"){''')

# ---------- rounds: open for 24 hours ----------
rep('''        <span class="small muted" style="align-self:flex-end">Rounds close at 4 PM. One sent after 4 PM closes two hours later.</span></div>''',
    '''        <span class="small muted" style="align-self:flex-end">Open for 24 hours unless you change it.</span></div>''')

rep('function closeTimeV3(){ const now = Date.now(), d = new Date(now + IST_MS); const four = Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate(), 16, 0) - IST_MS; return new Date(now < four - 15*60e3 ? four : now + 2*3600e3); }',
    'function closeTimeV3(){ const t = new Date(Date.now() + 24*3600e3); t.setSeconds(0, 0); t.setMinutes(Math.ceil(t.getMinutes() / 15) * 15); return t; }')

CSS = '''
/* tarazu-cadence v1 */
.pr-list{display:flex;flex-direction:column}
.pr-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px 10px;align-items:center;padding:10px 0;border-top:1px solid var(--line)}
.pr-row p{grid-column:1 / -1;margin:0;color:var(--muted)}
'''
rep('</style>', CSS + '</style>')
p.write_text(s, encoding='utf-8'); print('applied')
