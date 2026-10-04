const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync(__dirname + '/../billing.php', 'utf8');
const fn = source.slice(source.indexOf('function openInvoiceModal(bill) {'), source.indexOf('function openPaystackModal()'))
    .replace(/<\?php[\s\S]*?\?>/g, 'TEST');
const elements = {};
const context = {
    document: { getElementById: id => elements[id] ||= { style: {} } },
    fmt: n => Number(n).toFixed(2)
};
vm.createContext(context);
vm.runInContext(fn, context);
const bill = {id: 7, base_fee: 500, pppoe_subtotal: 50, commission_amount: 30,
    commission_rate: 0.03, pppoe_rate: 25, pppoe_count: 2, total_due: 580,
    amount_paid: 100, balance_due: 480, billing_period: '2026-09-01',
    due_date: '2026-09-16', invoice_number: 'INV-2026-09-7', status: 'pending'};
context.openInvoiceModal(bill);
assert.equal(elements.invMonthlyFee.textContent, 'KES 500.00');
assert.equal(elements.invRouterQty.textContent,'1 month');
assert.equal(elements.invMonthlyRate.textContent,'KES 500.00/month');
assert.equal(elements.invBaseFee.textContent, 'KES 50.00');
assert.equal(elements.invCommRate.textContent, '3.00%');
assert.equal(elements.invServiceSubtotal.textContent, 'KES 580.00');
assert.equal(elements.invAmountPaid.textContent, 'KES 100.00');
assert.equal(elements.invTotalDue.textContent, 'KES 480.00');
assert.equal(context.currentInvoiceAmount, 480);
assert.equal(elements.invNumber.textContent, bill.invoice_number);
context.openInvoiceModal({...bill, commission_rate: 0, pppoe_rate: 0, amount_paid: 580, balance_due: 0, status: 'paid'});
assert.equal(elements.invCommRate.textContent, '0.00%');
assert.equal(elements.invPppoeRate.textContent, 'KES 0.00/user');
assert.equal(context.currentInvoiceAmount, 0);
assert.equal(elements.invPayBar.style.display, 'none');
context.openInvoiceModal({...bill,base_fee:1000,router_count:2,router_fee_per_router:500,total_due:1080,balance_due:980});
assert.equal(elements.invRouterQty.textContent,'2 routers');
assert.equal(elements.invMonthlyRate.textContent,'KES 500.00/router');
assert.equal(elements.invMonthlyFee.textContent,'KES 1000.00');
assert.equal(elements.invRouterFeeRow.style.display,'');
context.openInvoiceModal({...bill,base_fee:0,router_count:0,router_fee_per_router:0});
assert.equal(elements.invRouterFeeRow.style.display,'none');
context.openInvoiceModal({...bill,show_router_fee:false});
assert.equal(elements.invRouterFeeRow.style.display,'none');
console.log('PASS trial/no-router rows hidden; quantity times unit rate matches invoice base charge');
console.log('PASS invoice breakdown, stored rates, totals, part-payments and paid invoices');
