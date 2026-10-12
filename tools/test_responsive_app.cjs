const fs = require('fs');
const path = require('path');
const assert = require('assert');
const {chromium} = require(process.env.UI_PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname,'..');
const read = file => fs.readFileSync(path.join(root,file),'utf8');
const strip = html => html.replace(/<\?php[\s\S]*?\?>/g,'');
(async () => {
 const browser = await chromium.launch({executablePath:process.env.UI_BROWSER_PATH,headless:true});
 try {
  const source = read('sms.php');
  const inline = strip(source.match(/<style>([\s\S]*?)<\/style>/)[1]);
  let markup = strip(source.slice(source.indexOf('<div class="main-content-wrapper">'),source.indexOf('<script>')));
  const message = 'Payment received.\nUsername: testcustomer\nPassword: 1234\nSupport: 0700123456\n' + 'Full message remains readable. '.repeat(12);
  markup = markup.replace(/(<div class="sms-message-content">).*?(<\/div>)/s,'$1'+message+'$2');
  const styles = inline + read('css/sms-manager.css') + read('css/responsive-app.css');
  for (const width of [320,360,390,768,1280]) {
   const page = await browser.newPage({viewport:{width,height:800}});
   await page.setContent('<html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;background:#141414;font-family:system-ui;color:white}'+styles+'</style></head><body>'+markup+'</body></html>');
   await page.addScriptTag({content:read('js/sms-manager.js')});
   assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth+1),'SMS page overflow at '+width);
   const detail = page.locator('.sms-message-details').first();
   await detail.locator('summary').click();
   assert(await detail.locator('.sms-message-content').isVisible());
   assert((await detail.innerText()).includes('Support: 0700123456'));
   assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth+1),'Expanded SMS overflow at '+width);
   await page.evaluate(() => document.getElementById('configModal').style.display='flex');
   const modal = page.locator('#configModal .sms-modal-inner');
   const bounds=await modal.boundingBox();
   assert(bounds.width<=width && bounds.height<=800,'SMS settings modal fits viewport');
   await page.keyboard.press('Escape');
   assert(!(await modal.isVisible()),'Escape closes dialog');
   if (width===390) {
    await page.evaluate(() => document.getElementById('configModal').style.display='none');
    await page.screenshot({path:path.join(root,'artifacts/sms-mobile-preview.png'),fullPage:true});
   }
   await page.close();
   console.log('PASS SMS layout, full message and settings modal at '+width+'px');
  }
  const page=await browser.newPage({viewport:{width:320,height:700}});
  await page.setContent('<style>body{margin:0}.content{padding:24px}.form-grid{display:grid;grid-template-columns:repeat(3,1fr)}table{min-width:1000px}'+read('css/responsive-app.css')+'</style><main class="content"><div class="form-grid"><input><input><input></div><table><tr><td>Wide reports table</td></tr></table></main>');
  await page.addScriptTag({content:read('js/responsive-app.js')});
  assert(await page.evaluate(() => document.documentElement.scrollWidth<=innerWidth+1),'Shared tables stay within viewport');
  assert(await page.locator('.responsive-table-scroll').count()===1);
  await page.evaluate(() => document.querySelector('table').insertAdjacentHTML('beforeend','<tr><td>Updated data</td></tr>'));
  await page.waitForTimeout(100);
  assert(await page.locator('.responsive-table-scroll').count()===1,'Dynamic updates do not nest scroll wrappers');
  console.log('PASS shared stacked forms and contained dynamic tables at 320px');
 } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exit(1);});
