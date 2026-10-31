<?php

declare(strict_types=1);

namespace MCF;

defined('MCF_APP') || exit;

/**
 * Visitor preferences (language, theme) in technical cookies, and the
 * language in the URL.
 *
 * Every page exists once per language: /it/, /es/privacy.php…
 * An address shows ONE language to everybody, so a link means the same page
 * to whoever opens it. An address without a language (/, /privacy.php, a plain
 * link such as /#webtools) redirects to the visitor's: saved choice, then browser, then
 * English; the browser keeps the #fragment across the redirect.
 *
 * The cookies are written by the SERVER, from ?lang= / ?theme= links, and
 * removed with ?forget_cookie= (privacy page), only when the request comes
 * from this service's own pages (its menu and toggle): a choice made here.
 * From anywhere else (another site, a bookmark, a typed address) nothing is
 * stored: the language is shown from the path (/it/) and the theme from
 * ?view=light|dark, which internal links carry along, so a site can open
 * the form in its own language and theme without leaving a cookie. Why not document.cookie:
 * Safari (ITP) and Brave cap script-written cookies at 7 days whatever the
 * Max-Age, so a "1 year" preference would silently expire after a week.
 * The links work without JavaScript; ui.js calls them in the background and
 * applies the change live.
 *
 * No cookie = no explicit choice: browser language, device theme. The URL
 * wins over the cookie for that page (path language, ?view= theme).
 */
final class Prefs
{
    public const LANG = 'mcf_lang';
    public const THEME = 'mcf_theme';
    private const MAX_AGE = 31536000; // 1 year

    /** Always "__Host-": Secure, this host only, Path=/ (HTTPS is mandatory, bootstrap.php). */
    public static function cookieName(string $base): string
    {
        return '__Host-' . $base;
    }

    public static function lang(): ?string
    {
        $v = $_COOKIE[self::cookieName(self::LANG)] ?? null;
        return I18n::exists($v) ? $v : null;
    }

    public static function theme(): ?string
    {
        $v = $_COOKIE[self::cookieName(self::THEME)] ?? null;
        return $v === 'light' || $v === 'dark' ? $v : null;
    }

    /** The theme shown by ?view= (from a link, never stored), or null. */
    public static function view(): ?string
    {
        $v = $_GET['view'] ?? null;
        return $v === 'light' || $v === 'dark' ? $v : null;
    }

    /** The theme this page shows: ?view= first, then the saved choice; null = the device's. */
    public static function shownTheme(): ?string
    {
        return self::view() ?? self::theme();
    }

    /** "view=dark" for internal links while ?view= is active, '' otherwise; $sep: "?" or "&". */
    public static function viewQuery(string $sep): string
    {
        $v = self::view();
        return $v !== null ? $sep . 'view=' . $v : '';
    }

    /** Saved choice first, then the browser's Accept-Language. */
    public static function resolveLang(): string
    {
        return self::lang() ?? I18n::fromAcceptLanguage($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
    }

    /** The request path below the service's own path (url()), "/" at least. */
    private static function path(): string
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $base = rtrim(url(), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        return $path === '' ? '/' : $path;
    }

    /** The language prefix of the URL (/es/…), or null. */
    public static function pathLang(): ?string
    {
        return preg_match('#^/([a-z]{2,3})(?:/|$)#', self::path(), $m) === 1 && I18n::exists($m[1]) ? $m[1] : null;
    }

    /**
     * <link> elements tying the language versions together: this page's
     * canonical URL, its version in every language and the x-default (the
     * address that redirects). The pages ask not to be indexed (the robots
     * meta in app/views/): these matter only if you remove that line.
     */
    public static function alternateLinks(string $lang, string $page): string
    {
        $origin = Security::selfOrigin();
        if ($origin === null) {
            return '';
        }
        $abs = static fn (string $path): string => $origin . url($path);
        $out = '<link rel="canonical" href="' . e($abs($lang . '/' . $page)) . '">' . "\n";
        foreach (array_keys(I18n::names()) as $code) {
            $out .= '    <link rel="alternate" hreflang="' . e($code) . '" href="' . e($abs($code . '/' . $page)) . '">' . "\n";
        }
        return $out . '    <link rel="alternate" hreflang="x-default" href="' . e($abs($page)) . '">';
    }

    /**
     * Where the site's name leads, in the top bar and on the error pages: the
     * site_url of config.php, else the form in that language.
     */
    public static function brandUrl(string $lang): string
    {
        return (string) Config::get('site_url') ?: self::pageUrl($lang, '');
    }

    /** URL of a page ('' = the form, 'privacy.php') in a language, ?view= kept. */
    public static function pageUrl(string $lang, string $page): string
    {
        return url($lang . '/' . $page) . self::viewQuery('?');
    }

    /**
     * Handles ?lang=, ?theme= and ?forget_cookie=, then makes sure the page is
     * addressed with its language, and returns that language. Must run before
     * any output. $page: '' (the form) or 'privacy.php'.
     */
    public static function handleRequest(string $page): string
    {
        $pathLang = self::pathLang();
        // GET and HEAD alike: a HEAD (curl -I, link checkers)
        // must see the same redirects as the browser. A POST never redirects.
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
            return $pathLang ?? self::resolveLang();
        }
        $lang = $_GET['lang'] ?? null;
        $theme = $_GET['theme'] ?? null;
        $forget = $_GET['forget_cookie'] ?? null;
        $onSite = self::isOnSite();

        // A choice is stored only when made here; from elsewhere the theme
        // becomes ?view= (shown, carried by internal links, never stored).
        // Both may come together (?lang=it&theme=dark): one redirect keeps both.
        $themeChosen = $theme === 'light' || $theme === 'dark';
        if ($themeChosen && $onSite) {
            // Readable by ui.js, to fix pages restored from the back/forward cache.
            self::write(self::THEME, $theme, time() + self::MAX_AGE, false);
        }
        $view = $themeChosen ? ($onSite ? null : $theme) : self::view();
        if (I18n::exists($lang)) {
            // Only a choice made here (the language menu) is remembered. From
            // a link, a bookmark or a typed address the language comes with
            // it (/it/) and is shown, never stored: nothing outside the form
            // can decide it for later visits.
            if ($onSite) {
                // httponly: no script needs it (the page receives the language in its config).
                self::write(self::LANG, $lang, time() + self::MAX_AGE, true);
            }
            // The same page in the chosen language (/es/privacy.php?lang=fr → /fr/privacy.php).
            self::go(url($lang . '/' . $page) . ($view !== null ? '?view=' . $view : ''));
        }
        if ($themeChosen) {
            // Chosen here: the cookie decides from now on, ?view= is dropped.
            self::redirect('', $view);
        }
        if ($forget === 'lang' || $forget === 'theme') {
            // The delete links live on the privacy page only.
            if ($onSite) {
                // Same attributes as when written, expiry in the past.
                self::write($forget === 'lang' ? self::LANG : self::THEME, '', time() - 3600, $forget === 'lang');
                if ($forget === 'lang') {
                    // Drop the language from the path: the next request resolves it
                    // again from the browser (or the fallback).
                    self::go(url($page) . self::viewQuery('?') . '#cookie');
                }
            }
            self::redirect('#cookie', self::view());
        }
        if ($pathLang === null) {
            // No language in the URL: the visitor's. Temporary (302), because it
            // depends on who asks; the query string, if any, is kept.
            header('Vary: Cookie, Accept-Language');
            $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
            self::go(url(self::resolveLang() . '/' . $page) . ($query !== '' && preg_match('/[\x00-\x20]/', $query) !== 1 ? '?' . $query : ''));
        }
        if (self::path() === '/' . $pathLang) {
            self::go(self::pageUrl($pathLang, ''), 301); // a language's home is /es/, not /es
        }
        return $pathLang;
    }

    /**
     * The request started from a page of this service (its menus and links):
     * not from another site, a sibling subdomain included ("same-site" is
     * another origin), nor from a bookmark or the address bar ("none").
     * Browsers without Sec-Fetch-* count as on site, as everywhere in this
     * service.
     */
    private static function isOnSite(): bool
    {
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        return $site === null || $site === 'same-origin';
    }

    private static function write(string $base, string $value, int $expires, bool $httpOnly): void
    {
        setcookie(self::cookieName($base), $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => true,
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]);
    }

    /** Redirect to a URL built by this class (never from request input). */
    private static function go(string $location, int $status = 302): never
    {
        self::keepReferrer();
        header('Cache-Control: no-store');
        header('Location: ' . $location, true, $status);
        exit;
    }

    /**
     * A browser applies a redirect's own Referrer-Policy to the request it
     * then makes, and this service answers no-referrer: across one of these
     * redirects (no language in the address, ?theme= from another site) the
     * visitor's site of origin would be lost, and with it the way back to the
     * page they came from (ui.js). So these redirects, and only these, keep
     * it: the referrer they carry on is that site's origin, which the first
     * request already brought; nothing of this service goes anywhere.
     * public/.htaccess leaves 3xx responses to PHP for the same reason.
     */
    private static function keepReferrer(): void
    {
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    /**
     * Back to the current page without the query string (no open redirect
     * possible), with ?view= when given.
     */
    private static function redirect(string $fragment, ?string $view = null): never
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        // A backslash too: browsers read "/\host" in a Location as "//host".
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\x00-\x20\\\\]/', $path) === 1) {
            $path = '/';
        }
        self::keepReferrer();
        header('Cache-Control: no-store');
        header('Location: ' . $path . ($view !== null ? '?view=' . $view : '') . $fragment, true, 302);
        exit;
    }

    /**
     * <meta name="theme-color">: the browser's own bar (and the Custom Tab an
     * installed app opens for this site) takes the colour of the top bar,
     * --surface in public/assets/css/style.css. With a theme shown, one tag;
     * without, one per device theme. ui.js follows a live theme change.
     */
    public static function themeColorMeta(): string
    {
        $colors = ['light' => '#ffffff', 'dark' => '#1d2026'];
        $theme = self::shownTheme();
        if ($theme !== null) {
            return '<meta name="theme-color" content="' . $colors[$theme] . '">';
        }
        return '<meta name="theme-color" content="' . $colors['light'] . '" media="(prefers-color-scheme: light)">' . "\n"
            . '    <meta name="theme-color" content="' . $colors['dark'] . '" media="(prefers-color-scheme: dark)">';
    }

    /** Attributes for the <html> element. */
    public static function htmlAttributes(string $lang): string
    {
        $theme = self::shownTheme();
        return 'lang="' . e($lang) . '" data-theme-cookie="' . e(self::cookieName(self::THEME)) . '"'
            . ($theme !== null ? ' data-theme="' . $theme . '"' : '');
    }
}
