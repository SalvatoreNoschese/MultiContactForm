/*
 * Shared page chrome (form and privacy page):
 *  - language drop-down and theme toggle are ?lang= / ?theme= links: the
 *    SERVER writes the cookie (see Prefs.php). Here they are called in the
 *    background and applied live, without reloading; the toggle also ends
 *    this visit's theme (theme.js);
 *  - links to your other sites carry the theme on screen when followed;
 *  - the privacy cookie table follows a theme change live;
 *  - obfuscated email addresses are rebuilt;
 *  - the way back: how many history entries this tab has spent here since
 *    it arrived from another site (contact.js steps back by that many).
 */
(() => {
    'use strict';

    const root = document.documentElement;

    // ── Way back to the page of origin ─────────────────────────────────────
    // JavaScript cannot read the addresses of earlier history entries, so the
    // success page cannot look for the project's page in them: it counts
    // instead. Each entry's history.state holds its depth (1 = the first page
    // here) and the origin it came from; the browser keeps that per entry,
    // through back/forward and reloads. A page reached from another site
    // starts at 1, a click on a link of ours adds 1 (handed over through
    // sessionStorage: our own Referrer-Policy is no-referrer, so the next
    // page cannot tell), anything else (a bookmark, a typed address) is 0.
    const NAV_KEY = 'mcf.nav';
    (function trackDepth() {
        const st = history.state && typeof history.state === 'object' ? history.state : {};
        let pending = null;
        try {
            pending = JSON.parse(sessionStorage.getItem(NAV_KEY) || 'null');
            sessionStorage.removeItem(NAV_KEY);
        } catch (e) { /* no storage: no depth */ }
        if (typeof st.mcfDepth === 'number') return;
        let ref = '';
        try { ref = document.referrer ? new URL(document.referrer).origin : ''; } catch (e) { /* none */ }
        let depth = 0;
        let from = '';
        if (pending && pending.depth > 0 && typeof pending.from === 'string' && Date.now() - pending.at < 10000) {
            depth = pending.depth + 1;
            from = pending.from;
        } else if (ref && ref !== location.origin) {
            depth = 1;
            from = ref;
        }
        try { history.replaceState(Object.assign({}, st, { mcfDepth: depth, mcfFrom: from }), ''); } catch (e) { /* ignore */ }
    })();
    // After the handlers of the links themselves: the language and theme links
    // cancel the navigation (they apply in place), and only a real one counts.
    document.addEventListener('click', (ev) => {
        const a = ev.target.closest && ev.target.closest('a[href]');
        if (!a || ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
        if (a.target && a.target !== '_self') return;
        let u;
        try { u = new URL(a.href, location.href); } catch (e) { return; }
        const st = history.state || {};
        if (u.origin !== location.origin || !(st.mcfDepth > 0)) return;
        try {
            sessionStorage.setItem(NAV_KEY, JSON.stringify({ depth: st.mcfDepth, from: st.mcfFrom, at: Date.now() }));
        } catch (e) { /* no storage: the next page starts at 0 */ }
    });

    /** Calls a ?lang=/?theme= link in the background (the server sets the cookie). */
    function callPref(href) {
        return fetch(href, { credentials: 'same-origin', redirect: 'manual', cache: 'no-store' });
    }

    // ── Theme ──────────────────────────────────────────────────────────────
    // Shown: this visit's (?view=, kept in sessionStorage by theme.js) >
    // the saved choice (cookie, written by the server from the toggle) > the
    // device's (no data-theme: the CSS follows it, live).

    const VISIT_KEY = 'mcf.view';
    const deviceLight = window.matchMedia('(prefers-color-scheme: light)');
    const valid = (v) => v === 'light' || v === 'dark';

    /** The saved choice or this visit's theme, as theme.js resolves it; null = the device's. */
    function chosenTheme() {
        let visit = null;
        try { visit = sessionStorage.getItem(VISIT_KEY); } catch (e) { /* no storage */ }
        if (valid(visit)) return visit;
        const name = root.dataset.themeCookie || '__Host-mcf_theme';
        const m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=(light|dark)'));
        return m ? m[1] : null;
    }

    /** The theme on screen, the device's included. */
    function shownTheme() {
        return valid(root.dataset.theme) ? root.dataset.theme : (deviceLight.matches ? 'light' : 'dark');
    }

    /**
     * One theme-color, the top bar's colour as drawn (read from the CSS, so
     * it is written in one place only). Also run once at start: Chrome picks
     * readable text for a Custom Tab's title bar (the one an installed app
     * opens for this site) only after the page updates the tag, not from
     * the tag in the HTML.
     */
    function syncThemeColor() {
        const bar = document.querySelector('.topbar');
        const metas = document.querySelectorAll('meta[name="theme-color"]');
        metas.forEach((meta, i) => {
            if (i > 0) { meta.remove(); return; }
            meta.removeAttribute('media');
            if (bar) meta.setAttribute('content', getComputedStyle(bar).backgroundColor);
        });
    }
    syncThemeColor();
    // Following the device: its theme may change while the page is open.
    deviceLight.addEventListener('change', syncThemeColor);

    /** A theme chosen with the toggle: the CSS shows the other toggle. */
    function applyTheme(theme) {
        root.dataset.theme = theme;
        syncThemeColor();
        drawVisit(); // the toggle has ended this visit's theme

        // Privacy page: the theme cookie row follows the change.
        document.querySelectorAll('[data-cookie-status="theme"]').forEach((box) => {
            const present = document.createElement('span');
            present.className = 'state-present';
            present.textContent = box.dataset.present;
            const value = document.createElement('code');
            value.textContent = theme;
            const del = document.createElement('a');
            del.href = '?forget_cookie=theme';
            del.textContent = box.dataset.delete;
            box.replaceChildren(present, ' ', value, ' · ', del);
        });
    }

    /**
     * Privacy page: this visit's theme (sessionStorage), which only the
     * browser knows about. Its state, and a link that deletes it: the page
     * then shows the saved choice or the device's theme.
     */
    function drawVisit() {
        document.querySelectorAll('[data-storage-status="visit"]').forEach((box) => {
            let visit = null;
            try { visit = sessionStorage.getItem(VISIT_KEY); } catch (e) { /* no storage */ }
            if (!valid(visit)) {
                const absent = document.createElement('span');
                absent.className = 'state-absent';
                absent.textContent = box.dataset.absent;
                box.replaceChildren(absent);
                return;
            }
            const present = document.createElement('span');
            present.className = 'state-present';
            present.textContent = box.dataset.present;
            const value = document.createElement('code');
            value.textContent = visit;
            const del = document.createElement('a');
            del.href = '#cookie';
            del.textContent = box.dataset.delete;
            del.addEventListener('click', (ev) => {
                ev.preventDefault();
                try { sessionStorage.removeItem(VISIT_KEY); } catch (e) { /* no storage */ }
                const theme = chosenTheme();
                if (theme) {
                    root.dataset.theme = theme;
                } else {
                    delete root.dataset.theme;
                }
                syncThemeColor();
                drawVisit();
            });
            box.replaceChildren(present, ' ', value, ' · ', del);
        });
    }
    drawVisit();

    document.querySelectorAll('a.theme-toggle').forEach((link) => {
        link.addEventListener('click', (ev) => {
            const theme = new URL(link.href, location.href).searchParams.get('theme');
            if (!valid(theme)) return;
            // Only the toggle saves a choice, and this visit's theme gives way
            // to it (also when the link is followed without JavaScript's help).
            try { sessionStorage.removeItem(VISIT_KEY); } catch (e) { /* no storage */ }
            if (typeof fetch !== 'function') return;
            ev.preventDefault();
            const href = link.href;
            callPref(href)
                .then(() => applyTheme(theme))
                .catch(() => { location.href = href; });
        });
    });

    // Back/forward cache: a page drawn before a theme change comes back with
    // the old theme. Storage and cookie are always current: re-align.
    window.addEventListener('pageshow', (ev) => {
        if (!ev.persisted) return;
        const theme = chosenTheme();
        if (theme === (valid(root.dataset.theme) ? root.dataset.theme : null)) return;
        if (theme) {
            root.dataset.theme = theme;
        } else {
            delete root.dataset.theme;
        }
        syncThemeColor();
    });

    // Links to your other sites that understand a theme parameter
    // (data-theme-param, see Prefs::link): the parameter is added only when
    // the link is followed (middle and Ctrl/Cmd+click too, for a new tab),
    // with the theme on screen, so the other site opens the same. Right
    // after, the link is clean again: the address read by the navigation is
    // already taken.
    function themeLink(ev) {
        const a = ev.target.closest && ev.target.closest('a[data-theme-param]');
        if (!a || !a.dataset.themeParam) return;
        const clean = a.getAttribute('href');
        let u;
        try { u = new URL(a.href); } catch (e) { return; }
        u.searchParams.set(a.dataset.themeParam, shownTheme());
        a.href = u.href;
        setTimeout(() => { a.setAttribute('href', clean); }, 0);
    }
    document.addEventListener('click', themeLink, true);
    document.addEventListener('auxclick', themeLink, true);

    // ── Language drop-down (<details>) ─────────────────────────────────────

    const menu = document.querySelector('details.lang-menu');
    if (menu) {
        const summary = menu.querySelector('summary');

        document.addEventListener('click', (ev) => {
            if (menu.open && !menu.contains(ev.target)) menu.open = false;
        });
        document.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape' && menu.open) {
                menu.open = false;
                summary.focus();
            }
        });

        menu.querySelectorAll('a[data-lang]').forEach((link) => {
            link.addEventListener('click', (ev) => {
                // Only the contact form switches in place (keeping what was
                // typed); elsewhere the link reloads the page in the new language.
                if (document.body.dataset.liveLang !== 'true' || typeof fetch !== 'function') return;
                ev.preventDefault();
                const lang = link.dataset.lang;
                const href = link.href;
                menu.open = false;
                summary.focus();
                const from = document.documentElement.lang;
                callPref(href)
                    .then(() => {
                        markLanguage(lang);
                        relangPage(from, lang);
                        document.dispatchEvent(new CustomEvent('contact:language', { detail: { lang } }));
                    })
                    .catch(() => { location.href = href; });
            });
        });

        /** /it/ → /fr/ (and /it/privacy.php → /fr/privacy.php) in a same-site URL. */
        function relang(href, from, to) {
            const u = new URL(href, location.href);
            const m = u.origin === location.origin && u.pathname.match(new RegExp('^(.*)/' + from + '/(privacy\\.php)?$'));
            if (!m) return href;
            u.pathname = m[1] + '/' + to + '/' + (m[2] || '');
            return u.href;
        }

        /** The address and the links follow a live language switch, as a reload would. */
        function relangPage(from, to) {
            try { history.replaceState(history.state, '', relang(location.href, from, to)); } catch (e) { /* ignore */ }
            document.querySelectorAll('a[href]').forEach((a) => {
                const next = relang(a.href, from, to);
                if (next !== a.href) a.href = next;
            });
        }

        function markLanguage(lang) {
            let name = lang;
            menu.querySelectorAll('a[data-lang]').forEach((a) => {
                if (a.dataset.lang === lang) {
                    a.setAttribute('aria-current', 'true');
                    name = a.textContent.trim();
                } else {
                    a.removeAttribute('aria-current');
                }
            });
            // Flag copied from the chosen option (a language may have none).
            const oldFlag = summary.querySelector('img.flag');
            const newFlag = menu.querySelector('a[data-lang="' + lang + '"] img.flag');
            if (oldFlag) oldFlag.remove();
            if (newFlag) summary.prepend(newFlag.cloneNode());
            summary.querySelector('[aria-hidden]').textContent = lang.toUpperCase();
            const sr = summary.querySelector('.sr-only');
            sr.lastChild.textContent = ': ' + name;
        }
    }

    // ── Obfuscated email (reversed hex of the UTF-8 bytes) ─────────────────

    function unscramble(str) {
        const hex = str.split('').reverse().join('');
        const pairs = hex.match(/.{1,2}/g) || [];
        return new TextDecoder('utf-8').decode(new Uint8Array(pairs.map((b) => parseInt(b, 16))));
    }

    document.querySelectorAll('span[data-obf-m]').forEach((host) => {
        try {
            const address = unscramble(host.dataset.obfM);
            if (!/^[^\s@<>"]+@[^\s@<>"]+$/.test(address)) return;
            const link = document.createElement('a');
            link.href = 'mailto:' + address;
            link.textContent = address;
            host.replaceChildren(link);
        } catch (e) { /* leave it empty */ }
    });
})();
