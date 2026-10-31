/**
 * Matomo (self-hosted, cookieless): loaded
 * only when matomo.url and matomo.site_id are set in config.php, which reach
 * it as data- attributes on its own <script> tag (matomo_tags()).
 *
 * Built here instead of pasting the official snippet: that is an inline
 * script, which the CSP forbids. disableCookies is not optional: without a
 * persistent identifier, and with IP anonymisation on in Matomo, no consent
 * banner is needed, and this service has none.
 */
(function () {
    'use strict';
    var me = document.currentScript;
    var url = me && me.dataset.url;
    var siteId = me && me.dataset.siteId;
    if (!url || !siteId) return;

    var _paq = (window._paq = window._paq || []);
    _paq.push(['disableCookies']);
    _paq.push(['trackPageView']);
    _paq.push(['enableLinkTracking']);
    _paq.push(['setTrackerUrl', url + 'matomo.php']);
    _paq.push(['setSiteId', siteId]);

    var script = document.createElement('script');
    script.async = true;
    script.src = url + 'matomo.js';
    document.head.appendChild(script);
})();
