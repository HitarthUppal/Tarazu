#!/usr/bin/env python3
"""Panel tab (14 Nov 2026): everything the advisors say outside rounds (the latest read, polls, comments, scorecard)
in one tab for owner and manager. Homes keep only what is new. Admin moves into the account sheet as
"Review and settings". Idempotent."""
import sys, pathlib
p = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else 'web/index.html')
s = p.read_text(encoding='utf-8')
MARK = '/* tarazu-panel v1 */'
if MARK in s:
    print('already applied'); sys.exit(0)
def rep(old, new, count=1):
    global s
    n = s.count(old)
    assert n == count, f'expected {count} of {old[:90]!r}, found {n}'
    s = s.replace(old, new)

JS = r'''
/* tarazu-panel v1: what the advisors say, in one place */
const seenKey = () => "tz_seen_panel_" + (S.me ? S.me.id : "");
function panelSeen(){ try{ return localStorage.getItem(seenKey()) || ""; }catch(e){ return ""; } }
function markPanelSeen(){ try{ localStorage.setItem(seenKey(), new Date().toISOString()); }catch(e){} }
function newComments(){ const seen = panelSeen(), cut = seen || new Date(Date.now() - 4 * 864e5).toISOString(); return S.comments.filter(c => String(c.created_at) > cut).sort(byNewest); }
function panelBadge(){ return newComments().length; }
function fromPanelCard(){
  const owner = S.me.role !== "manager", open = S.polls.filter(pollOpen).sort(byNewest), fresh = newComments().slice(0, 3);
  const per = readPeriod(), reads = readsFor(per.start), ps = panelists(), lean = leanOf(reads.map(o => ({stance:o.stance})));
  const readLine = owner && ps.length ? `<div class="fp-row"><span>Panel read · ${esc(periodLabel(per))}</span><span><b>${reads.length} of ${ps.length}</b> in${lean ? ` · ${lean === "split" ? "split" : stChip(lean)}` : ""}</span></div>` : "";
  if(!readLine && !open.length && !fresh.length) return "";
  return `<section class="card"><div class="row between"><h2>From the panel</h2><button class="ghost" data-act="go" data-route="panel">Open Panel</button></div>
    ${readLine}
    ${open.map(pl => `<button class="fp-row fp-btn" data-act="open-poll" data-id="${esc(pl.id)}"><span>Poll: ${esc(pl.question)}</span><span><b>${votesFor(pl.id).length} of ${ps.length}</b> answered ›</span></button>`).join("")}
    ${fresh.map(c => `<div class="fp-c"><b>${esc(userName(c.panelist_id))}</b> <span class="small muted">${esc(fmtDate(c.created_at))}</span><p class="small">${esc(commentText(c))}</p></div>`).join("")}
    ${newComments().length > 3 ? `<p class="small muted" style="margin:0">${newComments().length - 3} more new comment${newComments().length - 3 > 1 ? "s" : ""} in Panel.</p>` : ""}</section>`;
}
function panelView(){
  const owner = S.me.role !== "manager", since = new Date(Date.now() - 90 * 864e5).toISOString();
  const dec = S.rounds.filter(r => r.status === "decided" && String(r.created_at) >= since);
  return `<div class="layout"><div class="main">
    ${owner ? panelReadCard() : ""}
    ${pollsCardMgmt()}
    ${commentsFeed()}
    ${owner && dec.length ? scorecard(dec).replace("<h2>Advisor scorecard</h2>", "<h2>Advisor scorecard</h2><span class=\"small muted\">Last 90 days</span>") : ""}
  </div></div>`;
}
'''
rep('function v3AdvisorTop(){', JS + '\nfunction v3AdvisorTop(){')

# routing and tabs
rep('''  if(rt === "market") return marketPage();''', '''  if(rt === "market") return marketPage();
  if(rt === "panel" && S.me.role !== "panelist") return panelView();''')
rep('''  if(r === "manager"){''', '''  if(rt === "panel" && r !== "panelist") markPanelSeen();
  if(r === "manager"){''')
rep('setTabs(V3 ? [["home","Mills"],["rounds","Rounds"],["sales","Sales"],["market","Market"]]',
    'setTabs(V3 ? [["home","Mills"],["rounds","Rounds"],["panel","Panel", panelBadge()],["sales","Sales"],["market","Market"]]')
rep('setTabs(V3 ? [["home","Home"],["rounds","Rounds", awaiting],["sales","Sales"],["market","Market"],["admin","Admin"]]',
    'setTabs(V3 ? [["home","Home"],["rounds","Rounds", awaiting],["panel","Panel", panelBadge()],["sales","Sales"],["market","Market"]]')
rep('const TAB_ICON = {Home:"home", Mills:"mills", Today:"today",', 'const TAB_ICON = {Panel:"panel", Home:"home", Mills:"mills", Today:"today",')
rep("  plus:'<path d=\"M12 5v14M5 12h14\"/>',", "  panel:'<circle cx=\"9\" cy=\"8\" r=\"3\"/><path d=\"M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6\"/><circle cx=\"17\" cy=\"9\" r=\"2.5\"/><path d=\"M16 14.2c2.9.3 5 2.7 5 5.8\"/>',\n  plus:'<path d=\"M12 5v14M5 12h14\"/>',")
rep('const TITLES = {round:"Round",', 'const TITLES = {panel:"Panel", admin:"Review and settings", round:"Round",')

# homes keep only what is new
rep('''    ${panelReadCard()}
    ${pollsCardMgmt()}
    ${commentsFeed()}
    <section class="card"><div class="row between"><h2>Mill-wise position</h2>''', '''    ${fromPanelCard()}
    <section class="card"><div class="row between"><h2>Mill-wise position</h2>''')
rep('''    ${kpiStrip()}
    ${pollsCardMgmt()}
    ${commentsFeed()}
''', '''    ${fromPanelCard()}
    ${kpiStrip()}
''')

# Admin moves into the account sheet
rep('const secRoute = isMaster() ? "master" : (u.role === "owner" ? "admin" : null);', 'const secRoute = isMaster() ? "master" : (u.role === "owner" ? "admin" : null), secLabel = isMaster() ? "Security and activity" : "Review and settings";')
rep('<button class="row-btn" data-act="go" data-route="${secRoute}"><span>Security and activity</span><span>›</span></button>',
    '<button class="row-btn" data-act="go" data-route="${secRoute}"><span>${secLabel}</span><span>›</span></button>')
rep('return `<div class="row between"><h1>Admin</h1><label style="flex-direction:row;align-items:center;gap:8px">Month',
    'return `<div class="row between"><h1>Review and settings</h1><label style="flex-direction:row;align-items:center;gap:8px">Month')
rep('  ${scorecard(dec)}\n  <section class="card"><h2>Round log</h2>', '  <section class="card"><h2>Round log</h2>')
rep('`<div class="empty">No comments yet. Advisors can send one any time from their Today screen.</div>`',
    '`<div class="empty">No comments yet. Advisors can send one any time from their Home screen.</div>`')

CSS = '''
/* tarazu-panel v1 */
.fp-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--line);font-size:14px}
.fp-row > span:first-child{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.fp-row > span:last-child{flex:none;white-space:nowrap}
.fp-btn{width:100%;background:transparent;border:0;border-top:1px solid var(--line);border-radius:0;font:inherit;color:inherit;text-align:left;min-height:44px;cursor:pointer}
.fp-c{padding:10px 0;border-top:1px solid var(--line)}
.fp-c p{margin:4px 0 0}
'''
rep('</style>', CSS + '</style>')
p.write_text(s, encoding='utf-8'); print('applied')
