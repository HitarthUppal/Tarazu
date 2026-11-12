#!/usr/bin/env python3
"""Shell refinements: promote a screen's opening heading into the app bar; no duplicate floating action. Idempotent."""
import sys, pathlib
p = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else 'web/index.html')
s = p.read_text(encoding='utf-8')
MARK = '/* tarazu-shell v2 */'
if MARK in s:
    print('already applied'); sys.exit(0)
def rep(old, new):
    global s
    assert s.count(old) == 1, f'expected 1 of {old[:70]!r}, found {s.count(old)}'
    s = s.replace(old, new)

rep('function shellAfter(){', '''/* tarazu-shell v2 */
function screenHeading(){
  const m = $("#main"); if(!m) return null;
  const h = m.querySelector("h1"); if(!h) return null;
  const card = m.querySelector(".card");
  if(card && (card.compareDocumentPosition(h) & Node.DOCUMENT_POSITION_FOLLOWING)) return null;
  return h;
}
function shellAfter(){''')
rep('  const title = $("#ab-title"); if(title) title.textContent = S.me ? pageTitle() : "";\n  if(S.me){ document.title = pageTitle() + " · Tarazu"; }',
    '''  const h = S.me ? screenHeading() : null;
  if(h) h.classList.add("in-bar");
  const name = h ? h.textContent.trim() : (S.me ? pageTitle() : "");
  const title = $("#ab-title"); if(title) title.textContent = name;
  if(S.me){ document.title = name + " · Tarazu"; }''')
rep('    fab.hidden = !f;', '''    const dup = f && [...document.querySelectorAll(`#main [data-act="${f.act}"]`)].some(e => e.offsetParent && (!f.id || e.dataset.id === f.id));
    fab.hidden = !f || dup;''')
rep('/* tarazu-shell v1 */\n.ab-title{display:none}', '/* tarazu-shell v1 */\n.ab-title{display:none}\nbody.authed #main h1.in-bar{display:none}\nmain.wrap{width:100%;box-sizing:border-box}\nbody.authed #main .row.between:has(> h1.in-bar){justify-content:flex-end}')
p.write_text(s, encoding='utf-8'); print('applied')
