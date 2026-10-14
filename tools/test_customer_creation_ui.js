const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname,'../clients.php'),'utf8');
const close = source.slice(source.indexOf('function closeFormModal()'),source.indexOf('function toggleActionsMenu()'));
const submit = source.slice(source.indexOf('function handleFormSubmit(e)'),source.indexOf('function showCredentialsResult(data)'));
async function check(data) {
    const button = {disabled:false,textContent:'Save Customer'};
    const modal = {style:{display:'flex'}};
    const events = [];
    const context = {
        document:{querySelector:()=>button,getElementById:()=>modal},
        FormData: class { get(key) { return ''; } },
        fetch: async()=>({json:async()=>data}), console,
        showToast:()=>{},showCredentialsResult:()=>events.push('credentials'),
        setTimeout:()=>events.push('reload'),location:{reload:()=>{}},
        closeModal:()=>{},DashboardSync:{saved:()=>{}},
    };
    vm.createContext(context);
    vm.runInContext(close + submit,context);
    context.handleFormSubmit({preventDefault(){},target:{}});
    assert.equal(button.disabled,true,'Save stays disabled while request is pending');
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(modal.style.display,'none','Saved customer closes the Add Customer form');
    assert.equal(button.disabled,false,'Save is re-enabled after completion');
    assert(events.includes(data.auto_gen_username ? 'credentials' : 'reload'));
}
(async()=>{
    await check({success:true,auto_gen_username:true,username:'new-user'});
    await check({success:true,mikrotik_synced:true});
    console.log('PASS: Both generated and supplied credentials close the saved form.');
    const start = source.indexOf("if (new URLSearchParams(window.location.search).get('open_modal') === '1')");
    const end = source.indexOf('\n}',start)+2;
    let opened=0, url='https://example.test/clients.php?open_modal=1&search=Jane';
    const ctx={URL,URLSearchParams,window:{location:{search:new URL(url).search,href:url}},openAddModal:()=>opened++,history:{replaceState:(_,__,next)=>url=next}};
    vm.runInNewContext(source.slice(start,end),ctx);
    assert.equal(opened,1);
    assert.equal(url,'/clients.php?search=Jane');
    console.log('PASS: One-time Add Customer link is consumed while preserving other filters.');
})().catch(error=>{ console.error(error); process.exitCode=1; });
