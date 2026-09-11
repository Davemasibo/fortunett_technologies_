/* Production PHP renderer in a real mobile browser; no live payments or router. */
const {chromium}=require('playwright');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const root=path.join(__dirname,'..');
const php=process.env.PORTAL_PHP || 'php';
function fixture(buyOnly=false) {
    return execFileSync(php,['-n',path.join(__dirname,'render_hotspot_preview.php')],{
        encoding:'utf8',env:{...process.env,PORTAL_BUY_ONLY:buyOnly?'1':'0'}
    }).replace(/\$\(if error\)[\s\S]*?\$\(endif\)/g,'')
      .replaceAll('$(link-login-only)','https://portal.test/router-login')
      .replace(/\$\(mac(?:-esc)?\)/g,'AA:BB:CC:DD:EE:FF');
}
(async()=>{
    const browser=await chromium.launch({headless:true,...(process.env.PORTAL_BROWSER?{executablePath:process.env.PORTAL_BROWSER}:{})});
    try {
        const context=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true});
        const page=await context.newPage();
        let buyOnly=false,requests=0;
        const errors=[]; page.on('pageerror',error=>errors.push(error.message));
        await page.route('**/*',async route=>{
            if (new URL(route.request().url()).pathname==='/') await route.fulfill({contentType:'text/html',body:fixture(buyOnly)});
            else { requests++; await route.fulfill({json:{success:false,message:'Preview only'}}); }
        });
        await page.goto('https://portal.test/');
        assert.equal(await page.locator('.pkg-row').count(),10);
        const tariffs=['30 Minutes','1 Hour','3 Hours','6 Hours','8 Hours','14 Hours','24 Hours','4 Days','7 Days','30 Days'];
        assert.deepEqual(await page.locator('.pkg-dur').allTextContents(),tariffs);
        assert.ok((await page.locator('.pkg-speed').allTextContents()).every(text=>text==='10 Mbps'));
        for (const width of [320,390,720,1024]) {
            await page.setViewportSize({width,height:844});
            const geometry=await page.evaluate(()=>{
                const cards=[...document.querySelectorAll('.pkg-row')].map(el=>el.getBoundingClientRect());
                return {firstY:cards[0].y,secondY:cards[1].y,thirdY:cards[2].y,
                    reconnectBottom:document.getElementById('tab-reconnect').getBoundingClientRect().bottom,
                    pageWidth:document.documentElement.scrollWidth,viewport:innerWidth};
            });
            assert.equal(geometry.firstY,geometry.secondY); assert.ok(geometry.thirdY>geometry.firstY);
            assert.ok(geometry.reconnectBottom<=geometry.firstY);assert.ok(geometry.pageWidth<=geometry.viewport);
        }
        console.log('PASS: ten exact tariffs, actual speed, reconnect first and two columns without overflow at four screen widths');
        await page.setViewportSize({width:390,height:844});
        const artifacts=path.join(root,'artifacts');fs.mkdirSync(artifacts,{recursive:true});
        await page.screenshot({path:path.join(artifacts,'hotspot-simplified-portal.png'),fullPage:true});
        await page.locator('.pkg-row').first().click();
        assert.equal(await page.locator('#payment-dialog').evaluate(el=>el.open),true);
        await page.locator('#buy-phone').pressSequentially('0712345',{delay:150});
        await page.reload();
        assert.equal(await page.locator('#buy-phone').inputValue(),'0712345');
        assert.equal(await page.locator('.pkg-row.selected input').inputValue(),'39');
        assert.equal(await page.locator('#payment-dialog').evaluate(el=>el.open),true);
        assert.equal(requests,0);
        await page.screenshot({path:path.join(artifacts,'hotspot-simplified-payment.png')});
        console.log('PASS: forced page reload restores selected package, partial number and payment dialog without an API request');
        await page.getByRole('button',{name:'Close payment',exact:true}).click();
        await page.locator('.pkg-row').first().click();
        assert.equal(await page.locator('#payment-dialog').evaluate(el=>el.open),true);
        await page.getByRole('button',{name:'Close payment',exact:true}).click();
        console.log('PASS: Buy Now can reopen the same selected package after closing');
        await page.locator('#rc-code').fill('ABC1234567');await page.reload();
        assert.equal(await page.locator('#rc-code').inputValue(),'ABC1234567'); assert.equal(requests,0);
        console.log('PASS: receipt draft survives reload without automatically redeeming it');
        await page.evaluate(()=>{sessionStorage.clear();localStorage.clear();});
        buyOnly=true;await page.reload();
        assert.equal(await page.locator('#tab-reconnect').count(),0);
        assert.equal(await page.locator('#hs-auto-form').count(),1);
        await page.locator('.pkg-row').first().click();await page.locator('#buy-phone').fill('0712345678');
        await page.locator('#buy-btn').click();
        await page.waitForFunction(()=>document.getElementById('buy-notice').textContent==='Preview only');
        assert.equal(requests,1);assert.deepEqual(errors,[]);
        console.log('PASS: buy-only tenant retains payment handoff and handles API failure with no script errors');
    }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
