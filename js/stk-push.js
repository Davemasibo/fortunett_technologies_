/**
 * Shared M-Pesa STK Push driver.
 * ─────────────────────────────────────────────────────────────────────────────
 * One implementation of the initiate → wait → confirm cycle for every STK flow
 * in the platform, so they all show the same four-step progress and the same
 * plain-language failure reasons.
 *
 * Before this, each page rolled its own: the tenant billing modal gave up after
 * two minutes with no explanation, and none of them could tell a customer that
 * they had cancelled the prompt — the commonest outcome of all.
 *
 * Usage:
 *   FortunettSTK.run({
 *     container : document.getElementById('stkMount'),
 *     steps     : ['Sending request','Check your phone','Confirming payment','Updating your account'],
 *     initiate  : () => fetch(...).then(r => r.json()),   // -> {success, checkout_request_id}
 *     poll      : (id) => fetch(...).then(r => r.json()), // -> {status, message}
 *     onCompleted: (d) => {...},
 *     onFailed   : (msg) => {...},
 *     onCancel   : () => {...}
 *   });
 *
 * poll() must resolve to one of:
 *   {status:'pending'}                  still waiting
 *   {status:'processing', message}      paid, work still finishing
 *   {status:'completed', ...}           done
 *   {status:'failed', message}          terminal
 */
(function (global) {
    'use strict';

    var DEFAULT_STEPS = [
        'Sending request',
        'Check your phone',
        'Confirming payment',
        'Finishing up'
    ];
    var DEFAULT_SUBS = [
        'Reaching M-Pesa…',
        'Enter your M-Pesa PIN on the prompt',
        'Waiting for Safaricom…',
        'Almost there'
    ];

    /* Safaricom result codes worth naming. Anything else gets the generic line —
       "Payment failed" with no reason is what generates support calls. */
    var REASONS = {
        1032: 'You cancelled the payment request.',
        1037: 'The request timed out — you did not enter your PIN in time.',
        1019: 'The payment request expired. Please try again.',
        2001: 'Wrong M-Pesa PIN entered.',
        1:    'Insufficient M-Pesa balance.'
    };

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function reasonFor(code, fallback) {
        return REASONS[Number(code)] || fallback || 'The payment was not completed. You can try again.';
    }

    function Runner(opts) {
        this.o = opts || {};
        this.steps = this.o.steps || DEFAULT_STEPS;
        this.subs  = this.o.subs  || DEFAULT_SUBS;
        this.pollMs   = this.o.pollMs   || 4000;
        this.maxPolls = this.o.maxPolls || 75;   /* 75 × 4s = 5 min, Safaricom's STK timeout */
        this.timer = null;
        this.count = 0;
        this.id    = null;
        this.done  = false;
    }

    Runner.prototype.render = function () {
        var html = '<div class="stk"><div class="stk-steps">';
        for (var i = 0; i < this.steps.length; i++) {
            html += '<div class="stk-step" data-step="' + i + '">'
                 +    '<div class="stk-dot"></div>'
                 +    '<div class="stk-txt">'
                 +      '<div class="stk-title">' + esc(this.steps[i]) + '</div>'
                 +      '<div class="stk-sub">' + esc(this.subs[i] || '') + '</div>'
                 +    '</div>'
                 +  '</div>';
        }
        html += '</div><div class="stk-countdown"></div>'
             +  '<div class="stk-outcome"></div></div>';
        this.o.container.innerHTML = html;
        this.root = this.o.container.querySelector('.stk');
    };

    /* n = the step now in progress (1-based); everything before it is done. */
    Runner.prototype.step = function (n) {
        var nodes = this.root.querySelectorAll('.stk-step');
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].classList.toggle('is-done',   i < n - 1);
            nodes[i].classList.toggle('is-active', i === n - 1);
        }
    };

    Runner.prototype.setSub = function (n, text) {
        var el = this.root.querySelector('.stk-step[data-step="' + (n - 1) + '"] .stk-sub');
        if (el) el.textContent = text;
    };

    Runner.prototype.tick = function () {
        var el = this.root.querySelector('.stk-countdown');
        if (!el) return;
        if (!this.id) { el.textContent = ''; return; }
        var left = Math.max(0, (this.maxPolls - this.count) * (this.pollMs / 1000));
        var m = Math.floor(left / 60), s = Math.round(left % 60);
        el.innerHTML = 'Waiting for confirmation — <b>' + m + ':' + (s < 10 ? '0' : '') + s + '</b> left';
    };

    Runner.prototype.outcome = function (ok, title, sub, extraHtml) {
        var el = this.root.querySelector('.stk-outcome');
        if (!el) return;
        el.innerHTML =
            '<div class="' + (ok ? 'stk-result-ok' : 'stk-result-fail') + '">'
          +   '<div class="stk-result-title">' + (ok ? '&#10003;' : '&#10007;') + ' ' + esc(title) + '</div>'
          +   '<div class="stk-result-sub">' + (sub || '') + '</div>'
          + '</div>' + (extraHtml || '');
    };

    Runner.prototype.stop = function () {
        if (this.timer) { clearInterval(this.timer); this.timer = null; }
        this.id = null;
        this.tick();
    };

    Runner.prototype.fail = function (msg) {
        if (this.done) return;
        this.done = true;
        this.stop();
        var steps = this.root.querySelectorAll('.stk-step');
        for (var i = 0; i < steps.length; i++) steps[i].classList.remove('is-active');
        this.outcome(false, 'Payment not completed', esc(msg));
        if (typeof this.o.onFailed === 'function') this.o.onFailed(msg);
    };

    Runner.prototype.succeed = function (data) {
        if (this.done) return;
        this.done = true;
        this.stop();
        this.step(this.steps.length + 1);   /* mark every step done */
        if (typeof this.o.onCompleted === 'function') this.o.onCompleted(data || {});
    };

    Runner.prototype.start = function () {
        var self = this;
        this.render();
        this.step(1);

        Promise.resolve()
            .then(function () { return self.o.initiate(); })
            .then(function (d) {
                d = d || {};
                if (!d.success) {
                    self.fail(d.message || 'Could not start the payment. Please try again.');
                    return;
                }
                /* Some flows finish immediately (free plans, zero balance) */
                if (!d.checkout_request_id) {
                    self.succeed(d);
                    return;
                }
                self.id = d.checkout_request_id;
                self.count = 0;
                self.step(2);
                self.tick();
                self.timer = setInterval(function () { self.pollOnce(); }, self.pollMs);
            })
            .catch(function () {
                self.fail('Could not reach the payment server. Check your connection and try again.');
            });

        return this;
    };

    Runner.prototype.pollOnce = function () {
        var self = this;
        if (!this.id) return;

        this.count++;
        this.tick();

        /* After ~20s the PIN prompt has been answered one way or another */
        if (this.count === 5) this.step(3);

        if (this.count > this.maxPolls) {
            this.stop();
            this.done = true;
            this.outcome(false, 'Payment timed out',
                'No confirmation after 5 minutes. If the money left your account, it will be reconciled automatically — check back shortly.');
            if (typeof this.o.onFailed === 'function') this.o.onFailed('timeout');
            return;
        }

        Promise.resolve()
            .then(function () { return self.o.poll(self.id); })
            .then(function (d) {
                d = d || {};
                if (d.status === 'processing') {
                    self.step(self.steps.length);
                    if (d.message) self.setSub(self.steps.length, d.message);
                    return;
                }
                if (d.status === 'completed') { self.succeed(d); return; }
                if (d.status === 'failed') {
                    self.fail(d.message || reasonFor(d.result_code, null));
                }
            })
            .catch(function () { /* transient — keep polling */ });
    };

    global.FortunettSTK = {
        run: function (opts) { return new Runner(opts).start(); },
        reasonFor: reasonFor
    };
})(window);
