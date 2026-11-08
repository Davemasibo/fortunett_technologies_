const {chromium}=require('playwright');const assert=require('node:assert/strict');
(async()=>{
    const browser=await chromium.launch({executablePath:process.env.PORTAL_BROWSER||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
    const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
    try{
        await page.goto('http://127.0.0.1:8765/super_admin/billing.php?invoice=9');
        await page.locator('#su-user').fill('browser-admin');await page.locator('#su-pass').fill('Browser-test-only-42!');
        await Promise.all([page.waitForURL('**/billing.php?invoice=9'),page.locator('#sa-login-submit').click()]);
        await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Refresh current invoice'}).click()]);
        assert(!page.url().endsWith('invoice=9'));
        assert.match(await page.locator('.inv-lines').first().textContent(),/Prior-period collections commission/);
        const details=page.locator('.inv-lines').filter({hasText:'Unbilled collections carried forward'});
        assert.match(await details.textContent(),/200.00/);assert.match(await details.textContent(),/3%/);assert.match(await details.textContent(),/6.00/);
        await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Refresh current invoice'}).click()]);
        assert.match(await page.locator('.line-total').textContent(),/6.00/);
        await page.screenshot({path:'artifacts/invoice-carryover-mobile.png',fullPage:true});
        await page.getByRole('link',{name:'Edit invoice / view corrections'}).click();
        await page.locator('#base_fee').fill('1');await page.locator('#reason').fill('Verify carryover survives charge correction');
        assert.match(await page.locator('#correction-total').textContent(),/7.00/);
        await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Save correction'}).click()]);
        assert.match(await page.locator('[role=status]').textContent(),/Correction saved/);
        await page.getByRole('link',{name:'Back to invoice'}).click();
        assert.match(await page.locator('.line-total').textContent(),/7.00/);
        assert.match(await page.locator('.inv-lines').filter({hasText:'Unbilled collections carried forward'}).textContent(),/6.00/);
        await page.goto('http://127.0.0.1:8765/super_admin/billing.php?invoice=9');
        assert.match(await page.locator('.line-total').textContent(),/30.00/);
        assert.deepEqual(errors,[]);
        console.log('PASS refresh from early-paid invoice creates next invoice, shows source and historical rate, avoids duplicate charges, and preserves original paid total.');
    }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
