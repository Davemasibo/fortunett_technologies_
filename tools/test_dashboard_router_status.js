const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../dashboard.php'), 'utf8');
function section(from, to) { return source.slice(source.indexOf(from), source.indexOf(to, source.indexOf(from))); }
const elements = new Map();
const document = {getElementById(id) { if (!elements.has(id)) elements.set(id, {textContent:'',style:{}}); return elements.get(id); }, querySelector() { return null; }};
let requests = 0, respond;
const context = vm.createContext({document, console, fetch() { requests++; return new Promise(resolve => {respond = value => resolve({json:async()=>value});}); }});
vm.runInContext(section('function updateStatCards(s)', 'function updateRouterStatus(routers)') + section('function updateRouterStatus(routers)', 'function currentAnalyticsRange()') + section('let routerRefreshPending = false;', "document.addEventListener('DOMContentLoaded'"), context);
async function flush() { await new Promise(resolve=>setImmediate(resolve)); }
(async()=>{
 context.refreshRouterStatus();context.refreshRouterStatus();
 assert.equal(requests,1,'overlapping polls must not race');
 respond({success:true,router_status:[{id:19,online:true,pppoe_clients:0,hotspot_clients:0}],router_online:true,active_users:0,routers_online:1,routers_total:1});await flush();
 assert.match(document.getElementById('router-badge-19').innerHTML,/Online/);
 assert.match(document.getElementById('stat-active-label').textContent,/0 PPPoE/,'reachable router with no clients is still online');
 context.updateStatCards({router_online:false,routers_online:0,active_users:0});
 assert.equal(document.getElementById('stat-routers-online').textContent,'1','late stats cannot overwrite live health');
 assert.match(document.getElementById('stat-active-label').textContent,/0 PPPoE/);
 context.refreshRouterStatus();respond({success:true,router_status:[{id:19,online:false}],router_online:false,active_users:0,routers_online:0,routers_total:1});await flush();
 assert.match(document.getElementById('router-badge-19').innerHTML,/Offline/,'real offline result must still be displayed');
 context.refreshRouterStatus();respond({success:true,router_status:[{id:19,online:true}],router_online:true,active_users:1,routers_online:1,routers_total:1});await flush();
 assert.match(document.getElementById('router-badge-19').innerHTML,/Online/,'next successful poll recovers automatically');
 console.log('PASS: overlapping polls, stale stats, zero-client online state, genuine outage and automatic recovery');
})().catch(error=>{console.error(error);process.exitCode=1;});
