/*
 * Shared page chrome (form and privacy page):
 *  - language drop-down and theme toggle are ?lang= / ?theme= links: the
 *    SERVER writes the cookie (see Prefs.php). Here they are called in the
 *    background and applied live, without reloading;
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

    let themeLinks = Array.from(document.querySelectorAll('a.theme-toggle'));

    /** ?view= out of a same-site URL: a theme chosen here replaces it. */
    function dropView(href) {
        const u = new URL(href, location.href);
        if (u.origin !== location.origin || !u.searchParams.has('view')) return href;
        u.searchParams.delete('view');
        return u.href;
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

    function applyTheme(theme) {
        root.dataset.theme = theme;
        syncThemeColor();

        // A choice made here: the cookie decides from now on, so ?view= leaves
        // the address and the links (a reload would otherwise bring it back).
        try { history.replaceState(history.state, '', dropView(location.href)); } catch (e) { /* ignore */ }
        document.querySelectorAll('a[href]').forEach((a) => {
            const next = dropView(a.href);
            if (next !== a.href) a.href = next;
        });

        // One toggle only, now pointing to the opposite theme.
        const opposite = theme === 'dark' ? 'light' : 'dark';
        themeLinks.forEach((link, i) => {
            if (i > 0) {
                link.remove();
                return;
            }
            link.classList.remove('theme-switch--to-dark', 'theme-switch--to-light');
            link.href = '?theme=' + opposite;
        });
        themeLinks = themeLinks.slice(0, 1);

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

    themeLinks.forEach((link) => {
        link.addEventListener('click', (ev) => {
            const theme = new URL(link.href, location.href).searchParams.get('theme');
            if ((theme !== 'light' && theme !== 'dark') || typeof fetch !== 'function') return;
            ev.preventDefault();
            const href = link.href;
            callPref(href)
                .then(() => applyTheme(theme))
                .catch(() => { location.href = href; });
        });
    });

    // Back/forward cache: a page drawn before a theme change comes back with
    // the old theme. The cookie is always current, so re-align from it,
    // unless the address shows a theme of its own (?view=).
    window.addEventListener('pageshow', (ev) => {
        if (!ev.persisted || new URL(location.href).searchParams.has('view')) return;
        const name = root.dataset.themeCookie || '__Host-mcf_theme';
        const m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=(light|dark)'));
        if (m && m[1] !== root.dataset.theme) {
            root.dataset.theme = m[1];
            location.reload();
        }
    });

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
