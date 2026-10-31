<?php

declare(strict_types=1);

namespace MCF;

use Throwable;

defined('MCF_APP') || exit;

/**
 * Handles a submission end-to-end and returns [HTTP status, JSON payload].
 *
 * Order of checks (cheapest and least stateful first):
 *   method → size → content type → origin → CSRF → per-client attempt limit
 *   → honeypot → minimum fill time → field + attachment validation
 *   → proof-of-work → per-client message limit → global limit → delivery
 *
 * Two per-client limits: 'attempt' counts every request that gets this far
 * (generous: it only stops hammering), 'submit' counts messages, i.e. valid
 * forms with a solved challenge. A visitor who has to correct the form a few
 * times (an attachment the server refuses, say) is not locked out of sending.
 *
 * The global limit is counted last, only for submissions that passed every
 * other check: junk requests (or ones already refused per client) cannot
 * use it up and lock the form for everybody.
 */
final class ContactService
{
    /** @return array{0:int, 1:array<string,mixed>} */
    public static function handle(): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            return [405, ['ok' => false, 'error' => 'forbidden']];
        }
        $limits = (array) Config::get('limits');
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > Attachments::maxRequestBytes()) {
            return [413, ['ok' => false, 'error' => 'too_large']];
        }
        // multipart/form-data carries attachments; urlencoded is accepted too.
        $type = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($type !== 'multipart/form-data' && $type !== 'application/x-www-form-urlencoded') {
            return [415, ['ok' => false, 'error' => 'forbidden']];
        }
        // Body over php.ini's post_max_size: PHP silently drops $_POST and
        // $_FILES. Say "too large" instead of a misleading CSRF error.
        if ($length > 0 && $_POST === [] && $_FILES === []) {
            return [413, ['ok' => false, 'error' => 'too_large']];
        }
        if (!Security::isSameOrigin()) {
            return [403, ['ok' => false, 'error' => 'forbidden']];
        }

        $input = $_POST;

        // Missing/expired cookie or forged token: hand out fresh ones, the
        // visitor just presses Send again (after the minimum fill time).
        $csrf = $input['csrf'] ?? null;
        $age = Security::checkCsrf($csrf) ? Security::formAge($input['form_token'] ?? null, $csrf) : null;
        if ($age === null) {
            $fresh = Security::csrfToken();
            return [403, ['ok' => false, 'error' => 'csrf', 'csrf' => $fresh, 'form_token' => Security::formToken($fresh)]];
        }

        $client = Security::clientKey();
        $retry = RateLimiter::hit('attempt', $client, (array) Config::get('rate_limit.attempt'));
        if ($retry > 0) {
            return self::limited($retry);
        }

        // Honeypot filled: pretend success, deliver nothing.
        $honeypot = $input['website'] ?? '';
        if (!is_string($honeypot) || $honeypot !== '') {
            return [200, self::success($csrf)];
        }

        if ($age < $limits['min_fill_seconds']) {
            return [400, ['ok' => false, 'error' => 'too_fast']];
        }

        [$data, $errors] = Validation::validate($input);
        [$files, $fileError] = Attachments::collect($_FILES['attachments'] ?? null);
        if ($fileError !== null) {
            $errors['attachments'] = $fileError;
        }
        if ($errors !== []) {
            return [422, ['ok' => false, 'error' => 'validation', 'fields' => $errors]];
        }

        if (!Altcha::verify($input['altcha'] ?? null)) {
            return [400, ['ok' => false, 'error' => 'spam']];
        }

        // Messages per client, then for the whole service: every slot of
        // either costs a valid form and a solved challenge.
        $retry = RateLimiter::hit('submit', $client, (array) Config::get('rate_limit.submit'));
        if ($retry === 0) {
            $retry = RateLimiter::hit('submit_global', 'all', (array) Config::get('rate_limit.submit_global'));
        }
        if ($retry > 0) {
            return self::limited($retry);
        }

        try {
            [$subject, $body] = self::compose($data, $files);
            (new MailService((array) Config::get('mail')))->send($subject, $body, $data['email'], $files, self::fromName($data));
        } catch (Throwable $e) {
            // Log the technical cause only; never the visitor's data.
            error_log('[mcf] delivery failed: ' . $e->getMessage());
            return [502, ['ok' => false, 'error' => 'mail']];
        }

        return [200, self::success($csrf)];
    }

    /**
     * Subject and body. The subject is built exclusively from whitelisted
     * labels; user text appears only in the body.
     *
     * @return array{0:string, 1:string}
     */
    public static function compose(array $data, array $files = []): array
    {
        $lang = I18n::normalize(Config::get('mail.language'));
        $t = static fn (string $key): string => I18n::t($lang, $key);
        $pid = $data['project'];
        $rid = $data['reason'];
        $project = Projects::get($pid);
        $fields = Projects::fieldsFor($pid, $rid);

        $subject = '[' . $project['name'] . '] ' . Projects::reasonLabel($lang, $rid);
        foreach ($fields as $name => $def) {
            if ($def['type'] === 'select' && isset($data['fields'][$name])) {
                $subject .= ' — ' . Projects::optionLabel($lang, $name, $data['fields'][$name]);
            }
        }

        $rows = [
            $t('mail.project') => $project['name'] . ' (' . $pid . ')',
            $t('mail.reason')  => Projects::reasonLabel($lang, $rid),
        ];
        foreach ($fields as $name => $def) {
            if ($def['type'] === 'consent') {
                // Stated either way: an unticked box is an explicit "no".
                $rows[$t('mail.publish')] = $t(isset($data['fields'][$name]) ? 'mail.publish_yes' : 'mail.publish_no');
                continue;
            }
            if (!isset($data['fields'][$name])) {
                continue;
            }
            $value = $data['fields'][$name];
            $label = Projects::fieldText($lang, 'field', $name) ?? $name;
            $rows[$label] = $def['type'] === 'select' ? Projects::optionLabel($lang, $name, $value) : $value;
        }
        $rows[$t('mail.name')] = $data['name'];
        $rows[$t('mail.email')] = $data['email'];
        $rows[$t('mail.language')] = I18n::names()[$data['lang']] ?? $data['lang'];
        $rows[$t('mail.date')] = date('Y-m-d H:i:s T');
        if ($files !== []) {
            $list = array_map(static fn (array $f): string => $f['name'] . ' (' . Attachments::formatBytes($f['size'], $lang) . ')', $files);
            $rows[$t('mail.attachments')] = implode(', ', $list);
        }

        $title = $t('mail.title');
        $body = $title . "\n" . str_repeat('=', mb_strlen($title)) . "\n\n";
        foreach ($rows as $label => $value) {
            $body .= $label . ': ' . $value . "\n";
        }
        $body .= "\n" . $t('mail.message') . ":\n" . str_repeat('-', 40) . "\n" . $data['message'] . "\n"
            . str_repeat('-', 40) . "\n\n" . $t('mail.reply_hint') . "\n";

        return [$subject, $body];
    }

    /**
     * Sender name shown in the inbox, e.g. "Mario Rossi via My Blog", from
     * mail.from_name. The visitor's name is already a single sanitised line;
     * MailService::displayName() encodes it safely for the header.
     */
    public static function fromName(array $data): string
    {
        $name = mb_substr((string) $data['name'], 0, 60);
        return strtr((string) Config::get('mail.from_name'), [
            '{name}'    => $name,
            '{project}' => Projects::name($data['project']),
            '{site}'    => (string) Config::get('site_name'),
        ]);
    }

    /** @return array{0:int, 1:array<string,mixed>} */
    private static function limited(int $retry): array
    {
        header('Retry-After: ' . $retry);
        return [429, ['ok' => false, 'error' => 'rate', 'retry_after' => $retry]];
    }

    private static function success(string $csrf): array
    {
        return [
            'ok'         => true,
            // A new form starts now: "send another" must wait the minimum time again.
            'form_token' => Security::formToken($csrf),
        ];
    }
}
