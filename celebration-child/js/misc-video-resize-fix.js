/*
 * Neutralize the parent theme's forced-16:9 video resize — child-theme edition.
 *
 * The parent's js/misc.js boldthemes_video_resize() forces EVERY iframe/embed
 * (outside .boldPhotoBox) to inline width:100% + height:width*9/16, on ready
 * and on every window resize. That breaks our player embeds (which carry their
 * own inline width/aspect-ratio). Historically we commented 4 lines inside the
 * parent file — the one customization a theme update silently reverts (see
 * "misc.js re-apply" in the migration doc).
 *
 * This shim makes that edit unnecessary:
 *  1. SNAPSHOT every iframe/embed's original inline width/height on native
 *     DOMContentLoaded. jQuery 3.x fires ready callbacks async (a microtask
 *     AFTER DOMContentLoaded), so this runs BEFORE the parent's ready handler
 *     can clobber anything.
 *  2. RESTORE the snapshot from our own ready + resize handlers. We're enqueued
 *     with a dependency on 'celebration-misc', so our handlers bind after the
 *     parent's and run after it every time — same tick, no visible flicker.
 *
 * While the parent file still carries the commented-out lines, the stock code
 * no-ops and this shim restores values that never changed — a harmless no-op.
 * The day a theme update reverts misc.js to stock, this takes over silently.
 *
 * Known edge: iframes injected AFTER DOMContentLoaded aren't snapshotted (we
 * skip elements without one rather than guess). Server-rendered embeds — ours
 * included — are all present at DCL.
 */
(function ($) {
    'use strict';

    var SELECTOR = 'iframe, embed';
    var KEY = 'hsSizeSnap';

    function snapshot() {
        document.querySelectorAll(SELECTOR).forEach(function (el) {
            if (el.dataset[KEY] === undefined) {
                el.dataset[KEY] = (el.style.width || '') + '|' + (el.style.height || '');
            }
        });
    }

    function restore() {
        document.querySelectorAll(SELECTOR).forEach(function (el) {
            var snap = el.dataset[KEY];
            if (snap === undefined) return; // added after DCL — leave it alone
            var parts = snap.split('|');
            el.style.width = parts[0];
            el.style.height = parts[1];
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', snapshot);
    } else {
        snapshot();
    }

    $(function () {
        restore();
        $(window).on('resize', restore); // binds after the parent's handler → runs after it
    });
})(jQuery);
