#!/usr/bin/env python3
"""Lean pass (14 Nov 2026): action first on every home, one Market tab instead of a market block on every screen,
manual sale entry removed (sales arrive from Nirani's software), sold figures read-only, month-to-date comparison,
tables with typing boxes become one card per mill on phones. Idempotent."""
import sys, pathlib, re
p = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else 'web/index.html')
s = p.read_text(encoding='utf-8')
MARK = '/* tarazu-lean v1 */'
if MARK in s:
    print('already applied'); sys.exit(0)
def rep(old, new, count=1):
    global s
    n = s.count(old)
    assert n == count, f'expected {count} of {old[:90]!r}, found {n}'
    s = s.replace(old, new)
def cut(start, end_marker):
    """remove from start up to (not including) end_marker"""
    global s
    i = s.index(start); j = s.index(end_marker, i)
    s = s[:i] + s[j:]

# ---------- 1. no market block on every screen; one Market tab ----------
rep('function rail(){ return `<aside class="rail">${V3 && S.me && S.me.role === "manager" ? saleBox() : ""}${spotCard(false)}${newsCard()}${notifCard(5)}${intelCards()}</aside>`; }',
    '/* tarazu-lean v1 */\nfunction rail(){ return ""; }')
s = re.sub(r'function railBelow\(\)\{ return `[^\n]*', 'function railBelow(){ return ""; }', s, count=1)
rep('''function v3Main(rt){
  if(rt === "sales") return salesView();''', '''function v3Main(rt){
  if(rt === "sales") return salesView();
  if(rt === "market") return marketPage();''')
rep('setTabs(V3 ? [["home","Mills"],["rounds","Rounds"],["sales","Sales"]]', 'setTabs(V3 ? [["home","Mills"],["rounds","Rounds"],["sales","Sales"],["market","Market"]]')
rep('setTabs(V3 ? [["home","Home"],["rounds","Rounds", awaiting],["sales","Sales"],["admin","Admin"]]', 'setTabs(V3 ? [["home","Home"],["rounds","Rounds", awaiting],["sales","Sales"],["market","Market"],["admin","Admin"]]')
rep('function marketPage(){ return `<h1>Market</h1><div class="list">', 'function marketPage(){ return `<div class="list">')

# ---------- 2. manual sale entry removed ----------
cut('function saleBox(){', 'function salesRows(){')
rep('  if(f.id === "sale-form"){ await submitSale(); return true; }\n', '')
rep('  if(a === "sale-del"){ await deleteSale(b.dataset.id); return true; }\n', '')
# sold figures come from the sales feed, so they are no longer typed in
rep('${editable?`<td class="r">${inp(m,"stock_wh")}</td><td class="r">${inp(m,"quota")}</td><td class="r">${inp(m,"sold_qty")}</td><td class="r">${inp(m,"sold_avg_price")}</td>`',
    '${editable?`<td class="r">${inp(m,"stock_wh")}</td><td class="r">${inp(m,"quota")}</td><td class="r">${q(m.sold_qty)}</td><td class="r">${num(m.sold_avg_price)?inr(m.sold_avg_price):"–"}</td>`')

# ---------- 3. month-to-date comparison ----------
rep('''function monthStats(mk){
  const rows = salesRows().filter(r => r.date.slice(0,7) === mk);''', '''function monthStats(mk, upto){
  const rows = salesRows().filter(r => r.date.slice(0,7) === mk && (!upto || num(r.date.slice(8,10)) <= upto));''')
rep('''  const cur = monthStats(mk), prev = monthStats(pk), t = totals(millsSorted());''',
    '''  const dom = num(new Date(Date.now() + IST_MS).toISOString().slice(8,10)), cur = monthStats(mk), prev = monthStats(pk, dom), t = totals(millsSorted());''')
rep('const pl = new Date(pk + "-15T00:00:00Z").toLocaleDateString("en-IN", {month:"short", timeZone:"UTC"});',
    'const pl = "1–" + dom + " " + new Date(pk + "-15T00:00:00Z").toLocaleDateString("en-IN", {month:"short", timeZone:"UTC"});')

# ---------- 4. owner home: what needs him first ----------
OWNER_NEW = r'''function decisionNeededCard(){
  const due = S.rounds.filter(r => r.status !== "decided" && !isOpen(r)); if(!due.length) return "";
  return `<section class="card needs"><div class="eyebrow">Decision needed</div>${due.map(r => `<div class="row between" style="flex-wrap:nowrap"><div><b>${esc(r.title || "Advisory round")}</b><div class="small muted">${viewsFor(r.id).length} of ${panelists().length} advisor views · ${qtl((r.mills || []).reduce((a, m) => a + num(m.wish_to_sell), 0))} to sell</div></div><button class="primary" data-act="open" data-id="${esc(r.id)}">Decide</button></div>`).join("")}</section>`;
}
function ownerHomeV3(){
  const due = new Set(S.rounds.filter(r => r.status !== "decided" && !isOpen(r)).map(r => r.id)), last = S.rounds[0];
  return `<div class="layout"><div class="main">
    ${decisionNeededCard()}
    ${kpiStrip()}
    ${last && !due.has(last.id) ? latestRoundCard() : ""}
    ${panelReadCard()}
    ${pollsCardMgmt()}
    ${commentsFeed()}
    <section class="card"><div class="row between"><h2>Mill-wise position</h2><span class="small muted">${millsSorted().length} units</span></div>${millsTable(false)}</section>
  </div></div>`;
}
'''
i = s.index('function ownerHomeV3(){'); j = s.index('function v3AdvisorTop(){', i)
s = s[:i] + OWNER_NEW + s[j:]

# ---------- 5. manager home: the round he has to post comes first ----------
rep('''    ${kpiStrip()}
    ${last && last.decision ? decisionCardMgr(last) : ""}''', '''    ${last && last.decision ? decisionCardMgr(last) : ""}
    ${last && isOpen(last) ? latestRoundCard() : ""}''')
i = s.index('    <section class="card" id="new-round-card">'); j = s.index('    <div class="row between"><h1>Step 1 · Mills</h1>', i)
s = s[:i] + s[j:]
k = s.index('function managerHomeV3(){'); h = s.index('    <div class="row between"><h1>Step 1 · Mills</h1>', k)
s = s[:h] + '    <div class="row between" id="new-round-card"><h2>New round · Step 1: how much to sell</h2>' + s[h + len('    <div class="row between"><h1>Step 1 · Mills</h1>'):]
i = s.index('function managerHomeV3(){'); j = s.index('  </div>${rail()}</div>`;', i)
s = s[:j] + '''    ${kpiStrip()}
    ${pollsCardMgmt()}
    ${commentsFeed()}
''' + s[j:]

# ---------- 6. tables with typing boxes: one card per mill on a phone ----------
rep('''    <div class="tablewrap"><table><thead><tr><th>Mill</th><th class="r">Wish to sell</th><th class="r">${V2 && d.decUnit === "pct" ? "Sell now (% of wish)" : "Sell now (qtl)"}</th><th class="r">Rate (₹/qtl)</th></tr></thead>
      <tbody>${ms.map(m => `<tr><td>Unit ${esc(m.unit_no)} · ${esc(m.name)}</td><td class="r">${q(m.wish_to_sell)}</td><td class="r">''',
    '''    <div class="tablewrap"><table class="stack"><thead><tr><th>Mill</th><th class="r">Wish to sell</th><th class="r">${V2 && d.decUnit === "pct" ? "Sell now (% of wish)" : "Sell now (qtl)"}</th><th class="r">Rate (₹/qtl)</th></tr></thead>
      <tbody>${ms.map(m => `<tr><td>Unit ${esc(m.unit_no)} · ${esc(m.name)}</td><td class="r" data-l="Wish to sell">${q(m.wish_to_sell)}</td><td class="r" data-l="${V2 && d.decUnit === "pct" ? "Sell now, % of wish" : "Sell now, qtl"}">''')
rep('''inputmode="numeric" value="${esc(d.rows[m.id].qty)}">`}</td><td class="r"><input data-dec''', '''inputmode="numeric" value="${esc(d.rows[m.id].qty)}">`}</td><td class="r" data-l="Rate, ₹/qtl"><input data-dec''')
rep('''<div class="tablewrap" style="margin-top:8px"><table><thead><tr><th>Mill</th><th class="r">Wish</th><th class="r">Sell %</th><th class="r">Rate</th></tr></thead><tbody>${ms.map(m => { const o = d.overrides[m.id] || {}; return `<tr><td>Unit ${esc(m.unit_no)} · ${esc(m.name)}</td><td class="r">${q(m.wish_to_sell)}</td><td class="r"><input data-ov''',
    '''<div class="tablewrap" style="margin-top:8px"><table class="stack"><thead><tr><th>Mill</th><th class="r">Wish</th><th class="r">Sell %</th><th class="r">Rate</th></tr></thead><tbody>${ms.map(m => { const o = d.overrides[m.id] || {}; return `<tr><td>Unit ${esc(m.unit_no)} · ${esc(m.name)}</td><td class="r" data-l="Wish, qtl">${q(m.wish_to_sell)}</td><td class="r" data-l="Sell %"><input data-ov''')
rep('''value="${has(o.sell_pct)?esc(o.sell_pct):""}"></td><td class="r"><input data-ov''', '''value="${has(o.sell_pct)?esc(o.sell_pct):""}"></td><td class="r" data-l="Rate, ₹/qtl"><input data-ov''')

# mill table on a phone: the manager gets one card per mill with the wish box up front; the owner a short summary table
MILLS_PHONE = r'''function millsPhone(editable){
  const ms = millsSorted(), t = totals(ms), pct = V2 && S.draft.wishUnit === "pct";
  if(!editable) return `<div class="tablewrap"><table class="compact"><thead><tr><th>Mill</th><th class="r">Bal. quota</th><th class="r">Sold avg</th></tr></thead>
    <tbody>${ms.map(m => `<tr><td class="mname">${esc(m.unit_no)} · ${esc(m.name)}</td><td class="r"><b>${q(balQuota(m))}</b></td><td class="r">${num(m.sold_avg_price) ? inr(m.sold_avg_price) : "–"}</td></tr>`).join("")}</tbody>
    <tfoot><tr><td>Group total</td><td class="r">${q(t.bq)}</td><td class="r">${t.avg ? inr(t.avg) : "–"}</td></tr></tfoot></table></div><p class="small muted">Quintals. Full table on a computer.</p>`;
  const inp = (m, k, l) => `<label class="mc-f">${l}<input data-mill="${esc(m.id)}" data-k="${k}" inputmode="numeric" value="${has(m[k]) ? esc(m[k]) : ""}"></label>`;
  return `<div class="row between small muted" style="padding:0 4px"><span>Mill · balance quota</span><span>Wish to sell${pct ? ", %" : ", qtl"}</span></div><div class="mcards">${ms.map(m => `<div class="mcard">
      <div class="mc-main"><b>${esc(m.unit_no)} · ${esc(m.name)}</b><span class="small muted">Bal. quota <b class="num">${q(balQuota(m))}</b> · sold <span class="num">${q(m.sold_qty)}</span></span></div>
      ${pct ? `<input class="mc-in" data-millpct="${esc(m.id)}" inputmode="decimal" aria-label="${esc(m.name)}: wish to sell, percent of balance quota" placeholder="0" value="${has(m.wish_to_sell) && balQuota(m) ? pctOf(m.wish_to_sell, balQuota(m)) : ""}">` : `<input class="mc-in" data-mill="${esc(m.id)}" data-k="wish_to_sell" inputmode="numeric" aria-label="${esc(m.name)}: wish to sell, quintals" placeholder="0" value="${num(m.wish_to_sell) ? esc(m.wish_to_sell) : ""}">`}
      ${num(m.wish_to_sell) > balQuota(m) ? `<span class="small mc-x" style="color:var(--danger)">More than the balance quota</span>` : ""}
      <details class="mc-x"><summary class="small">Stock and quota</summary><div class="grid2">${inp(m, "stock_wh", "Stock in WH")}${inp(m, "quota", "Quota")}</div></details>
    </div>`).join("")}</div>
    <p class="small"><b class="num">${qtl(t.wish)}</b> to sell across ${ms.filter(m => num(m.wish_to_sell)).length} mills. Sold figures come from the sales feed.</p>`;
}
'''
rep('function millsTable(editable){\n  const ms = millsSorted(), t = totals(ms);',
    MILLS_PHONE + 'function millsTable(editable){\n  if(document.body.classList.contains("phone")) return millsPhone(editable);\n  const ms = millsSorted(), t = totals(ms);')
rep('''</table></div><p class="small muted">Quantities in quintals. Balance quota = quota − sold; balance stock = stock − sold, both calculated.</p>`;''',
    '''</table></div><p class="small muted">Quintals. Balance = quota or stock minus sold. Sold figures come from the sales feed.</p>`;''')

# ---------- 7. trims ----------
rep('''  ${countLine(rows.length, "sale", rows.length ? "this period" : "")}
''', '')

CSS = r'''
/* tarazu-lean v1 */
.card.needs{border:2px solid var(--danger)}
.card.needs .eyebrow{color:var(--danger)}
.card.needs .row + .row{border-top:1px solid var(--line);padding-top:10px}
body.phone table.stack thead{display:none}
body.phone table.stack,body.phone table.stack tbody,body.phone table.stack tfoot{display:block;width:100%}
body.phone table.stack tr{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px 10px;padding:10px 0;border-top:1px solid var(--line)}
body.phone table.stack td{display:block;padding:0;border:0;text-align:left;min-width:0}
body.phone table.stack td:first-child{grid-column:1 / -1;font-weight:700}
body.phone table.stack td[data-l]::before{content:attr(data-l);display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:2px}
body.phone table.stack td input{width:100%;min-width:0}
body.phone table.stack tfoot tr{grid-template-columns:repeat(3,minmax(0,1fr))}
body.phone table.stack tfoot td:first-child{grid-column:auto}
.mcards{display:flex;flex-direction:column;gap:8px}
.mcard{border:1px solid var(--line);border-radius:14px;padding:10px 12px;display:grid;grid-template-columns:minmax(0,1fr) 112px;gap:6px 10px;align-items:center;background:var(--card)}
.mc-main{display:flex;flex-direction:column;gap:2px;min-width:0}
.mc-main > b{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mc-in{text-align:right;font-weight:700}
.mc-x{grid-column:1 / -1}
.mc-facts{display:flex;flex-wrap:wrap;gap:4px 14px;color:var(--muted)}
.mc-facts b{color:var(--ink)}
.mc-wish{font-weight:700}
.mc-wish input{margin-top:4px}
.mcard details summary{color:var(--muted);cursor:pointer}
.mcard details .grid2{margin-top:8px}
'''
rep('</style>', CSS + '</style>')
p.write_text(s, encoding='utf-8'); print('applied')
