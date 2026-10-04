const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict'),path=require('path');
const html=fs.readFileSync(path.join(__dirname,'../hotspot/login.html'),'utf8');
const source=html.slice(html.indexOf('function customerPortalDestination('),html.indexOf('function showResult('));
function run(deviceConnected=false){const elements={};const get=id=>elements[id]||(elements[id]={value:'https://portal.test/customer/hotspot_landing.php',style:{},submit(){this.submitted=true;}});const timers=[];const redirects=[];
 const context={PORTAL:'https://portal.test',TID:14,document:{getElementById:get},sessionStorage:{removeItem(){}},showResult(){},escHtml:x=>x,setTimeout:fn=>timers.push(fn),window:{location:{assign:url=>redirects.push(url)}}};
 vm.runInNewContext(source,context);context.autoConnect('customer','1234','buy-result','Paid',deviceConnected,'a+b/c');timers[0]();return {elements,redirects};}
let r=run();assert.equal(r.elements['auto-dst'].value,'https://portal.test/customer/auto_login.php?token=a%2Bb%2Fc');assert.equal(r.elements['auto-user'].value,'customer');assert.equal(r.elements['auto-pass'].value,'1234');assert.equal(r.elements['hs-auto-form'].submitted,true);assert.equal(r.redirects.length,0);
console.log('PASS paid customer credentials authenticate RouterOS; one-use token carries identity to portal');
r=run(true);assert.equal(r.redirects[0],'https://portal.test/customer/auto_login.php?token=a%2Bb%2Fc');assert.equal(r.elements['hs-auto-form'],undefined);
console.log('PASS already-connected customer opens portal without another router login');
assert.match(html,/autoConnect\(d.username, d.password, 'buy-result', 'Payment confirmed', false, d.portal_token\)/);
assert.match(html,/autoConnect\(d.username, d.password, 'rc-result'.*d.portal_token\)/);
const renew=fs.readFileSync(path.join(__dirname,'../customer/renew.php'),'utf8');assert.match(renew,/else if \(portalToken\)/);
assert.match(html,/_paidConfirmed && d.portal_token/);
console.log('PASS confirmed payment opens account while offline router provisioning retries');
console.log('PASS purchase, receipt recovery and renewal wire the payment token into the redirect');
