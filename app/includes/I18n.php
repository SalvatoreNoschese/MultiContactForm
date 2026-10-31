<?php

declare(strict_types=1);

namespace MCF;

use RuntimeException;

defined('MCF_APP') || exit;

/**
 * Languages, discovered from app/lang/<code>.php: dropping app/lang/es.php in place
 * adds Spanish to the menu, to browser detection and to every text of the
 * service. No list to keep in sync anywhere.
 *
 * Each file returns one flat array, key => text: 'language.name' (the name in
 * the menu), the interface strings, and dotted families resolved server-side
 * (mail.*, reason.*, privacy.*, …). Numbered keys (privacy.rights.1, .2, …)
 * are read as lists by items(). English (app/lang/en.php) is the fallback
 * and must be complete; other files may be partial: missing keys are shown
 * in English.
 */
final class I18n
{
    public const FALLBACK = 'en';
    private const CODE = '/^[a-z]{2,3}$/';

    /** @var array<string, array{name:string, strings:array<string,string>}>|null */
    private static ?array $langs = null;

    /** @return array<string, array{name:string, strings:array<string,string>}> */
    private static function all(): array
    {
        if (self::$langs !== null) {
            return self::$langs;
        }
        $langs = [];
        foreach (glob(MCF_APP_DIR . '/lang/*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            if (preg_match(self::CODE, $code) !== 1) {
                continue;
            }
            $data = require $file;
            if (!is_array($data) || !is_string($data['language.name'] ?? null)) {
                throw new RuntimeException("app/lang/$code.php must return ['language.name' => …, key => text, …].");
            }
            $langs[$code] = [
                'name'    => $data['language.name'],
                'strings' => array_filter($data, 'is_string'),
            ];
        }
        if (!isset($langs[self::FALLBACK])) {
            throw new RuntimeException('app/lang/' . self::FALLBACK . '.php (fallback language) is missing.');
        }
        // Alphabetical by code: a stable menu order.
        ksort($langs);
        return self::$langs = $langs;
    }

    /** @return array<string, string> code => name, for the language menu */
    public static function names(): array
    {
        return array_map(static fn (array $l): string => $l['name'], self::all());
    }

    public static function exists(mixed $lang): bool
    {
        return is_string($lang) && isset(self::all()[$lang]);
    }

    /** Any input to a supported language code (unknown → fallback). */
    public static function normalize(mixed $lang): string
    {
        return self::exists($lang) ? $lang : self::FALLBACK;
    }

    /**
     * First supported language in the browser's Accept-Language, honouring
     * q-values ("es-ES,es;q=0.9,it;q=0.8" → es if available, else it, …).
     */
    public static function fromAcceptLanguage(?string $header): string
    {
        $prefs = [];
        foreach (explode(',', (string) $header) as $i => $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(trim($bits[0]));
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (preg_match('/^\s*q=([01](?:\.\d{1,3})?)\s*$/', $param, $m) === 1) {
                    $q = (float) $m[1];
                }
            }
            if ($tag !== '' && $q > 0) {
                $prefs[] = [$q, -$i, $tag];
            }
        }
        rsort($prefs);
        foreach ($prefs as [, , $tag]) {
            $primary = explode('-', $tag)[0];
            if (self::exists($primary)) {
                return $primary;
            }
        }
        return self::FALLBACK;
    }

    /** Text for $key in $lang, else in English, else the key itself. */
    public static function t(string $lang, string $key, array $vars = []): string
    {
        $text = self::find($lang, $key) ?? $key;
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        return $text;
    }

    /** Text for the first key found (in $lang, then in English), or null. */
    public static function find(string $lang, string ...$keys): ?string
    {
        $all = self::all();
        foreach ([$lang, self::FALLBACK] as $code) {
            foreach ($keys as $key) {
                if (isset($all[$code]['strings'][$key])) {
                    return $all[$code]['strings'][$key];
                }
            }
        }
        return null;
    }

    /**
     * Interface strings of every language, for live switching in the browser.
     * Dotted keys (project texts, mail, …) are resolved server-side instead.
     *
     * @return array<string, array<string, string>>
     */
    public static function clientStrings(): array
    {
        return array_map(
            static fn (array $l): array => array_filter($l['strings'], static fn (string $k): bool => !str_contains($k, '.'), ARRAY_FILTER_USE_KEY),
            self::all()
        );
    }

    /**
     * The ALTCHA widget's strings per language (its own key names), from the
     * altcha.* keys: contact.js registers them in $altcha.i18n, so the widget
     * follows a language switch too.
     */
    public static function altchaStrings(): array
    {
        $map = [
            'ariaLinkLabel' => 'aria_link_label', 'cancel' => 'cancel', 'enterCode' => 'enter_code',
            'enterCodeAria' => 'enter_code_aria', 'enterCodeFromImage' => 'enter_code_from_image',
            'error' => 'error', 'expired' => 'expired', 'footer' => 'footer',
            'getAudioChallenge' => 'get_audio_challenge', 'label' => 'label', 'loading' => 'loading',
            'reload' => 'reload', 'verificationRequired' => 'verification_required',
            'verified' => 'verified', 'verifying' => 'verifying', 'verify' => 'verify', 'waitAlert' => 'wait_alert',
        ];
        $out = [];
        foreach (array_keys(self::all()) as $lang) {
            foreach ($map as $widgetKey => $key) {
                $out[$lang][$widgetKey] = self::t($lang, 'altcha.' . $key);
            }
        }
        return $out;
    }

    /**
     * "I have read the [privacy notice]" → ['I have read the ', 'privacy
     * notice', '']: the text in brackets becomes a link in the view, and
     * contact.js does the same on a language switch. Without brackets the
     * whole text is the link.
     *
     * @return array{0:string, 1:string, 2:string}
     */
    public static function linkParts(string $text): array
    {
        return preg_match('/^(.*?)\[(.+?)\](.*)$/su', $text, $m) === 1 ? [$m[1], $m[2], $m[3]] : ['', $text, ''];
    }

    /**
     * The numbered texts under a prefix ('privacy.rights' → privacy.rights.1,
     * .2, …): as many as English has, each in $lang or else in English.
     *
     * @return list<string>
     */
    public static function items(string $lang, string $prefix): array
    {
        $en = self::all()[self::FALLBACK]['strings'];
        $items = [];
        for ($i = 1; isset($en[$prefix . '.' . $i]); $i++) {
            $items[] = self::t($lang, $prefix . '.' . $i);
        }
        return $items;
    }
}
