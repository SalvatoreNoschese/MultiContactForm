<?php

declare(strict_types=1);

namespace MCF;

defined('MCF_APP') || exit;

/**
 * Loads and validates projects.php (REQUIRED, next to config.php).
 *
 * A project is a name, its site's address and a list of reasons. Reasons are
 * built-in ids, translated in app/lang/<code>.php; a reason may add the extra
 * fields 'text' (a short topic), 'url' (a link on the project's own site),
 * 'rating' (1–5 stars) and/or 'publish' (consent to publish the message).
 */
final class Projects
{
    /** Built-in reasons, in no particular order (projects.php decides the order). */
    public const REASONS = ['report', 'support', 'feedback', 'feature', 'collaboration', 'account', 'security', 'abuse', 'privacy', 'other'];
    public const EXTRAS = ['text', 'url', 'rating', 'publish'];

    private const ID = '/^[a-z0-9_]{1,32}$/';
    private const PROJECT_KEYS = ['name', 'site', 'reasons'];
    private const RATING_VALUES = ['5', '4', '3', '2', '1'];

    private static ?array $projects = null;

    public static function all(): array
    {
        if (self::$projects === null) {
            $file = MCF_ROOT . '/projects.php';
            if (!is_file($file)) {
                throw new ConfigException('projects.php not found (copy projects-sample.php).');
            }
            self::$projects = self::build(require $file);
        }
        return self::$projects;
    }

    public static function get(string $project): ?array
    {
        $all = self::all();
        return (preg_match(self::ID, $project) === 1 && isset($all[$project])) ? $all[$project] : null;
    }

    public static function reason(string $project, string $reason): ?array
    {
        $p = self::get($project);
        if ($p === null || preg_match(self::ID, $reason) !== 1 || !isset($p['reasons'][$reason])) {
            return null;
        }
        return $p['reasons'][$reason];
    }

    /** Field definitions (name => definition) shown for a project/reason pair. */
    public static function fieldsFor(string $project, string $reason): array
    {
        return self::reason($project, $reason) ?? [];
    }

    public static function isOption(array $field, string $value): bool
    {
        return in_array($value, $field['options'] ?? [], true);
    }

    // ── Texts ──────────────────────────────────────────────────────────────

    public static function name(string $project): string
    {
        return self::get($project)['name'] ?? $project;
    }

    public static function reasonLabel(string $lang, string $reason): string
    {
        return I18n::find($lang, "reason.$reason") ?? $reason;
    }

    public static function notice(string $lang, string $reason): ?string
    {
        return I18n::find($lang, "notice.$reason");
    }

    /** $kind: field (label), placeholder or help. */
    public static function fieldText(string $lang, string $kind, string $field): ?string
    {
        return I18n::find($lang, "$kind.$field");
    }

    public static function optionLabel(string $lang, string $field, string $value): string
    {
        return I18n::find($lang, "option.$field.$value") ?? $value;
    }

    /**
     * Ordered, browser-safe copy of the configuration: every translated text
     * is an object with one entry per available language ({it: …, en: …}),
     * so the page can switch language without a reload.
     */
    public static function publicConfig(): array
    {
        $langs = array_keys(I18n::names());
        $each = static function (callable $fn) use ($langs): array {
            $out = [];
            foreach ($langs as $l) {
                $out[$l] = $fn($l);
            }
            return $out;
        };

        $out = [];
        foreach (self::all() as $id => $p) {
            $reasons = [];
            foreach ($p['reasons'] as $rid => $fields) {
                $list = [];
                foreach ($fields as $name => $f) {
                    $field = [
                        'name'     => $name,
                        'type'     => $f['type'],
                        'label'    => $each(fn ($l) => self::fieldText($l, 'field', $name) ?? $name),
                    ];
                    foreach (['placeholder', 'help'] as $kind) {
                        if (self::fieldText(I18n::FALLBACK, $kind, $name) !== null) {
                            $field[$kind] = $each(fn ($l) => self::fieldText($l, $kind, $name));
                        }
                    }
                    foreach (['maxlength', 'hosts'] as $k) {
                        if (isset($f[$k])) {
                            $field[$k] = $f[$k];
                        }
                    }
                    if ($f['type'] === 'select') {
                        $field['options'] = array_map(fn (string $v): array => [
                            'value' => $v,
                            'label' => $each(fn ($l) => self::optionLabel($l, $name, $v)),
                        ], $f['options']);
                    }
                    $list[] = $field;
                }
                $reason = ['id' => $rid, 'label' => $each(fn ($l) => self::reasonLabel($l, $rid)), 'fields' => $list];
                if (self::notice(I18n::FALLBACK, $rid) !== null) {
                    $reason['notice'] = $each(fn ($l) => self::notice($l, $rid));
                }
                $reasons[] = $reason;
            }
            $out[] = ['id' => $id, 'label' => $each(fn () => $p['name']), 'hosts' => $p['hosts'], 'reasons' => $reasons];
        }
        return $out;
    }

    /** Whether any reason of any project asks for consent to publish (privacy page). */
    public static function anyPublish(): bool
    {
        foreach (self::all() as $p) {
            foreach ($p['reasons'] as $fields) {
                if (isset($fields['publish'])) {
                    return true;
                }
            }
        }
        return false;
    }

    // ── Loading ────────────────────────────────────────────────────────────

    /** Validates projects.php, failing loudly with the offending entry. */
    private static function build(mixed $projects): array
    {
        $fail = static function (string $msg): never {
            throw new ConfigException('Invalid projects.php: ' . $msg);
        };

        if (!is_array($projects) || $projects === []) {
            $fail('must return a non-empty array of projects');
        }
        $out = [];
        foreach ($projects as $id => $p) {
            if (!is_string($id) || preg_match(self::ID, $id) !== 1) {
                $fail("project id '$id': use lowercase letters, digits and _");
            }
            if (!is_array($p)) {
                $fail("$id must be an array");
            }
            foreach (array_diff(array_keys($p), self::PROJECT_KEYS) as $key) {
                $fail("$id: unknown key '$key' (allowed: " . implode(', ', self::PROJECT_KEYS) . ')');
            }
            $name = $p['name'] ?? null;
            if (!is_string($name) || preg_match('/^[^\x00-\x1f\x7f\[\]]{1,40}$/u', $name) !== 1 || trim($name) === '') {
                $fail("$id.name must be a short name (up to 40 characters)");
            }
            $site = $p['site'] ?? '';
            if (!is_string($site) || !str_starts_with($site, 'https://') || filter_var($site, FILTER_VALIDATE_URL) === false) {
                $fail("$id.site must be the site's https address");
            }
            $hosts = self::hostVariants(strtolower((string) parse_url($site, PHP_URL_HOST)));
            if (empty($p['reasons']) || !is_array($p['reasons'])) {
                $fail("$id needs at least one reason");
            }

            $reasons = [];
            foreach ($p['reasons'] as $key => $value) {
                // 'report' (list entry) or 'report' => ['url', 'rating']
                [$rid, $extras] = is_int($key) ? [$value, []] : [$key, $value];
                if (!is_string($rid) || !in_array($rid, self::REASONS, true)) {
                    $fail("$id: unknown reason '" . (is_string($rid) ? $rid : '?') . "' (available: " . implode(', ', self::REASONS) . ')');
                }
                if (isset($reasons[$rid])) {
                    $fail("$id: reason '$rid' listed twice");
                }
                if (!is_array($extras) || !array_is_list($extras) || array_diff($extras, self::EXTRAS) !== []) {
                    $fail("$id.$rid: extra fields must be a list of " . implode(', ', self::EXTRAS) . ", e.g. '$rid' => ['url']");
                }
                $fields = [];
                foreach (self::EXTRAS as $extra) {
                    if (!in_array($extra, $extras, true)) {
                        continue;
                    }
                    $fields[$extra] = match ($extra) {
                        'text'   => ['type' => 'text', 'maxlength' => 100],
                        // Only links on the project's own site; the part after "#" is dropped.
                        'url'    => ['type' => 'url', 'hosts' => $hosts, 'maxlength' => 2048],
                        'rating' => ['type' => 'select', 'options' => self::RATING_VALUES],
                        // A checkbox, never ticked in advance: consent is opt-in.
                        'publish' => ['type' => 'consent'],
                    };
                }
                $reasons[$rid] = $fields;
            }
            $out[$id] = ['name' => $name, 'hosts' => $hosts, 'reasons' => $reasons];
        }
        return $out;
    }

    /** example.com ↔ www.example.com are the same site. */
    private static function hostVariants(string $host): array
    {
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        return [$host === $bare ? $bare : $host, $host === $bare ? 'www.' . $bare : $bare];
    }
}
