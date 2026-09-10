const fs = require('fs'), vm = require('vm'), assert = require('assert/strict');
const source = fs.readFileSync(require('path').join(__dirname, '../customer-sms.js'), 'utf8');
async function scenario(result, networkFailure = false) {
    let calls = 0, refreshes = 0;
    const ids = ['smsFeedback','smsClientId','smsSendBtn','smsMessage','smsTemplate'];
    const elements = Object.fromEntries(ids.map(id => [id,{value:'',dataset:{},textContent:'',disabled:false}]));
    elements.smsMessage.value = 'My message'; elements.smsClientId.value = '7';
    const context = {
        Uint8Array, crypto:require('crypto').webcrypto, smsRetryCsrf:'token',
        document:{getElementById:id=>elements[id], addEventListener:()=>{}},
        loadSMSHistory:id=>{ assert.equal(id,7); refreshes++; },
        fetch:async (url,options)=>{
            calls++;
            assert.equal(url,'api/clients/send_sms.php');
            assert.equal(options.headers['X-CSRF-Token'],'token');
            assert.equal(JSON.parse(options.body).message,'My message');
            if(networkFailure) throw new Error('Offline');
            return {json:async()=>result};
        }
    };
    vm.runInNewContext(source,context);
    const event = {preventDefault:()=>{}};
    const sending = context.handleSendSMS(event);
    await context.handleSendSMS(event);
    await sending;
    assert.equal(calls,1);
    assert.equal(elements.smsMessage.value,'My message');
    assert.equal(elements.smsMessage.readOnly,false);
    return {elements,refreshes};
}
(async()=>{
    let run = await scenario({success:true,message:'Accepted'});
    assert.equal(run.elements.smsSendBtn.textContent,'Sent');
    assert.equal(run.elements.smsSendBtn.disabled,true);
    assert.equal(run.refreshes,1);
    console.log('PASS: Actual endpoint called once, external submit button handled, history refreshed');
    run = await scenario({success:false,retryable:true,message:'Insufficient balance'});
    assert.equal(run.elements.smsFeedback.textContent,'Insufficient balance');
    assert.equal(run.elements.smsSendBtn.disabled,false);
    console.log('PASS: Provider failure stays visible, message preserved, retry enabled');
    run = await scenario(null,true);
    assert.equal(run.elements.smsSendBtn.disabled,true);
    assert.match(run.elements.smsFeedback.textContent,/could not be confirmed/);
    console.log('PASS: Network failure cannot display false success or invite duplicate send');
})().catch(error=>{console.error(error);process.exitCode=1;});
