const fs = require('fs'), vm = require('vm'), assert = require('assert/strict');
const html = fs.readFileSync(require('path').join(__dirname,'../clients.php'),'utf8');
const source = html.slice(html.indexOf('function loadOnlineStatus()'),html.indexOf('function updateModalOnlineStatus('));
async function status(response, username='customer', failure=false) {
    const badge={};
    const cell={dataset:{online:'0'}};
    const row={getAttribute:()=>username,querySelector:selector=>selector==='.online-badge'?badge:cell};
    const context={
        currentCustomer:null,onlineStatusCache:{},
        document:{querySelectorAll:selector=>selector==='.online-badge'?[badge]:[row]},
        fetch:async()=>{if(failure)throw Error('Offline');return {json:async()=>response};},
        _lsRefreshCell:()=>{},Set,Date
    };
    vm.runInNewContext(source,context);
    context.loadOnlineStatus();await new Promise(setImmediate);
    return {badge,cell};
}
(async()=>{
    let result=await status({success:true,online:[],unavailable:{hotspot:[19]}});
    assert.equal(result.badge.textContent,'Status unavailable');
    console.log('PASS: Router read failure is not reported as offline');
    result=await status({success:true,online:['customer'],unavailable:{pppoe:[19]}});
    assert.match(result.badge.innerHTML,/Online/);assert.equal(result.cell.dataset.online,'1');
    console.log('PASS: Verified hotspot session stays online despite PPPoE check failure');
    result=await status({success:true,online:[],unavailable:{}});
    assert.match(result.badge.innerHTML,/Offline/);
    console.log('PASS: Successful empty session checks report offline');
    result=await status({success:true,online:[],unavailable:{}},'');
    assert.equal(result.badge.textContent,'Not provisioned');
    console.log('PASS: Missing router credentials have an explicit label');
    result=await status(null,'customer',true);
    assert.equal(result.badge.textContent,'Status unavailable');
    console.log('PASS: Network errors have visible feedback');
})().catch(error=>{console.error(error);process.exitCode=1;});
