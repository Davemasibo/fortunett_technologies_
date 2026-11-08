const {chromium}=require('playwright');
const assert=require('node:assert/strict');
(async()=>{
    const browser=await chromium.launch({executablePath:process.env.PORTAL_BROWSER||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
    const page=await browser.newPage({viewport:{width:390,height:844}});
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    try {
        const base='http://127.0.0.1:8765';
        await page.goto(base+'/super_admin/disbursements.php?tenant_id=9');
        await page.locator('#su-user').fill('browser-admin');await page.locator('#su-pass').fill('Browser-test-only-42!');
        await Promise.all([page.waitForURL('**/disbursements.php?tenant_id=9'),page.locator('#sa-login-submit').click()]);
        const panel=page.locator('section').filter({has:page.getByRole('heading',{name:'Settle invoices before payout'})});
        assert.match(await panel.textContent(),/KES 100.00/);
        assert.match(await panel.textContent(),/14,900.00/);
        await page.locator('#payout_amount').fill('100');
        assert(await page.locator('#record-disbursement').isDisabled());
        assert.equal(await page.locator('[name=confirm_invoices]').count(),1);
        await page.locator('[name=confirm_invoices]').check();
        await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Deduct invoices from collections'}).click()]);
        assert.match(await page.locator('[role=status]').textContent(),/invoices settled from collections/);
        assert.match(await page.locator('.sa-summary-amount').textContent(),/14,900.00/);
        assert.match(await page.locator('main').textContent(),/No outstanding invoices/);
        assert.equal(await page.getByRole('button',{name:'Deduct invoices from collections'}).count(),0);
        await page.locator('#payout_amount').fill('14846');await page.locator('#fees_amount').fill('54');
        assert.equal(await page.locator('#summary-reduction').textContent(),'KES 14,900.00');
        assert.equal(await page.locator('#record-disbursement').isEnabled(),true);
        await page.locator('#reference').fill('AFTER-INVOICES');await page.locator('[name=confirmed]').check();
        await Promise.all([page.waitForNavigation(),page.locator('#record-disbursement').click()]);
        assert.match(await page.locator('[role=status]').textContent(),/recorded/);
        assert.match(await page.locator('.sa-summary-amount').textContent(),/0.00/);
        assert.match(await page.locator('tbody').last().textContent(),/COLLECTIONS-INVOICE-10/);
        assert.match(await page.locator('tbody').last().textContent(),/COLLECTIONS-INVOICE-11/);
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
        await page.screenshot({path:'artifacts/invoice-first-payout-mobile.png',fullPage:true});
        assert.deepEqual(errors,[]);
        console.log('PASS invoice-first review, part-paid balance, payout hold, batch deductions, remaining payout with fees, history and mobile layout.');
    } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
