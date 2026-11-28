/* Tarazu sample client: Shri Gurudatt Sugars (one mill), served at /gurudatt/.
   Runs the normal app (index.html) on an in-browser store instead of api.php: no sign-in codes, no server writes,
   nothing shared with any real client. Changes stay in this browser and the sample refreshes itself after 6 hours.
   All figures are illustrative. */
(function(){
"use strict";
if(!window.TARAZU_DEMO) return;
var KEY = "tarazu.demo.gurudatt.v1", FRESH_MS = 6 * 3600e3, IST_MS = 19800e3;

function seed(){
  var now = Date.now(), H = 3600e3, D = 864e5;
  var iso = function(t){ return new Date(t).toISOString(); };
  var day = function(t){ return new Date(t + IST_MS - 3 * H).toISOString().slice(0, 10); };          /* same 3 AM cycle day as the app */
  var title = function(t){ return "Round of " + new Date(t).toLocaleDateString("en-IN", {day:"numeric", month:"short", timeZone:"Asia/Kolkata"}); };
  var C = "clients/gurudatt/", docs = {};
  var put = function(p, d){ docs[p] = d; };

  /* people (example names) */
  put("users/o1", {name:"Managing Director", role:"owner", client_id:"gurudatt", active:true});
  put("users/mg1", {name:"Sales Manager", role:"manager", client_id:"gurudatt", active:true});
  var ADV = [["p1","R. Kulkarni"],["p2","S. Patil"],["p3","A. Deshmukh"],["p4","M. Joshi"],["p5","V. Shinde"]];
  ADV.forEach(function(a){ put("users/" + a[0], {name:a[1], role:"panelist", client_id:"gurudatt", active:true}); });
  put("clients/gurudatt", {name:"Shri Gurudatt Sugars", single_mill:true});
  put("config/auth", {phone_required:false, otp_required:false});
  put("config/news", {});
  put("config/notify", {});
  put(C + "config/mill", {read_every:4, read_anchor:day(now - D), interest_rate_pct:10.5, storage_per_qtl_month:18, single_mill:true});

  /* the one mill: this fortnight's position */
  var mill = {unit_no:1, name:"Takaliwadi", code:"55301", location:"Takaliwadi, Shirol, Kolhapur", state:"MH", grade:"S",
              stock_wh:42000, quota:18500, sold_qty:6000, sold_avg_price:5152, wish_to_sell:0, updated_at:iso(now - 26 * H), updated_by:"mg1"};
  put(C + "mills/u1", mill);
  var snap = function(m, wish){ return [{id:"u1", unit_no:1, name:m.name, code:m.code, state:"MH", stock_wh:m.stock, quota:m.quota, sold_qty:m.sold,
                                         sold_avg_price:m.avg, balance_quota:m.quota - m.sold, wish_to_sell:wish}]; };

  /* sales feed: this month's lifts, and twelve months of history */
  [[14, 3000, 5085, "M/30"], [10, 2000, 5140, "M/30"], [6, 2500, 5160, "M/30"], [3, 1500, 5145, "M/30"], [44, 3000, 4610, "M/30"], [40, 4000, 4790, "M/30"], [36, 3500, 4920, "M/30"], [33, 3500, 5010, "M/30"]].forEach(function(s, i){
    var t = now - s[0] * D; put(C + "ledger/l" + (i + 1), {date:day(t), mill_id:"u1", mill_code:"55301", mill_name:"Takaliwadi", qty:s[1], rate:s[2], grade:s[3], source:"feed", created_at:iso(t)}); });
  var hist = [[1,14000,4840],[2,12000,4150],[3,11000,4060],[4,11000,3980],[5,10500,3925],[6,9000,3890],[7,8500,3860],[8,12500,3840],[9,15000,3810],[10,16500,3790],[11,14500,3760],[12,9500,3740]];
  hist.forEach(function(h){ var d = new Date(now); d.setUTCDate(1); d.setUTCMonth(d.getUTCMonth() - h[0]);
    var mk = d.toISOString().slice(0, 7); put(C + "sales/" + mk + "__u1", {month:mk, mill:"Takaliwadi", qty:h[1], avg_price:h[2]}); });

  /* rounds: two decided, the latest closed an hour ago and waiting for the owner */
  var view = function(rid, p, st, pct, rate, dir, note, at, extra){
    put(C + "rviews/" + rid + "__" + p, Object.assign({round_id:rid, panelist_id:p, stance:st, sell_pct:pct, target_rate:rate, direction:dir, rest:"hold", lots:2, interval_days:3,
      horizon_months:1, expected_move:null, overrides:{}, reasons:{}, conviction:"medium", note:note || "", voice:null, updated_at:iso(at),
      versions:[{sell_pct:pct, target_rate:rate, at:iso(at), kind:"first", change_reason:""}]}, extra || {})); };

  var tA = now - 13 * D;
  put(C + "rounds/rA", {title:title(tA), created_by:"mg1", created_at:iso(tA), valid_until:iso(tA + 24 * H), band_min:5060, band_max:5100, ask_rate:5080, floor_rate:5060, grade:"M/30",
    flags:[], cash_need:"moderate", note:"First lifting of the season. Buyers in Pune want 15-day credit; I am asking for cash against delivery.", status:"decided",
    mills:snap({name:"Takaliwadi", code:"55301", stock:30000, quota:9000, sold:0, avg:0}, 6000),
    decision:{mills:{u1:{qty:5000, rate:5085}}, total_qty:5000, avg_rate:5085, note:"Sell 5,000 now; keep 1,000 for the Pune buyer if he pays cash.", by:"o1", at:iso(tA + 27 * H)}});
  [["p1","some",70,5080,"flat"],["p2","sell",100,5070,"down"],["p3","hold",0,5120,"up"],["p4","sell",100,5060,"flat"],["p5","some",80,5090,"flat"]].forEach(function(v, i){ view("rA", v[0], v[1], v[2], v[3], v[4], "", tA + (2 + i) * H); });

  var tB = now - 8 * D;
  put(C + "rounds/rB", {title:title(tB), created_by:"mg1", created_at:iso(tB), valid_until:iso(tB + 24 * H), band_min:5130, band_max:5170, ask_rate:5150, floor_rate:5130, grade:"M/30",
    flags:[], cash_need:"moderate", note:"New fortnight quota is in. Kolhapur firm after the cane-price agitation held up crushing.", status:"decided",
    mills:snap({name:"Takaliwadi", code:"55301", stock:36000, quota:18500, sold:0, avg:0}, 7000),
    decision:{mills:{u1:{qty:4500, rate:5150}}, total_qty:4500, avg_rate:5150, note:"Sell 4,500 in two lots this week. Hold the rest for the next round.", by:"o1", at:iso(tB + 26 * H)}});
  [["p1","some",60,5150,"up"],["p2","sell",100,5140,"flat"],["p3","some",30,5190,"up"],["p4","sell",100,5135,"flat"],["p5","some",70,5160,"up"]].forEach(function(v, i){ view("rB", v[0], v[1], v[2], v[3], v[4], "", tB + (3 + i) * H); });

  var tC = now - 25 * H;
  put(C + "rounds/rC", {title:title(tC), created_by:"mg1", created_at:iso(tC), valid_until:iso(now - 1 * H), band_min:5170, band_max:5210, ask_rate:5190, floor_rate:5170, grade:"M/30",
    flags:["quota"], cash_need:"moderate",
    note:"12,500 qtl of quota left and it lapses at the end of this fortnight. Gujarat buyers asking ₹5,180 for prompt lifting. Crushing restarts Monday.", status:"open",
    mills:snap({name:"Takaliwadi", code:"55301", stock:42000, quota:18500, sold:6000, avg:5152}, 12500),
    brief:{lean:{side:"sell", reason:"Quota that is not sold by the fortnight's end lapses, Kolhapur spot is already at the top of AgriMandi's one-month band, and higher releases plus restarted crushing add supply."},
      summary:"Sell about 10,000 qtl now at ₹5,180 or better and offer the last 2,500 qtl before the fortnight ends, not below ₹5,165. Four of five advisors would sell at least 60% now; the one holder expects UP demand to lift Maharashtra prices, which the 2,500 held back still covers.",
      agreements:["Price band is fair: every advisor's rate sits within ₹5,165 to 5,210.","Unsold quota lapsing is the bigger risk than a small price rise."],
      disagreements:["A. Deshmukh expects ₹5,250 within two weeks as UP pulls Maharashtra sugar north; the others see flat to softer prices."],
      evidence:[{side:"sell", point:"Second-half November releases expected at 1.5 to 1.6 MMT, up from 1.3 MMT.", source_type:"agrimandi", source_title:"AgriMandi report, November 2026"},
        {side:"sell", point:"Kolhapur S/30 at ₹5,120 to 5,170 sits at the top of the one-month band of ₹4,800 to 5,200.", source_type:"agrimandi", source_title:"AgriMandi report, November 2026"},
        {side:"sell", point:"Prices fell from November to December in 11 of the last 13 seasons.", source_type:"isma", source_title:"ISMA base rates"},
        {side:"hold", point:"UP spot at record highs is drawing Maharashtra sugar north; national stocks-to-use near historic lows.", source_type:"chinimandi", source_title:"ChiniMandi spot prices"}],
      generated_at:iso(now - 50 * 60e3), responded:5, expected:5, articles_scanned:46}});
  view("rC", "p1", "some", 60, 5180, "down", "Sell 60% now, the rest before the fortnight ends. Spot is at the top of the band.", tC + 3 * H);
  view("rC", "p2", "sell", 100, 5170, "down", "Sell everything. Quota lapses and releases are rising.", tC + 5 * H);
  view("rC", "p3", "some", 40, 5210, "up", "UP is pulling Maharashtra sugar north. I would hold 60% for ₹5,250.", tC + 6 * H, {conviction:"high"});
  view("rC", "p4", "sell", 100, 5165, "flat", "Cane payments start with crushing. Take the cash.", tC + 9 * H);
  view("rC", "p5", "some", 70, 5190, "flat", "Gujarat and MP buyers are lifting at ₹5,180 to 5,190 today.", tC + 14 * H, {conviction:"high"});

  /* panel read every 4 days: last period complete, this one 3 of 5 */
  var cur = day(now - D), prev = day(now - 5 * D);
  [["p1","some",60,"down","Fresh crushing from Monday will weigh on Kolhapur."],["p2","sell",100,"down",""],["p3","hold",0,"up","UP spread is widening; Maharashtra follows."],["p4","sell",100,"flat",""],["p5","some",70,"flat","Buyers active, but only for prompt lifting."]]
    .forEach(function(o, i){ var t = now - (5 * D) + (3 + i) * H; put(C + "outlooks/" + prev + "__" + o[0], {day:prev, panelist_id:o[0], stance:o[1], sell_pct:o[2], direction:o[3], note:o[4], created_at:iso(t), updated_at:iso(t)}); });
  [["p2","sell",100,"down","Releases are going up. Sell into strength."],["p4","sell",100,"flat",""],["p5","some",70,"flat","Gujarat demand steady at ₹5,180."]]
    .forEach(function(o, i){ var t = now - D + (4 + i) * H; put(C + "outlooks/" + cur + "__" + o[0], {day:cur, panelist_id:o[0], stance:o[1], sell_pct:o[2], direction:o[3], note:o[4], created_at:iso(t), updated_at:iso(t)}); });

  /* a poll and two comments */
  put(C + "polls/pl1", {question:"Will Kolhapur M/30 trade below ₹5,100 by mid-December?", options:["Yes","No","Not sure"], note:"", created_by:"o1", created_at:iso(now - 20 * H), closes_at:iso(now + 28 * H), round_id:null});
  [["p2","Yes"],["p3","No"],["p5","Yes"]].forEach(function(v, i){ put(C + "pvotes/pl1__" + v[0], {poll_id:"pl1", panelist_id:v[0], choice:v[1], comment:"", at:iso(now - (15 - i) * H)}); });
  put(C + "comments/cm1", {panelist_id:"p5", text:"Two Gujarat buyers asked for 5,000 qtl each at ₹5,180, lifting within a week. Worth a quick answer.", voice:null, created_at:iso(now - 9 * H), round_id:null});
  put(C + "comments/cm2", {panelist_id:"p3", text:"Muzaffarnagar M/30 crossed ₹5,850. The north-south spread is the widest this season.", voice:null, created_at:iso(now - 4 * H), round_id:null});

  /* market: ChiniMandi spot (₹/qtl, ex-mill, excl. GST) and AgriMandi's November report */
  var band = function(lo, hi){ return {lo:lo, hi:hi, mid:Math.round((lo + hi) / 2)}; }, txt = function(lo, hi){ return lo === hi ? "₹" + lo : "₹" + lo + " to " + hi; };
  var ex = [["Maharashtra",5120,5170,5160,5210],["South Karnataka",5130,5180,5170,5220],["Uttar Pradesh",0,0,5580,5900],["Gujarat",5150,5190,5150,5190],["Tamil Nadu",5100,5250,5150,5250]]
    .map(function(r){ return {state:r[0], s30:r[1] ? band(r[1], r[2]) : null, m30:band(r[3], r[4]), s30_text:r[1] ? txt(r[1], r[2]) : "–", m30_text:txt(r[3], r[4])}; });
  var trend = []; for(var i = 29; i >= 0; i--){ var t = now - i * D, k = 29 - i;
    trend.push({date:day(t), maharashtra_s30:Math.round(4880 + k * 9 + Math.sin(k / 2) * 18), karnataka_s30:Math.round(4900 + k * 9.5 + Math.cos(k / 3) * 15)}); }
  put("config/market", {ok:true, fetched_at:iso(now - 40 * 60e3),
    spot:{date:day(now), url:"https://www.chinimandi.com/", exmill:ex,
      cities:[{city:"Kolhapur", grade:"M/30", rate:{mid:5290}},{city:"Pune", grade:"M/30", rate:{mid:5330}},{city:"Ahmedabad", grade:"M/30", rate:{mid:5410}},{city:"Muzaffarnagar", grade:"M/30", rate:{mid:5860}}]},
    trend:trend,
    news:[{title:"Second-half November sugar quota raised as government moves to cool prices", url:"https://www.chinimandi.com/", date:iso(now - 6 * H), lang:"en", categories:["Indian Sugar News"]},
      {title:"Kolhapur mills restart crushing after cane-price talks", url:"https://www.chinimandi.com/", date:iso(now - 20 * H), lang:"en", categories:["Indian Sugar News"]},
      {title:"Maharashtra sugar moves north as UP prices hit season high", url:"https://www.chinimandi.com/", date:iso(now - 30 * H), lang:"en", categories:["Indian Sugar News"]},
      {title:"कोल्हापूर विभागात गाळप पुन्हा सुरू", url:"https://www.chinimandi.com/", date:iso(now - 22 * H), lang:"mr", categories:["Indian Sugar News"]}],
    notifications:[{title:"Mill-wise sugar release order, second fortnight of November", url:"https://www.chinimandi.com/notifications/", date:day(now - 13 * D)}]});
  put("reports/nov2026", {title:"AgriMandi Sugar & Allied Intelligence Report, November 2026", month:"2026-11",
    headline:"Near term firm: tight supply, late crushing, drought in the south. Softer over three months as crushing catches up.",
    domestic_range:"Kolhapur S/30 ₹4,800 to 5,200 (1 month); ₹4,400 to 5,200 (3 months)",
    outlook:"Short-term support from tight supply, low carryover stocks and delayed crushing. Medium-term pressure as fresh supply arrives and quota releases rise; crop losses limit the fall."});

  return {seededAt:now, docs:docs};
}

function load(){
  try{ var s = JSON.parse(localStorage.getItem(KEY) || "null"); if(s && s.docs && Date.now() - s.seededAt < FRESH_MS) return s; }catch(e){}
  var f = seed(); persist(f); return f;
}
function persist(s){ try{ localStorage.setItem(KEY, JSON.stringify(s)); }catch(e){} }

var state = load(), subs = [];
var parent = function(p){ return p.split("/").slice(0, -1).join("/"); };
var snap = function(p){ return {id:p.split("/").pop(), exists:Object.prototype.hasOwnProperty.call(state.docs, p), data:function(){ return state.docs[p]; }}; };
var notify = function(){ persist(state); subs.slice().forEach(function(fn){ try{ fn(); }catch(e){} }); };
var clone = function(d){ return JSON.parse(JSON.stringify(d)); };
var listen = function(run){ subs.push(run); setTimeout(run, 0); return function(){ var i = subs.indexOf(run); if(i >= 0) subs.splice(i, 1); }; };

var db = {
  collection: function(path){ return { onSnapshot: function(next){ return listen(function(){ next({docs:Object.keys(state.docs).filter(function(k){ return parent(k) === path; }).map(snap)}); }); } }; },
  doc: function(path){ return {
    onSnapshot: function(next){ return listen(function(){ next(snap(path)); }); },
    set: function(d){ state.docs[path] = clone(d); notify(); return Promise.resolve(); },
    update: function(d){ state.docs[path] = Object.assign({}, state.docs[path] || {}, clone(d)); notify(); return Promise.resolve(); },
    delete: function(){ delete state.docs[path]; notify(); return Promise.resolve(); } }; }
};
window.TARAZU_DEMO_RESET = function(){ try{ localStorage.removeItem(KEY); Object.keys(localStorage).filter(function(k){ return k.indexOf("gd:") === 0; }).forEach(function(k){ localStorage.removeItem(k); }); }catch(e){} location.reload(); };
window.claude = { use: function(what){ return Promise.resolve(what === "db" ? db : null); } };
})();
