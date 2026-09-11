/* Real browser, actual portal template; simulated gateway and RouterOS responses.
 * Requires playwright on NODE_PATH and optionally PORTAL_BROWSER pointing to Chrome/Edge.
 */
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const template = process.env.PORTAL_TEMPLATE
    ? fs.readFileSync(process.env.PORTAL_TEMPLATE, 'utf8')
    : fs.readFileSync(path.join(__dirname, '../hotspot/login.html'), 'utf8');
function render(error = false) {
    const tabs = ['buy', 'login', 'voucher', 'reconnect'];
    const replacements = {
        PORTAL_URL:'http://portal.test', TENANT_ID:'9', HAS_PAID_TAB:'true', TABS_JSON:JSON.stringify(tabs),
        TAB_BUTTONS:tabs.map(t=>`<button id="tb-${t}" class="tab-btn" onclick="switchTab('${t}')">${t}</button>`).join(''),
        PACKAGES_SECTION:'<label class="pkg-row" data-price="5" data-free="0" data-name="Half hour" data-dur="30 minutes"><input type="radio" name="package" value="39">30 minutes</label>',
        COMPANY_NAME:'Portal test', BODY_BG:'#fff'
    };
    return template.replace(/\$\(if error\)([\s\S]*?)\$\(endif\)/g, (_, block)=>error ? block.replace('$(error)', 'Login rejected') : '')
        .replace(/\{\{([A-Z_]+)\}\}/g, (_, key)=>replacements[key] || '')
        .replaceAll('https://portal.test', 'http://portal.test')
        .replaceAll('$(link-login-only)', 'http://portal.test/router-login')
        .replace(/\$\(mac(?:-esc)?\)/g, 'AA:BB:CC:DD:EE:FF');
}
(async()=>{
    const browser = await chromium.launch({headless:true, ...(process.env.PORTAL_BROWSER ? {executablePath:process.env.PORTAL_BROWSER} : {})});
    try {
        const context = await browser.newContext({viewport:{width:390,height:844}, isMobile:true, hasTouch:true});
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error=>errors.push(error.message));
        let posts=0, polls=0, prompts=0, loginChecks=0, deferredLogin;
        let loginResponse={processing:true}, status={status:'completed',username:'paid-user',password:'test-only'};
        await page.route('**/*', async route=>{
            const url = new URL(route.request().url());
            if (url.pathname === '/router-login') { posts++; await route.fulfill({contentType:'text/html',body:render(true)}); }
            else if (url.pathname.endsWith('hotspot_payment_status.php')) { polls++; await route.fulfill({json:status}); }
            else if (url.pathname.endsWith('hotspot_stk_push.php')) {
                prompts++;
                const body = new URLSearchParams(route.request().postData());
                assert.equal(body.get('phone'),'254712345678'); assert.equal(body.get('package_id'),'39');
                await route.fulfill({json:{success:true,checkout_request_id:'fresh-checkout',client_id:1}});
            } else if (url.pathname.endsWith('hotspot_login.php')) {
                loginChecks++;
                if (deferredLogin) await deferredLogin;
                await route.fulfill({json:loginResponse});
            } else if (url.hostname === 'portal.test') await route.fulfill({contentType:'text/html',body:render()});
            else await route.abort();
        });
        await page.goto('http://portal.test/');
        await page.evaluate(()=>localStorage.setItem('fortunett-checkout-9',JSON.stringify({checkout:'old-paid',client:1,saved:Date.now()})));
        await page.reload();
        if (process.env.PORTAL_EXPECT_REFRESH_LOOP === '1') {
            await page.waitForTimeout(9000);
            assert.ok(posts>=2, 'Original portal must reproduce repeated RouterOS submissions');
            console.log(`REPRODUCED: original portal submitted router login ${posts} times in nine seconds without customer input`);
            return;
        }
        await page.locator('.pkg-row').first().click();
        await page.locator('#buy-phone').pressSequentially('0712345678',{delay:450});
        assert.equal(await page.locator('#buy-phone').inputValue(),'0712345678');
        assert.equal(posts,0); assert.equal(polls,0); assert.equal(prompts,0);
        console.log('PASS: saved paid checkout leaves phone entry untouched through the former refresh interval');

        await page.getByRole('button',{name:'Close payment',exact:true}).click();
        await page.getByRole('button',{name:'Check previous payment'}).click();
        await page.waitForURL('**/router-login');
        await page.waitForTimeout(9000);
        assert.equal(posts,1); assert.equal(polls,1); assert.equal(prompts,0);
        console.log('PASS: rejected RouterOS handoff returns once and never enters a refresh loop');

        await page.evaluate(()=>{forgetCheckout(); switchTab('buy');});
        await page.locator('.pkg-row').first().click();
        await page.locator('#buy-phone').fill('0712345678');
        status={status:'pending'};
        await page.locator('#buy-btn').click();
        await page.waitForFunction(()=>_buyReqId === 'fresh-checkout');
        await page.evaluate(()=>pollBuyStatus());
        await page.waitForFunction(()=>!_buyPolling);
        assert.equal(prompts,1); assert.equal(posts,1);
        status={status:'completed',username:'new-user',password:'test-only'};
        const handoff = page.waitForEvent('framenavigated', {predicate:frame=>frame===page.mainFrame()});
        await page.evaluate(()=>pollBuyStatus());
        await handoff;
        await page.waitForLoadState();
        await page.waitForTimeout(1000);
        assert.equal(posts,2); assert.equal(prompts,1);
        console.log('PASS: phone submission requests one prompt; only confirmed payment submits router credentials');

        await page.evaluate(()=>{forgetCheckout(); switchTab('login');});
        await page.locator('#hs-user').fill('paid-user'); await page.locator('#hs-pass').fill('test-only');
        await page.locator('#hs-login-btn').click();
        await page.waitForFunction(()=>document.getElementById('hs-login-err').textContent.includes('shortly'));
        await page.evaluate(()=>switchTab('buy'));
        await page.locator('.pkg-row').first().click();
        await page.locator('#buy-phone').fill('0712345678');
        await page.waitForTimeout(9000);
        assert.equal(loginChecks,1); assert.equal(posts,2);
        assert.equal(await page.locator('#buy-phone').inputValue(),'0712345678');
        console.log('PASS: provisioning wait cannot launch timed sign-in retries while the customer is buying');

        let release;
        deferredLogin=new Promise(resolve=>{release=resolve;});
        loginResponse={success:true,mikrotik_username:'paid-user',mikrotik_password:'test-only'};
        await page.evaluate(()=>switchTab('login'));
        await page.locator('#hs-login-btn').click();
        while (loginChecks<2) await page.waitForTimeout(20);
        await page.evaluate(()=>switchTab('buy'));
        release();
        await page.waitForFunction(()=>!document.getElementById('hs-login-btn').disabled);
        assert.equal(posts,2); assert.equal(await page.locator('#buy-phone').inputValue(),'0712345678');
        assert.deepEqual(errors,[]);
        console.log('PASS: late successful sign-in cannot navigate away from phone entry; no browser script errors');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
