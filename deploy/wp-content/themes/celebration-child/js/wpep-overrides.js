/*
 * WP Easy Pay — HitchStream customizations, child-theme edition (JS half).
 *
 * Replaces the direct template edits we used to keep inside the plugin
 * (views/frontend/*.php on 4.0.4 — archived in wp-easy-pay-customizations/).
 * Living here means wp-easy-pay can be updated freely; these survive.
 *
 * Does nothing on pages without a .singlepage payment form.
 */
(function () {
    'use strict';

    function init() {
        var form = document.querySelector('.singlepage');
        if (!form) return;

        // ── 1. Account linkage: /payment/?acct=XYZ → hidden field submitted
        //       with the payment (was hardcoded into simple_payment_form.php).
        //       Guarded so it never duplicates a server-rendered acct field
        //       (i.e. harmless while the old template hack is still present).
        var acct = new URLSearchParams(window.location.search).get('acct');
        var holder = form.querySelector('#wpep_personal_information');
        if (acct && holder && !form.querySelector('input[name="acct"]')) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'acct';
            input.value = acct;
            holder.appendChild(input);
        }

        // ── 2. Wording (was template text edits) ──
        var isDonation = !!form.querySelector('[class*="donation"], .donation_goals');
        form.querySelectorAll('.s_ft h2').forEach(function (h) {
            var t = h.textContent.trim();
            if (t === 'Basic Info') h.textContent = isDonation ? 'Info' : 'Your Info';
            if (t === 'Payment Successful') h.textContent = 'Payment Successful!';
        });
        form.querySelectorAll('.orderCompleted ~ p, .wizard-fieldset p').forEach(function (p) {
            if (p.textContent.trim() === 'Thank you for your purchase.') {
                p.textContent = 'Thank you for your payment';
            }
        });

        // ── 3. No stray " USD" on the pay button when the form has no default
        //       amount (was a template conditional) ──
        form.querySelectorAll('button small.display').forEach(function (s) {
            if (s.textContent.trim() === 'USD') s.textContent = '';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
