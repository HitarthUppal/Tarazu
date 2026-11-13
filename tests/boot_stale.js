/* A saved session the server rejects must fall back to the sign-in screen. Run: NODE_PATH=$(npm root -g) node tests/boot_stale.js */
const { chromium } = require('playwright');
(async()=>{ const b=await chromium.launch({executablePath:'/usr/bin/google-chrome',args:['--no-sandbox']}); let fail=0;
for (const c of [{t:'tz1_'+'a'.repeat(64),me:'m1'},{t:'tz1_'+'a'.repeat(64),me:'o1'},{t:null,me:'m1'}]) {
  const ctx=await b.newContext({viewport:{width:390,height:844}}); const p=await ctx.newPage(); const errs=[]; p.on('pageerror',e=>errs.push(String(e)));
  await p.addInitScript(c=>{ if(c.t) localStorage.setItem('tarazu.token',c.t); localStorage.setItem('tarazu.me',c.me); },c);
  await p.goto((process.env.TZ_BASE||'http://127.0.0.1:8097')+'/nirani/'); await p.waitForTimeout(2500);
  const good = await p.locator('#ph-num').count()===1 && errs.length===0;
  console.log(good?'  ok  ':'  FAIL', `stale session (${c.me}, token ${c.t?'rejected':'missing'}) shows sign-in`, errs); if(!good) fail++;
  await ctx.close(); }
await b.close(); console.log(`\n${3-fail} passed, ${fail} failed`); process.exit(fail?1:0); })();
