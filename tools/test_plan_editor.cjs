const fs=require('fs'),path=require('path'),assert=require('assert');
const {chromium}=require(process.env.UI_PLAYWRIGHT_PATH || 'playwright');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const source=read('super_admin/plans.php');
const strip=s=>s.replace(/<\?php[\s\S]*?\?>/g,'');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.UI_BROWSER_PATH,headless:true});
 try {
  const css=strip(source.match(/<style>([\s\S]*?)<\/style>/)[1])+read('css/responsive-app.css')+read('super_admin/css/plans.css');
  const markup=strip(source.slice(source.indexOf('<div class="edit-modal"'),source.indexOf('<!-- Plans table -->')));
  const script=source.slice(source.indexOf('let planEditorOpener'),source.indexOf('</script>',source.indexOf('let planEditorOpener')));
  for(const width of [320,390,768,1280]) {
   const page=await browser.newPage({viewport:{width,height:800}});
   await page.setContent('<style>'+css+'</style><button id="opener">Edit pricing</button>'+markup);
   await page.addScriptTag({content:script});
   await page.locator('#opener').focus();
   await page.evaluate(()=>openEditPanel({id:3,name:'Standard',pppoe_fee_per_user:25,hotspot_commission_rate:3,base_monthly_fee:500,sms_rate:1.25,max_clients:0,max_routers:null,trial_days:30}));
   assert.equal(await page.locator('#fSmsRate').inputValue(),'1.25');
   assert.equal(await page.locator('#fHotspotRate').inputValue(),'3');
   assert.equal(await page.locator('#fMaxClients').inputValue(),'');
   const box=await page.locator('#editPanel').boundingBox();
   assert(box.width<=width && box.height<=800,'Editor fits viewport');
   await page.locator('#fSmsRate').fill('2.5000');
   await page.locator('#fHotspotRate').fill('5');
   const values=await page.evaluate(()=>Object.fromEntries(new FormData(document.getElementById('editForm'))));
   assert.equal(values.sms_rate,'2.5000');assert.equal(values.hotspot_commission_rate,'5');
   await page.locator('#fHotspotRate').fill('101');
   assert(!(await page.locator('#fHotspotRate').evaluate(el=>el.checkValidity())));
   await page.keyboard.press('Escape');
   assert(!(await page.locator('#editPanel').isVisible()));
   assert(await page.locator('#opener').evaluate(el=>el===document.activeElement));
   console.log('PASS plan editor rates, validation, viewport and focus at '+width+'px');
   await page.close();
  }
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
