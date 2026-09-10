const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const source = fs.readFileSync(require('path').join(__dirname, '../sms-retry.js'), 'utf8');
async function run(result, throws = false) {
    let handler, calls = 0;
    const feedback = {};
    const button = {disabled: false, dataset: {id: '7'}, parentElement: {querySelector: () => feedback}};
    const context = {
        smsRetryCsrf: 'test-token',
        document: {addEventListener: (_, fn) => handler = fn},
        fetch: async (_, options) => {
            calls++;
            assert.equal(options.headers['X-CSRF-Token'], 'test-token');
            assert.deepEqual(JSON.parse(options.body), {id: 7});
            if (throws) throw new Error('Connection lost');
            return {json: async () => result};
        }
    };
    vm.runInNewContext(source, context);
    const event = {target: {closest: () => button}};
    const first = handler(event);
    await handler(event);
    await first;
    assert.equal(calls, 1, 'Double click must send only one request');
    return {button, feedback};
}
(async () => {
    let state = await run({success: true, message: 'Accepted'});
    assert.equal(state.button.disabled, true);
    assert.equal(state.button.textContent, 'Resent');
    console.log('PASS: Success disables further retries and double clicks send once');
    state = await run({success: false, retryable: true, message: 'Insufficient balance'});
    assert.equal(state.button.disabled, false);
    assert.equal(state.feedback.textContent, 'Insufficient balance');
    console.log('PASS: Definite failure displays feedback and permits another attempt');
    state = await run(null, true);
    assert.equal(state.button.disabled, true);
    assert.match(state.feedback.textContent, /could not be confirmed/);
    console.log('PASS: Network failure does not trigger an unsafe duplicate request');
})().catch(error => { console.error(error); process.exitCode = 1; });
