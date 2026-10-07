// Browser regression checks for the rendered PHP form and real modal behavior.
// Requires Playwright on NODE_PATH and a local Chrome/Edge installation.
const fs=require('fs'),path=require('path'),os=require('os'),assert=require('assert');
const {execFileSync}=require('child_process');
const {chromium}=require('playwright');
const source=fs.readFileSync('mikrotik.php','utf8');
const form=execFileSync('php',['-n','-r',"require 'includes/router_wan_form.php'; renderRouterWanForm('editWan');"],{encoding:'utf8'});
const modal=source.slice(source.indexOf('<div id="wanSetupModal"'),source.indexOf('<script src="assets/router-wan.js')).replace(/<\?php renderRouterWanForm\('editWan'\); \?>/,form);
const backdrop='<main style="padding:45px;max-width:1200px;margin:auto"><p style="color:#93b9e1;font-size:12px;letter-spacing:2px">HOMELINK FIBER</p><h1>Your devices</h1><p style="color:#a3a3a0">Manage connections and customer services.</p><button id="opener">Internet setup</button><div style="display:flex;gap:20px;margin-top:30px"><article style="width:300px;padding:25px;background:#222221;border-radius:12px"><h3>Main router</h3><p>Site connection</p></article><article style="width:300px;padding:25px;background:#222221;border-radius:12px"><h3>Customer access</h3><p>Network services</p></article></div></main>';
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.PORTAL_BROWSER || 'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
 try {
  const page=await browser.newPage({viewport:{width:1280,height:1000}});const errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.setContent('<html><head><meta name="wan-csrf" content="test-csrf"></head><body style="margin:0;background:#141414;color:#e2e2e0;font-family:Arial,sans-serif">'+backdrop+'<input type="hidden" id="wanProvisionEndpoint" value="https://example.test/api/routers/provision.php?token=test">'+modal+'</body></html>');
  await page.addStyleTag({content:fs.readFileSync('assets/router-wan.css','utf8')});
  await page.evaluate(()=>{
   window.wanTest={postCalls:0,managementOk:true,storageOk:true,offline:false,allOk:false};
   window.fetch=async(url,options={})=>{
    url=String(url);
    if(url.includes('bridges.php'))return {ok:true,json:async()=>({bridges:['bridge-lan']})};
    if(url.includes('provision.php'))return {ok:wanTest.managementOk,text:async()=>wanTest.managementOk?'# management setup':'Unavailable'};
    if(options.method==='POST') {
     wanTest.postCalls++;wanTest.payload=Object.fromEntries(options.body.entries());
     if(options.body.get('action')==='verify') {
      if(wanTest.offline)return {ok:false,json:async()=>({message:'Cannot connect to router'})};
      return {ok:true,json:async()=>({all_ok:wanTest.allOk,lan_bridge:'verified-bridge',router_name:'HomeLink',checks:[true,true,true,wanTest.allOk].map((ok,index)=>({ok,label:'Check '+index,detail:'test'}))})};
     }
     return {ok:true,json:async()=>({status:'success',script:'# wan setup',identity:'HomeLink',config:{lan:'bridge-lan'}})};
    }
    if(!wanTest.storageOk)return {ok:false,json:async()=>({code:'storage_unavailable',message:'WAN storage unavailable. Apply the router WAN database migration.'})};
    return {ok:true,json:async()=>({status:'success',router_name:'HomeLink · Main router',config:null})};
   };
  });
  await page.addScriptTag({content:fs.readFileSync('assets/router-wan.js','utf8')});
  const open=async()=>{await page.locator('#opener').focus();await page.evaluate(()=>openWanSetup(1));};
  await open();
  assert(await page.getByRole('dialog').isVisible());
  assert.strictEqual(await page.locator('input[type="radio"][value="dhcp"]').isChecked(),true);
  assert.strictEqual(await page.locator('#editWanAdvanced').evaluate(node=>node.open),false);
  assert.strictEqual(await page.locator('#wanSetupModal').evaluate(node=>getComputedStyle(node).backdropFilter),'blur(9px)');
  await page.locator('#editWanPrepare').click();
  assert.strictEqual(await page.evaluate(()=>wanTest.postCalls),0,'Blank customer bridge must stop before an API mutation');
  assert(await page.locator('#editWanLan').getAttribute('aria-invalid'));
  await page.locator('input[type="radio"][value="static"]').check();
  assert(await page.locator('#editWanStatic').isVisible());assert(await page.locator('#editWanDns').isVisible());
  await page.locator('input[type="radio"][value="pppoe"]').check();
  assert(await page.locator('#editWanPppoe').isVisible());assert(!(await page.locator('#editWanStatic').isVisible()));
  await page.locator('#editWanUsername').fill('isp-user');await page.locator('#editWanPassword').fill('test-private');await page.locator('#editWanLan').fill('bridge-lan');
  await page.locator('#editWanPrepare').click();
  await page.getByRole('button',{name:'Download setup file',exact:true}).waitFor();
  assert.strictEqual(await page.locator('#editWanPassword').inputValue(),'');
  assert.strictEqual(await page.evaluate(()=>wanTest.payload.wan_password),'test-private');
  assert.strictEqual(await page.evaluate(()=>wanTest.payload.csrf),'test-csrf');
  assert.strictEqual(await page.locator('.wan-script').evaluate(node=>node.open),false,'Script source stays collapsed');
  assert(await page.locator('#editWanOutput').innerText().then(text=>text.includes('/import fortunett-setup.rsc')));
  await page.locator('#editWanVerify').click();
  assert.strictEqual(await page.locator('.wan-check-list li').count(),4);
  assert.strictEqual(await page.locator('.wan-check-failed').count(),1,'Failed billing access must be visible');
  assert(await page.getByRole('button',{name:'Download setup file',exact:true}).isVisible(),'Checking connectivity preserves installer instructions');
  await page.evaluate(()=>wanTest.allOk=true);await page.locator('#editWanVerify').click();
  assert(await page.locator('#editWanCheckOutput').innerText().then(text=>text.includes('Your device is connected')));
  await page.evaluate(()=>{
   const bridge=document.createElement('input');bridge.id='provBridgeName';document.body.append(bridge);
   window.openProvision=(id,name)=>wanTest.next={id,name};
  });
  await page.locator('#editWanVerify').click();await page.getByRole('button',{name:'Configure customer services',exact:true}).click();
  assert.strictEqual(await page.locator('#provBridgeName').inputValue(),'verified-bridge','Next step carries the server-verified bridge');
  assert.deepStrictEqual(await page.evaluate(()=>wanTest.next),{id:1,name:'HomeLink'});
  await open();
  await page.evaluate(()=>wanTest.offline=true);await page.locator('#editWanVerify').click();
  assert(await page.locator('#editWanCheckOutput').innerText().then(text=>text.includes('Apply the downloaded setup file onsite')));
  await page.keyboard.press('Escape');assert(!(await page.getByRole('dialog').isVisible()));assert.strictEqual(await page.evaluate(()=>document.activeElement.id),'opener');
  await page.evaluate(()=>wanTest.storageOk=false);await open();
  assert(await page.locator('#editWanOutput').innerText().then(text=>text.includes('Ask your administrator')));
  assert(await page.locator('#editWanPrepare').isDisabled());
  await page.evaluate(()=>wanTest.storageOk=true);await page.getByRole('button',{name:'Try again',exact:true}).click();
  await page.waitForFunction(()=>!document.getElementById('editWanPrepare').disabled);
  await page.locator('#editWanLan').fill('bridge-lan');await page.evaluate(()=>wanTest.managementOk=false);await page.locator('#editWanPrepare').click();
  await page.getByRole('button',{name:'Download Internet recovery file',exact:true}).waitFor();
  // Tab wraps within the dialog and closing restores background scroll.
  await page.locator('#editWanPrepare').focus();await page.keyboard.press('Tab');
  assert.strictEqual(await page.evaluate(()=>document.activeElement.getAttribute('aria-label')),'Close Internet setup');
  await page.evaluate(()=>closeWanSetup());
  assert.strictEqual(await page.evaluate(()=>document.body.style.overflow),'');
  await page.evaluate(()=>wanTest.managementOk=true);await open();await page.locator('#editWanLan').fill('bridge-lan');
  const preview=path.join(os.tmpdir(),'codex-wan-preview');fs.mkdirSync(preview,{recursive:true});
  await page.locator('.wan-dialog').evaluate(async node=>{await Promise.all(node.getAnimations().map(animation=>animation.finished));});
  await page.screenshot({path:path.join(preview,'wan-desktop.png')});
  await page.setViewportSize({width:390,height:844});
  await page.screenshot({path:path.join(preview,'wan-mobile.png')});
  assert.strictEqual(await page.locator('.wan-connection-options').evaluate(node=>getComputedStyle(node).gridTemplateColumns.split(' ').length),1);
  assert(await page.evaluate(()=>document.querySelector('.wan-dialog').getBoundingClientRect().right<=window.innerWidth));
  assert.strictEqual(await page.evaluate(()=>document.querySelector('.wan-body').scrollWidth<=document.querySelector('.wan-body').clientWidth),true,'Mobile dialog must not overflow sideways');
  assert.deepStrictEqual(errors,[]);
  console.log('PASS: desktop/mobile layout, blur, connection choices, required fields, installer recovery, verification, migration feedback, focus trap and Escape close.');
  console.log('Previews: '+preview);
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
