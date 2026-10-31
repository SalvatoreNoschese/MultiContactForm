<?php

declare(strict_types=1);

namespace MCF;

use Normalizer;

defined('MCF_APP') || exit;

/**
 * Server-side validation and normalisation of a contact submission.
 *
 * Only known keys are read; everything is checked against the server-side
 * project configuration. Client-side checks are a convenience, never trusted.
 */
final class Validation
{
    /**
     * @param array<string, mixed> $input raw request data ($_POST)
     * @return array{0: array<string, mixed>, 1: array<string, array{code:string, vars?:array}>}
     *         [clean data, field errors]
     */
    public static function validate(array $input): array
    {
        $limits = (array) Config::get('limits');
        $errors = [];
        $data = [];

        // Project and reason: must match the server-side whitelist.
        $project = self::line(self::str($input, 'project'));
        if (Projects::get($project) === null) {
            $errors['project'] = ['code' => $project === '' ? 'required' : 'project_invalid'];
        }
        $data['project'] = $project;

        $reason = self::line(self::str($input, 'reason'));
        if (!isset($errors['project']) && Projects::reason($project, $reason) === null) {
            $errors['reason'] = ['code' => $reason === '' ? 'required' : 'reason_invalid'];
        }
        $data['reason'] = $reason;

        // Name: single line, no control / bidi characters.
        $name = self::line(self::str($input, 'name'));
        $len = mb_strlen($name);
        if ($len === 0) {
            $errors['name'] = ['code' => 'required'];
        } elseif ($len < $limits['name_min'] || $len > $limits['name_max']) {
            $errors['name'] = ['code' => 'name_length', 'vars' => ['min' => $limits['name_min'], 'max' => $limits['name_max']]];
        }
        $data['name'] = $name;

        // Email: strict syntax; also guarantees no CR/LF (header safety).
        $email = trim(self::str($input, 'email'));
        if ($email === '') {
            $errors['email'] = ['code' => 'required'];
        } elseif (strlen($email) > $limits['email_max']
            || preg_match('/[\x00-\x20\x7f<>",;]/', $email) === 1
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors['email'] = ['code' => 'email_invalid'];
        }
        $data['email'] = $email;

        // Message: multi-line text, control characters stripped.
        $message = self::text(self::str($input, 'message'));
        $len = mb_strlen($message);
        if ($len === 0) {
            $errors['message'] = ['code' => 'required'];
        } elseif ($len < $limits['message_min']) {
            $errors['message'] = ['code' => 'message_short', 'vars' => ['min' => $limits['message_min']]];
        } elseif ($len > $limits['message_max']) {
            $errors['message'] = ['code' => 'message_long', 'vars' => ['max' => $limits['message_max']]];
        }
        $data['message'] = $message;

        // Conditional fields: only those defined for this project/reason are read.
        $data['fields'] = [];
        if (!isset($errors['project']) && !isset($errors['reason'])) {
            foreach (Projects::fieldsFor($project, $reason) as $fname => $def) {
                [$value, $error] = self::field(self::str($input, $fname), $def);
                if ($error !== null) {
                    $errors[$fname] = $error;
                }
                if ($value !== '') {
                    $data['fields'][$fname] = $value;
                }
            }
        }

        if (self::str($input, 'privacy') !== '1') {
            $errors['privacy'] = ['code' => 'privacy'];
        }

        $data['lang'] = I18n::normalize(self::str($input, 'lang'));

        return [$data, $errors];
    }

    /** @return array{0:string, 1:?array} */
    private static function field(string $raw, array $def): array
    {
        $max = (int) ($def['maxlength'] ?? 200);
        $value = self::line($raw);

        // Extra fields are always optional.
        if ($value === '') {
            return ['', null];
        }
        if (mb_strlen($value) > $max) {
            return ['', ['code' => 'too_long']];
        }

        switch ($def['type']) {
            case 'select':
                return Projects::isOption($def, $value) ? [$value, null] : ['', ['code' => 'option_invalid']];

            case 'url':
                $url = self::normalizeUrl($value, $def['hosts']);
                return $url !== null
                    ? [$url, null]
                    : ['', ['code' => 'url_invalid', 'vars' => ['hosts' => implode(', ', $def['hosts'])]]];

            case 'text':
                return [$value, null];

            case 'consent':
                // A ticked checkbox sends "1"; anything else is no consent.
                return [$value === '1' ? '1' : '', null];
        }
        return ['', ['code' => 'invalid']];
    }

    /**
     * Accepts only https URLs on whitelisted hosts, without credentials.
     * The fragment is always dropped: it may hold a private key or token.
     */
    public static function normalizeUrl(string $value, array $hosts): ?string
    {
        $value = explode('#', $value, 2)[0];
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $p = parse_url($value);
        if (!is_array($p)
            || strtolower($p['scheme'] ?? '') !== 'https'
            || isset($p['user']) || isset($p['pass'])
            || (isset($p['port']) && (int) $p['port'] !== 443)
            || !in_array(strtolower($p['host'] ?? ''), array_map('strtolower', $hosts), true)
        ) {
            return null;
        }
        $url = 'https://' . strtolower($p['host']) . ($p['path'] ?? '/');
        if (isset($p['query']) && $p['query'] !== '') {
            $url .= '?' . $p['query'];
        }
        return preg_match('/[\s<>"\\\\]/', $url) === 1 ? null : $url;
    }

    private static function str(array $input, string $key): string
    {
        $v = $input[$key] ?? '';
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            return '';
        }
        if (class_exists('Normalizer')) {
            $v = Normalizer::normalize($v, Normalizer::FORM_C) ?: $v;
        }
        return $v;
    }

    /** Single-line value: all control, line-separator and bidi-override characters removed. */
    public static function line(string $v): string
    {
        $v = preg_replace('/[\p{Cc}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', ' ', $v) ?? '';
        return trim(preg_replace('/\s{2,}/u', ' ', $v) ?? '');
    }

    /** Multi-line value: newlines and tabs kept, other control characters removed. */
    public static function text(string $v): string
    {
        $v = str_replace(["\r\n", "\r"], "\n", $v);
        $v = preg_replace('/[^\P{Cc}\n\t]|[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $v) ?? '';
        $v = preg_replace('/\n{4,}/', "\n\n\n", $v) ?? '';
        return trim($v);
    }
}
