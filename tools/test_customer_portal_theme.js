/* Actual customer PHP templates; all data synthetic, no external actions. */
const {chromium}=require('playwright');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'), path=require('node:path'), assert=require('node:assert/strict');
const root=path.join(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.PORTAL_BROWSER});
 try {
  const page=await browser.newPage({reducedMotion:"reduce"}); const errors=[];page.on('pageerror',e=>errors.push(e.message));
  let template='dashboard.php',theme='light';
  await page.route('**/*',async route=>{
   const url=new URL(route.request().url());
   if(url.hostname!=='portal.test') return route.abort();
   if(url.pathname.endsWith('.php')) {
    if(url.pathname.startsWith('/api/') || url.pathname.startsWith('/customer/api/')) return route.fulfill({json:{success:false,message:'Offline preview'}});
    const html=execFileSync(process.env.PORTAL_PHP||'php',['-n',path.join(__dirname,'render_customer_preview.php'),template],{encoding:'utf8',env:{...process.env,PREVIEW_THEME:theme}});
    assert.ok(!html.includes('Fatal error'),html.slice(-1500));
    return route.fulfill({contentType:'text/html',body:html});
   }
   const local=path.join(root,url.pathname); if(fs.existsSync(local)) return route.fulfill({path:local});
   return route.abort();
  });
  for(theme of ['light','dark']) for(template of ['dashboard.php','packages.php','payment.php','account.php','devices.php','login.php','register.php','renew.php']) {
   await page.setViewportSize({width:390,height:844});
   await page.goto('https://portal.test/customer/'+template);
   const styles=await page.evaluate(()=>({accent:getComputedStyle(document.documentElement).getPropertyValue('--accent').trim(),surface:getComputedStyle(document.documentElement).getPropertyValue('--surface').trim()}));
   assert.equal(styles.accent,'#00856a',template+' uses tenant accent');
   assert.notEqual(styles.surface,theme==='dark'?'#ffffff':'#222221');
   const surfaceCheck=await page.evaluate(()=>{
    const target=document.querySelector('.auth-body,.status-card,.packages-list,.pay-card,.acct-card,.dev-card');
    const probe=document.createElement('span');probe.style.background='var(--surface)';document.body.appendChild(probe);
    const expected=getComputedStyle(probe).backgroundColor;probe.remove();
    return {actual:getComputedStyle(target).backgroundColor,expected};
   });
   assert.equal(surfaceCheck.actual,surfaceCheck.expected,template+' surface follows theme');
   for(const width of [320,390,1280]) {
    await page.setViewportSize({width,height:844});
    const size=await page.evaluate(()=>({doc:document.documentElement.scrollWidth,view:innerWidth}));
    assert.ok(size.doc<=size.view,`${theme} ${template} overflow: ${JSON.stringify(size)}`);
   }
   await page.setViewportSize({width:390,height:844});
   if(template==='dashboard.php') {
    assert.equal(await page.locator('#customer-network-password').getAttribute('type'),'password');
    await page.getByRole('button',{name:'Show password',exact:true}).click();
    assert.equal(await page.locator('#customer-network-password').getAttribute('type'),'text');
    await page.getByRole('button',{name:'Hide password',exact:true}).click();
    assert.equal(await page.locator('.mobile-nav').isVisible(),true);
    await page.locator('.menu-toggle').click();
    assert.equal(await page.locator('.menu-toggle').getAttribute('aria-expanded'),'true');
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('.menu-toggle').getAttribute('aria-expanded'),'false');
    await page.screenshot({path:path.join(root,'artifacts','customer-dashboard-'+theme+'.png'),fullPage:true});
   }
   if(theme==='light' && ['packages.php','payment.php','login.php'].includes(template)) await page.screenshot({path:path.join(root,'artifacts','customer-'+template.replace('.php','')+'.png'),fullPage:true});
   console.log('PASS '+theme+' '+template+': tenant palette, three viewport widths');
  }
  template='dashboard.php';theme='light';await page.goto('https://portal.test/customer/dashboard.php');
  const picker=page.locator('[data-customer-theme-picker]');
  await picker.selectOption('dark');
  assert.equal(await page.locator('html').getAttribute('data-customer-theme'),'dark');
  assert.equal(await page.evaluate(()=>getComputedStyle(document.body).backgroundColor),'rgb(20, 20, 20)');
  await page.reload();assert.equal(await picker.inputValue(),'dark');
  template='payment.php';await page.goto('https://portal.test/customer/payment.php');assert.equal(await picker.inputValue(),'dark');
  await picker.selectOption('light');assert.equal(await page.evaluate(()=>getComputedStyle(document.body).backgroundColor),'rgb(243, 245, 247)');
  await picker.selectOption('system');await page.emulateMedia({colorScheme:'dark'});await page.waitForFunction(()=>document.documentElement.getAttribute('data-customer-theme')==='dark');assert.equal(await page.locator('html').getAttribute('data-customer-theme'),'dark');
  await picker.selectOption('tenant');assert.equal(await page.locator('html').getAttribute('data-customer-theme'),'tenant');
  await page.screenshot({path:path.join(root,'artifacts','customer-payment-theme-picker.png'),fullPage:true});
  console.log('PASS personal theme choice, persistence across pages, device theme and tenant reset');
  assert.deepEqual(errors,[]);
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
