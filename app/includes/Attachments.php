<?php

declare(strict_types=1);

namespace MCF;

use finfo;

defined('MCF_APP') || exit;

/**
 * Optional attachments:
 *  - allowed extensions come from config, each mapped to ONE MIME type;
 *  - the declared extension AND the type sniffed from the real bytes
 *    (fileinfo) must match: neither the browser's Content-Type nor the
 *    extension alone can be trusted;
 *  - limits on number of files, size per file and total size;
 *  - files are never written anywhere: read from PHP's temporary upload,
 *    attached to the email, discarded at the end of the request.
 *
 * Effective limits are the strictest of config.php and php.ini, so the form
 * never promises more than the server will accept.
 */
final class Attachments
{
    /**
     * File extensions Brevo's API accepts as attachments (its documented
     * list, trimmed to the ones that make sense here). A message carrying
     * any other extension is rejected by Brevo as a whole.
     */
    public const BREVO_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff', 'pdf', 'txt', 'csv',
        'rtf', 'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'zip',
    ];

    /** Room for the text fields and multipart framing on top of the files. */
    private const REQUEST_OVERHEAD = 65536;

    /** @return array{enabled:bool, max_files:int, max_file_bytes:int, max_total_bytes:int, types:array<string,string>} */
    public static function limits(): array
    {
        $cfg = (array) Config::get('attachments');
        $types = [];
        // The list of the configured mail client (Brevo accepts fewer types).
        foreach ((array) ($cfg['types'][Config::get('mail.client')] ?? []) as $ext => $mime) {
            $types[strtolower((string) $ext)] = (string) $mime;
        }

        $maxFiles = min((int) $cfg['max_files'], (int) (ini_get('max_file_uploads') ?: 20));
        $perFile = min((int) $cfg['max_file_bytes'], self::iniBytes('upload_max_filesize'));
        $total = (int) $cfg['max_total_bytes'];
        $postMax = self::iniBytes('post_max_size');
        if ($postMax > 0) {
            $total = min($total, $postMax - self::REQUEST_OVERHEAD - (int) Config::get('limits.post_max_bytes'));
        }
        $perFile = min($perFile, $total);

        $enabled = (bool) $cfg['enabled'] && $types !== [] && $maxFiles > 0 && $perFile > 0;
        return [
            'enabled'         => $enabled,
            'max_files'       => $enabled ? $maxFiles : 0,
            'max_file_bytes'  => $enabled ? $perFile : 0,
            'max_total_bytes' => $enabled ? $total : 0,
            'types'           => $enabled ? $types : [],
        ];
    }

    /** Largest request body accepted by submit.php. */
    public static function maxRequestBytes(): int
    {
        $l = self::limits();
        return (int) Config::get('limits.post_max_bytes') + ($l['enabled'] ? $l['max_total_bytes'] + self::REQUEST_OVERHEAD : 0);
    }

    /**
     * Validates $_FILES['attachments'] (input name="attachments[]"). Sizes
     * in an error travel as bytes ({size}): contact.js writes them in the
     * language shown.
     *
     * @return array{0: list<array{name:string, mime:string, size:int, content:string}>, 1: ?array{code:string, vars?:array}}
     */
    public static function collect(mixed $files): array
    {
        if (!is_array($files) || !is_array($files['name'] ?? null)) {
            return [[], null];
        }
        $l = self::limits();

        // Empty slots are normal for a multiple input left blank.
        $indexes = [];
        foreach ($files['name'] as $i => $_) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $indexes[] = $i;
            }
        }
        if ($indexes === []) {
            return [[], null];
        }
        if (!$l['enabled']) {
            return [[], ['code' => 'att_disabled']];
        }
        if (count($indexes) > $l['max_files']) {
            return [[], ['code' => 'att_too_many', 'vars' => ['max' => $l['max_files']]]];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $out = [];
        $total = 0;
        foreach ($indexes as $i) {
            $name = self::displayName((string) ($files['name'][$i] ?? ''));
            $error = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                return [[], ['code' => 'att_file_too_large', 'vars' => ['name' => $name, 'size' => $l['max_file_bytes']]]];
            }
            $tmp = (string) ($files['tmp_name'][$i] ?? '');
            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                return [[], ['code' => 'att_upload', 'vars' => ['name' => $name]]];
            }

            $size = (int) filesize($tmp);
            if ($size === 0) {
                return [[], ['code' => 'att_empty', 'vars' => ['name' => $name]]];
            }
            if ($size > $l['max_file_bytes']) {
                return [[], ['code' => 'att_file_too_large', 'vars' => ['name' => $name, 'size' => $l['max_file_bytes']]]];
            }
            $total += $size;
            if ($total > $l['max_total_bytes']) {
                return [[], ['code' => 'att_total_too_large', 'vars' => ['size' => $l['max_total_bytes']]]];
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $expected = $l['types'][$ext] ?? null;
            $content = $expected !== null ? file_get_contents($tmp) : false;
            // The extension is forged in a second: it must match what the bytes are.
            if ($content === false || $finfo->buffer($content) !== $expected) {
                return [[], ['code' => 'att_type', 'vars' => ['name' => $name, 'types' => self::extensionList($l['types'])]]];
            }

            $out[] = ['name' => $name, 'mime' => $expected, 'size' => $size, 'content' => $content];
        }
        return [$out, null];
    }

    /**
     * Single-line, length-limited name for the email body and the
     * attachment header (where it is additionally reduced to safe ASCII).
     */
    public static function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = mb_check_encoding($name, 'UTF-8') ? $name : 'file';
        $name = Validation::line($name);
        if (mb_strlen($name) > 100) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 90) . ($ext !== '' ? '.' . $ext : '');
        }
        return $name !== '' && $name !== '.' && $name !== '..' ? $name : 'file';
    }

    /** ASCII-only file name for MIME headers: no quoting or encoding tricks possible. */
    public static function headerName(string $name): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'file';
        $ascii = trim($ascii, '._');
        return $ascii !== '' ? $ascii : 'file';
    }

    /** "JPG/JPEG, PNG, PDF": the extensions of one MIME type together. */
    public static function extensionList(array $types): string
    {
        $byMime = [];
        foreach ($types as $ext => $mime) {
            $byMime[$mime][] = $ext;
        }
        return strtoupper(implode(', ', array_map(static fn (array $exts): string => implode('/', $exts), $byMime)));
    }

    /** "4 MB", "1,5 MB", "12 KB" with the decimal separator of $lang. */
    public static function formatBytes(int $bytes, string $lang): string
    {
        if ($bytes >= 1048576) {
            $mb = rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.');
            return str_replace('.', I18n::t($lang, 'decimal_sep'), $mb) . ' MB';
        }
        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /** php.ini shorthand ("8M", "2G", "512K") to bytes; 0 means unlimited. */
    private static function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key));
        if ($v === '' || $v === '0' || $v === '-1') {
            return PHP_INT_MAX;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
