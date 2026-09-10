/* Exercise the actual portal polling function without a browser or payment. */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync(require('node:path').join(__dirname, '../hotspot/login.html'), 'utf8');
const source = html.slice(html.indexOf('function pollBuyStatus()'), html.indexOf('function cancelBuy()'));
function portal(response) {
    const ctx = {
        _buyReqId:'checkout', _buyClientId:1, _buyPollCount:100, _buyPollMax:75,
        _buyPolling:false, _paidConfirmed:false, _buyTimer:1, PORTAL:'https://test.invalid', TID:2,
        calls:[], document:{getElementById:()=>({textContent:'',style:{},scrollIntoView(){}}),querySelector:()=>({textContent:''})},
        tickCountdown(){}, setStep(){}, clearInterval(){}, encodeURIComponent,
        forgetCheckout(){ctx.calls.push('forgot');}, cancelBuy(){ctx.calls.push('buy-again');},
        setBuyNotice(){}, showResult(){}, autoConnect(u,p){ctx.calls.push(['connect',u,p]);},
        fetch: async()=>({json:async()=>response})
    };
    vm.createContext(ctx); vm.runInContext(source,ctx); return ctx;
}
(async()=>{
    let p=portal({status:'processing',message:'Connecting'});
    p.pollBuyStatus(); await new Promise(setImmediate);
    assert.equal(p._buyReqId,'checkout'); assert.equal(p._buyPolling,false); assert.deepEqual(p.calls,[]);
    console.log('PASS: slow provisioning retains the checkout beyond the former five-minute timeout');
    p=portal({status:'completed',username:'paid-user',password:'test-only'});
    p.pollBuyStatus(); await new Promise(setImmediate);
    assert.equal(p.calls[0][0],'connect'); assert.equal(p.calls[0][1],'paid-user');
    assert.equal(p.calls.includes('forgot'),false);
    console.log('PASS: verified payment hands credentials to RouterOS and retains recovery through handoff');
    p=portal({status:'completed',device_only:true});
    p.pollBuyStatus(); await new Promise(setImmediate);
    assert.equal(p.calls.includes('forgot'),true);
    assert.equal(p.calls.some(call=>Array.isArray(call)&&call[0]==='connect'),false);
    console.log('PASS: a TV payment never logs the paying phone into the TV subscription');
    p=portal({status:'failed'});p.pollBuyStatus();await new Promise(setImmediate);
    assert.deepEqual(p.calls,['forgot','buy-again']);
    console.log('PASS: an explicit failed payment unlocks a new purchase');
    p=portal({status:'pending'});let requests=0, release;
    p.fetch=()=>{requests++;return new Promise(resolve=>{release=()=>resolve({json:async()=>({status:'pending'})});});};
    p.pollBuyStatus();p.pollBuyStatus();assert.equal(requests,1);release();await new Promise(setImmediate);
    assert.equal(p._buyPolling,false);
    console.log('PASS: slow requests cannot overlap and repeatedly reprovision the customer');
})().catch(error=>{console.error(error);process.exitCode=1;});
