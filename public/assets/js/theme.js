/*
 * The theme for this visit, applied before the page is drawn: loaded in the
 * <head> without defer, ahead of the stylesheet (the CSP allows no inline
 * script), so there is no flash of the wrong colours.
 *
 * ?view=light|dark in the address (a link from another site, carrying the
 * theme that site shows) holds for this visit only: it goes to
 * sessionStorage (this tab, until it is closed) and leaves the address
 * without a reload, other parameters and the #fragment kept. It never
 * becomes a saved choice: only the theme toggle saves one (the cookie,
 * written by the server, see Prefs.php). Any other value of ?view= is
 * ignored.
 *
 * Theme shown: this visit's > the saved choice > the device's. With neither
 * of the first two, no data-theme: the CSS follows prefers-color-scheme, also
 * when it changes while the page is open. ui.js resolves it the same way
 * (theme toggle, back/forward cache).
 */
(() => {
    'use strict';

    const KEY = 'mcf.view';
    const root = document.documentElement;
    const valid = (v) => v === 'light' || v === 'dark';
    let theme = null;

    try {
        const u = new URL(location.href);
        const view = u.searchParams.get('view');
        if (valid(view)) {
            theme = view;
            try { sessionStorage.setItem(KEY, view); } catch (e) { /* no storage: this page only */ }
            u.searchParams.delete('view');
            history.replaceState(history.state, '', u.pathname + u.search + u.hash);
        }
    } catch (e) { /* leave the address as it is */ }

    if (!valid(theme)) {
        try { theme = sessionStorage.getItem(KEY); } catch (e) { theme = null; }
    }
    if (!valid(theme)) {
        const name = root.dataset.themeCookie || '__Host-mcf_theme';
        const m = String(document.cookie).match(new RegExp('(?:^|;\\s*)' + name + '=(light|dark)'));
        theme = m ? m[1] : null;
    }
    if (valid(theme)) {
        root.dataset.theme = theme;
    } else {
        delete root.dataset.theme;
    }
})();
