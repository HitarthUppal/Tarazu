/* Sample client /gurudatt/ (one mill, in-browser data): role picker, single-mill screens, decide -> post -> answer flow. Local only.
   Run: tests/setup_local.sh, then NODE_PATH=$(npm root -g) node tests/gurudatt.js  (W=1366 H=900 for desktop) */
const { chromium } = require('playwright'); const fs=require('fs'); const OUT='/tmp/tz-gurudatt'; fs.mkdirSync(OUT,{recursive:true});
const W=+(process.env.W||390), H=+(process.env.H||844);
let fails=0; const ok=(n,c,x)=>{ console.log((c?'  ok   ':'  FAIL ')+n+(c?'':' '+(x||''))); if(!c) fails++; };
(async()=>{ const b=await chromium.launch({executablePath:'/usr/bin/google-chrome',args:['--no-sandbox']});
 const ctx=await b.newContext({viewport:{width:W,height:H}}); const pg=await ctx.newPage(); const errs=[], ext=[];
 pg.on('pageerror',e=>errs.push(String(e))); pg.on('requestfailed',r=>ext.push(r.url()));
 pg.on('request',r=>{ if(!r.url().startsWith('http://127.0.0.1')) ext.push('REQ '+r.url()); });
 const as = async uid => { await pg.click(`[data-act="demo-login"][data-id="${uid}"]`); await pg.waitForTimeout(600); };
 const out = async () => { await pg.click('[data-act="profile"]'); await pg.waitForTimeout(200); await pg.click('#sheet [data-act="logout"]'); await pg.waitForTimeout(400); };
 const main = () => pg.textContent('#main');
 await pg.goto('http://127.0.0.1:8097/gurudatt/'); await pg.waitForTimeout(1000);
 ok('login: role picker, no phone field', await pg.locator('[data-act="demo-login"]').count()===7 && await pg.locator('#ph-num').count()===0);
 // owner decides
 await as('o1'); let t = await main();
 ok('owner: decision needed', /Decision needed/i.test(t));
 ok('owner: no mill-wise table or group total', !/Mill-wise position|Group total|1 mills|1 units/.test(t));
 ok('owner: Maharashtra spot', /Maharashtra spot/.test(t));
 await pg.click('.needs [data-act="open"]'); await pg.waitForTimeout(500); t = await main();
 ok('round: no per-mill decision table', !/Apply % to all|Unit 1/.test(t));
 await pg.click('[data-act="dec-pct"][data-k="100"]'); await pg.waitForTimeout(200);
 ok('decide: sell all sets 12,500', await pg.inputValue('input[data-dec][data-k="qty"]') === '12500');
 ok('decide: nothing left unsold', /nothing left unsold/.test(await main()));
 await pg.fill('input[data-dec][data-k="qty"]','10000'); await pg.waitForTimeout(100);
 ok('decide: typing updates summary', /10,000/.test(await pg.textContent('#dec-tq')));
 await pg.click('#decision-form button[type=submit]'); await pg.waitForTimeout(200); await pg.click('#decision-form button[type=submit]'); await pg.waitForTimeout(500);
 t = await main(); ok('decide: recorded', /Owner's decision|Decided/i.test(t), t.slice(0,300));
 await pg.screenshot({path:`${OUT}/${W}-owner-decided.png`,fullPage:true});
 await out();
 // manager posts a new round
 await as('mg1'); t = await main();
 ok('manager: sees the decision', /10,000/.test(t));
 ok('manager: no unit toggle', await pg.locator('[data-act="wish-unit"]').count()===0);
 await pg.click('[data-act="wish-pct"][data-k="100"]'); await pg.waitForTimeout(400);
 ok('manager: All offers balance quota', /Offering 2,500/.test(await main()), (await main()).match(/Offering[^.]*/)?.[0]);
 await pg.fill('#rd-bmin','5160'); await pg.fill('#rd-bmax','5200');
 await pg.click('#round-form button[type=submit]'); await pg.waitForTimeout(600);
 t = await main(); ok('manager: round sent', /offered from Takaliwadi/.test(t));
 await pg.screenshot({path:`${OUT}/${W}-manager-round.png`,fullPage:true});
 await out();
 // advisor answers
 await as('p1'); t = await main(); ok('advisor: open round on home', /Round of/.test(t));
 const open = pg.locator('[data-act="open"]').first(); await open.click(); await pg.waitForTimeout(500);
 t = await main(); ok('advisor: no per-mill override', !/particular mill/.test(t));
 await pg.click('[data-act="stance"][data-k="sell"]'); await pg.waitForTimeout(200);
 await pg.screenshot({path:`${OUT}/${W}-advisor-form.png`,fullPage:true});
 await pg.click('#view-form button[type=submit], form button.primary[type=submit]'); await pg.waitForTimeout(500);
 t = await main(); ok('advisor: view sent', /Your view|Update my view|Last updated/.test(t));
 await out();
 // owner sees it, brief refresh is honest
 await as('o1'); await pg.click('nav.tabs button[data-route="rounds"]'); await pg.waitForTimeout(400);
 await pg.locator('[data-act="open"]').first().click(); await pg.waitForTimeout(400);
 const rb = pg.locator('[data-act="brief"]'); if(await rb.count()){ await rb.click(); await pg.waitForTimeout(400); ok('brief: sample message', /sample data/i.test(await main())); }
 // persistence across reload, and isolation from real sign-in keys
 await pg.reload(); await pg.waitForTimeout(900);
 ok('reload: still signed in as owner', await pg.evaluate(()=>document.body.classList.contains('authed')));
 ok('isolation: no real tarazu.me key', await pg.evaluate(()=>localStorage.getItem('tarazu.me')===null && localStorage.getItem('gd:tarazu.me')==='o1'));
 const ovf = await pg.evaluate(()=>document.documentElement.scrollWidth - innerWidth); ok('no sideways scroll', ovf<=1, ovf);
 console.log('page errors:', errs.join(' | ')||'none'); console.log('outside requests:', [...new Set(ext)].join('\n  ')||'none');
 console.log(fails ? fails+' FAILED' : 'ALL OK'); await b.close(); })();
