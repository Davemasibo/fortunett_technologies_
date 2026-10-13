const {chromium}=require('./browser/node_modules/playwright');
const fs=require('fs'),assert=require('node:assert/strict'),path=require('path');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try {
  const html=fs.readFileSync(path.join(__dirname,'preview.html'),'utf8');
  const data=JSON.parse(fs.readFileSync(path.join(__dirname,'hotspot-status.json'),'utf8').replace(/^\uFEFF/,''));
  const auto=JSON.parse(fs.readFileSync(path.join(__dirname,'automation-after.json'),'utf8').replace(/^\uFEFF/,''));
  const coexist=JSON.parse(fs.readFileSync(path.join(__dirname,'coexistence.json'),'utf8').replace(/^\uFEFF/,''));
  for(const width of [1440,390]){
   const page=await browser.newPage({viewport:{width,height:1000}});const errors=[];
   page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*',async route=>{
    const url=new URL(route.request().url());
    if(url.pathname==='/hotspot_diagnostics.php')return route.fulfill({body:html,contentType:'text/html'});
    if(url.pathname==='/assets/diagnostics.css')return route.fulfill({body:fs.readFileSync('assets/diagnostics.css'),contentType:'text/css'});
    if(url.pathname.endsWith('hotspot_status.php'))return route.fulfill({json:data});
    if(url.pathname.endsWith('automation_chain.php')){assert.match(route.request().postData(),/service=hotspot/);return route.fulfill({json:auto});}
    if(url.pathname.endsWith('coexistence_check.php'))return route.fulfill({json:coexist});
    if(url.pathname.endsWith('wireguard_status.php'))return route.fulfill({json:{success:true,checks:[],verdict:{level:'ok',title:'Tunnel reachable',message:'Verified'}}});
    if(url.pathname.endsWith('repair_hotspot_portal.php'))return route.fulfill({json:{success:true,message:'Portal rules updated',applied:['HTTPS rule added']}});
    const local=path.resolve('.',url.pathname.slice(1));
    if(url.hostname==='diagnostics.test' && /\.(css|js)$/.test(local) && fs.existsSync(local)) return route.fulfill({body:fs.readFileSync(local),contentType:local.endsWith('.css')?'text/css':'text/javascript'});
    return route.fulfill({body:'',contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript'});
   });
   await page.goto('https://diagnostics.test/hotspot_diagnostics.php');
   await page.locator('#checks-12').getByText('Hotspot gateway',{exact:true}).waitFor();
   assert.match(await page.locator('#auto-verdict').innerText(),/Review 2 caveats/);
   assert.match(await page.locator('.hd-container').innerText(),/KSh 10.00/);
   assert.equal(await page.locator('.hd-container').evaluate(el=>el.scrollWidth>el.clientWidth),false,'Mobile content must fit viewport');
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth),false,'Whole page must fit viewport');
   assert.equal(errors.length,0,errors.join('\n'));
   await page.screenshot({path:path.join(__dirname,`diagnostics-${width}.png`),fullPage:true});
   // Display a real repairable failure, then verify persistent action feedback.
   await page.evaluate(d=>renderChecks(12,d),{...data,checks:data.checks.map(c=>c.id==='portal'?{...c,level:'fail'}:c),verdict:{level:'fail',title:'Portal needs attention',message:'Repair current DNS rules'}});
   await page.locator('#portal-repair-12').click();
   await page.locator('#repair-result-12').getByText('Portal rules updated HTTPS rule added',{exact:true}).waitFor();
   await page.evaluate(()=>setVerdict(document.getElementById('verdict-12'),'fail','','<img src=x onerror=alert(1)>','<script>broken</script>'));
   assert.equal(await page.locator('#verdict-12 img').count(),0,'Router text must be escaped');
   await page.close();
  }
  console.log('Desktop and mobile diagnostic layout, hotspot scope, repair feedback and HTML escaping passed');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
