/* KRECK theme for Gibbon — shell behaviour.
   Loaded deferred on every normal/login page (themes/Kreck/js/common.js).
   1) Debrand the footer: remove Gibbon's footer block + wordmark, inject
      "© 2026 Kreck Micro Learn".
   2) Contrast guard: ensure text never disappears by giving any element whose
      text has too little contrast with its effective background a contrasting
      colour (dark or white). Careful around Alpine.js / htmx. No CSP in Gibbon,
      so inline styles set via the DOM are unrestricted. */
(function () {
    'use strict';
    document.documentElement.classList.add('kreck-theme');

    // ---------------------------------------------------------------- Footer.
    function debrandFooter() {
        // Remove the Gibbon wordmark image(s).
        document.querySelectorAll('img[src*="gibbon-white"], img[alt^="Logo text"]').forEach(function (img) {
            img.remove();
        });
        // Find the core footer block via its gibbonedu.org link, else by class.
        var link = document.querySelector('#wrapOuter a[href*="gibbonedu.org"]');
        var footer = link
            ? link.closest('div.border-t, div[class*="border-t"]')
            : document.querySelector('#wrapOuter .border-t.border-gray-300.leading-normal');
        // Inject the KRECK footer once, then REMOVE the Gibbon footer entirely
        // (so no gibbonedu.org links remain anywhere in the DOM).
        if (!document.querySelector('.kreck-footer')) {
            var f = document.createElement('div');
            f.className = 'kreck-footer';
            f.textContent = '© 2026 Kreck Micro Learn';
            if (footer && footer.parentNode) footer.parentNode.insertBefore(f, footer.nextSibling);
            else (document.getElementById('wrapOuter') || document.body).appendChild(f);
        }
        if (footer) footer.remove();
    }

    // --------------------------------------------------------- Contrast guard.
    var DARK = '#0f1e2e', LIGHT = '#ffffff';
    var THRESHOLD = 2.3; // only fix genuinely low-contrast text; leave designed accents (~3:1) alone.

    function parseColor(c) {
        if (!c) return null;
        var m = c.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/);
        if (!m) return null;
        return { r: +m[1], g: +m[2], b: +m[3], a: m[4] === undefined ? 1 : +m[4] };
    }
    function lum(c) {
        var a = [c.r, c.g, c.b].map(function (v) {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
    }
    function ratio(l1, l2) {
        var hi = Math.max(l1, l2), lo = Math.min(l1, l2);
        return (hi + 0.05) / (lo + 0.05);
    }
    function effectiveBg(el) {
        var node = el;
        while (node && node.nodeType === 1) {
            var cs = getComputedStyle(node);
            // A gradient/image background can't be reduced to one colour — bail
            // out ("unknown") so we never repaint text over the slate app-bar etc.
            if (cs.backgroundImage && cs.backgroundImage !== 'none') return null;
            var bg = parseColor(cs.backgroundColor);
            if (bg && bg.a > 0.1) return bg;
            node = node.parentElement;
        }
        return { r: 255, g: 255, b: 255, a: 1 };
    }
    var SKIP = { SCRIPT: 1, STYLE: 1, svg: 1, SVG: 1, PATH: 1, IMG: 1, INPUT: 1, SELECT: 1, TEXTAREA: 1, OPTION: 1, BUTTON: 0, BR: 1, HR: 1, CANVAS: 1, VIDEO: 1, IFRAME: 1, NOSCRIPT: 1 };
    function alpineOwned(el) {
        try { return el.closest('[x-show],[x-cloak],[x-if]') !== null; } catch (e) { return false; }
    }
    function hasOwnText(el) {
        for (var i = 0; i < el.childNodes.length; i++) {
            var n = el.childNodes[i];
            if (n.nodeType === 3 && n.textContent.trim() !== '') return true;
        }
        return false;
    }
    var DARK_L = lum({ r: 15, g: 30, b: 46 }), LIGHT_L = lum({ r: 255, g: 255, b: 255 });
    function fixEl(el) {
        if (!el || el.nodeType !== 1 || SKIP[el.tagName]) return;
        if (el.dataset.kcDone === '1') return;
        if (!hasOwnText(el)) return;
        if (alpineOwned(el)) { el.dataset.kcDone = '1'; return; }
        var cs = getComputedStyle(el);
        var fg = parseColor(cs.color);
        var bg = effectiveBg(el);
        if (fg && bg) { // bg null = gradient/image area → leave it to the theme CSS
            if (ratio(lum(fg), lum(bg)) < THRESHOLD) {
                var want = ratio(DARK_L, lum(bg)) >= ratio(LIGHT_L, lum(bg)) ? DARK : LIGHT;
                el.style.setProperty('color', want, 'important');
            }
        }
        el.dataset.kcDone = '1';
    }

    var observer = null, pending = false;
    function pass() {
        if (observer) observer.disconnect();
        try {
            debrandFooter();
            var all = document.body.getElementsByTagName('*');
            // snapshot length (live collection) and iterate
            for (var i = 0, n = all.length; i < n; i++) fixEl(all[i]);
        } catch (e) { /* never break the page */ }
        if (observer) observer.observe(document.body, { childList: true, subtree: true });
    }
    function schedule() {
        if (pending) return;
        pending = true;
        setTimeout(function () { pending = false; pass(); }, 150);
    }
    function init() {
        pass();
        observer = new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                if (muts[i].addedNodes && muts[i].addedNodes.length) { schedule(); return; }
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
        document.body.addEventListener('htmx:afterSettle', schedule);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
