const fs=require('fs'),vm=require('vm'),assert=require('assert');
const source=fs.readFileSync('mikrotik.php','utf8');
for (const block of source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)) {
 const js=block[1].replace(/<\?php([\s\S]*?)\?>/g,(_,php)=>php.trim().startsWith('echo')?'0':'');
 new vm.Script(js);
}
const polling=source.slice(source.indexOf('function startPolling()'),source.indexOf('\nfunction toggleService'));
async function run(connected) {
 const status={textContent:'',innerHTML:''};let scheduled=0,advanced=0;
 const context={currentStep:2,provisioningTimer:null,wizardRouterId:null,Date,encodeURIComponent,
 document:{getElementById:id=>id==='mikrotikName'?{value:'RB951'}:id==='wizardModal'?{style:{display:'flex'}}:status},
 clearTimeout(){},setTimeout(){scheduled++;return 1;},updateWizard(){advanced++;},loadWizardBridges(){},
 fetch:async()=>({json:async()=>({connected,router:{id:20},message:'Awaiting API'})})};
 vm.createContext(context);vm.runInContext(polling+';startPolling();',context);
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal(context.wizardRouterId,20);
 assert.equal(advanced,connected?1:0);assert.equal(scheduled,connected?0:1);
 assert.equal(context.currentStep,connected?3:2);
}
(async()=>{await run(false);await run(true);console.log('PASS: page JavaScript parses; wizard waits for API, retains device ID and advances only on verified connection.');})().catch(e=>{console.error(e);process.exit(1);});
