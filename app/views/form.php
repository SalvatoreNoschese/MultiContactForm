<?php

declare(strict_types=1);

use MCF\Attachments;
use MCF\Config;
use MCF\I18n;
use MCF\Prefs;
use MCF\Security;

defined('MCF_APP') || exit;

/**
 * @var string   $lang
 * @var string   $csrf
 * @var string   $formToken
 * @var string   $configJson
 * @var array    $limits
 * @var array    $att
 * @var callable $t
 */
?><!DOCTYPE html>
<html <?= Prefs::htmlAttributes($lang) ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <?php $siteVars = ['site' => (string) Config::get('site_name')]; ?>
    <?= Prefs::themeColorMeta() ?>

    <meta name="description" content="<?= e(strip_tags(I18n::t($lang, 'intro'))) ?>">
    <title data-i18n="page_title" data-i18n-vars="<?= e(json_encode($siteVars)) ?>"><?= $t('page_title', $siteVars) ?></title>
    <?= Prefs::alternateLinks($lang, '') ?>

    <?= head_tags($lang, '', I18n::t($lang, 'page_title', $siteVars), strip_tags(I18n::t($lang, 'intro'))) ?>
    <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
    <noscript><link rel="stylesheet" href="<?= e(asset('assets/css/noscript.css')) ?>"></noscript>
    <script type="application/json" id="contact-config"><?= $configJson ?></script>
    <script src="<?= e(asset('assets/js/ui.js')) ?>" defer></script>
    <?php /* The nonce lets the widget style itself under the CSP (Security::cspNonce()). */ ?>
    <script src="<?= e(asset('assets/vendor/altcha/altcha.umd.min.cjs')) ?>" nonce="<?= e(Security::cspNonce()) ?>" defer></script>
    <script src="<?= e(asset('assets/js/contact.js')) ?>" defer></script>
</head>
<body data-live-lang="true">
<?php require MCF_APP_DIR . '/views/topbar.php'; ?>

<main id="main" class="wrap">
    <section id="form-view" aria-labelledby="heading">
        <h1 id="heading" data-i18n="heading"><?= $t('heading') ?></h1>
        <p class="intro" data-i18n="intro"><?= $t('intro') ?></p>

        <noscript><p class="notice notice-warn"><?= $t('noscript') ?></p></noscript>

        <div id="error-summary" class="error-summary" role="alert" tabindex="-1" hidden>
            <h2 data-i18n="errors_title"><?= $t('errors_title') ?></h2>
            <p id="error-summary-text"></p>
            <ul id="error-summary-list"></ul>
        </div>

        <?php /* Without JavaScript there is no anti-spam proof-of-work: noscript.css
                 hides the form and only the <noscript> notice shows. Shown from the
                 start otherwise, so the page does not shift when contact.js runs. */ ?>
        <form id="contact-form" method="post" action="<?= e(url('submit.php')) ?>" novalidate>
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="form_token" value="<?= e($formToken) ?>">
            <input type="hidden" name="lang" value="<?= e($lang) ?>">
            <p class="required-note"><span class="req" aria-hidden="true">*</span> <span data-i18n="required_note"><?= $t('required_note') ?></span></p>

            <div class="field">
                <label for="project"><span data-i18n="project"><?= $t('project') ?></span><span class="req" aria-hidden="true">*</span></label>
                <select id="project" name="project" required aria-describedby="project-error">
                    <option value="" data-i18n="project_ph"><?= $t('project_ph') ?></option>
                </select>
                <p class="field-error" id="project-error" hidden></p>
            </div>

            <div class="field">
                <label for="reason"><span data-i18n="reason"><?= $t('reason') ?></span><span class="req" aria-hidden="true">*</span></label>
                <select id="reason" name="reason" required disabled aria-describedby="reason-error">
                    <option value="" data-i18n="reason_need_project"><?= $t('reason_need_project') ?></option>
                </select>
                <p class="field-error" id="reason-error" hidden></p>
            </div>

            <div id="reason-notice" class="notice notice-warn" role="note" aria-live="polite" hidden></div>

            <div id="conditional-fields" aria-live="polite"></div>

            <div class="field">
                <label for="name"><span data-i18n="name"><?= $t('name') ?></span><span class="req" aria-hidden="true">*</span></label>
                <input type="text" id="name" name="name" autocomplete="name" required
                       minlength="<?= (int) $limits['name_min'] ?>" maxlength="<?= (int) $limits['name_max'] ?>"
                       placeholder="<?= $t('ph_name') ?>" data-i18n-placeholder="ph_name"
                       aria-describedby="name-error">
                <p class="field-error" id="name-error" hidden></p>
            </div>

            <div class="field">
                <label for="email"><span data-i18n="email"><?= $t('email') ?></span><span class="req" aria-hidden="true">*</span></label>
                <input type="email" id="email" name="email" autocomplete="email" inputmode="email" required
                       spellcheck="false" maxlength="<?= (int) $limits['email_max'] ?>"
                       placeholder="<?= $t('ph_email') ?>" data-i18n-placeholder="ph_email"
                       aria-describedby="email-error email-hint">
                <p class="field-error" id="email-error" hidden></p>
                <p class="hint" id="email-hint" data-i18n="email_hint"><?= $t('email_hint') ?></p>
            </div>

            <div class="field">
                <label for="message"><span data-i18n="message"><?= $t('message') ?></span><span class="req" aria-hidden="true">*</span></label>
                <textarea id="message" name="message" rows="8" required
                          minlength="<?= (int) $limits['message_min'] ?>" maxlength="<?= (int) $limits['message_max'] ?>"
                          placeholder="<?= $t('ph_message') ?>" data-i18n-placeholder="ph_message"
                          aria-describedby="message-error message-hint"></textarea>
                <p class="field-error" id="message-error" hidden></p>
                <?php $msgVars = ['min' => (int) $limits['message_min'], 'max' => (int) $limits['message_max'], 'n' => (int) $limits['message_max']]; ?>
                <p class="hint" id="message-hint" data-i18n="message_hint" data-i18n-vars="<?= e(json_encode($msgVars)) ?>"><?= $t('message_hint', $msgVars) ?></p>
            </div>

            <?php if ($att['enabled']): ?>
            <?php
            $attVars = [
                'n'     => $att['max_files'],
                'file'  => Attachments::formatBytes($att['max_file_bytes'], $lang),
                'total' => Attachments::formatBytes($att['max_total_bytes'], $lang),
                'types' => Attachments::extensionList($att['types']),
            ];
            $accept = implode(',', array_map(static fn (string $x): string => '.' . $x, array_keys($att['types'])));
            ?>
            <div class="field" id="attachments-field">
                <label for="attachments-btn"><span data-i18n="attachments"><?= $t('attachments') ?></span><span class="optional"> <span data-i18n="optional"><?= $t('optional') ?></span></span></label>
                <?php /* The native file input cannot be translated ("Browse… / No files
                         selected" comes from the browser's own language): it stays in the
                         form, visually hidden, and our translated button opens it. */ ?>
                <input type="file" id="attachments" class="file-native" name="attachments[]" multiple accept="<?= e($accept) ?>"
                       tabindex="-1" aria-hidden="true">
                <div class="file-picker">
                    <button type="button" id="attachments-btn" class="btn btn-secondary btn-small"
                            aria-describedby="attachments-error attachments-status attachments-hint attachments-note"
                            data-i18n="att_choose"><?= $t('att_choose') ?></button>
                    <span id="attachments-status" class="file-status" data-i18n="att_none"><?= $t('att_none') ?></span>
                </div>
                <p class="field-error" id="attachments-error" hidden></p>
                <p class="hint" id="attachments-hint" data-i18n="att_hint" data-i18n-vars="<?= e(json_encode($attVars)) ?>"><?= $t('att_hint', $attVars) ?></p>
                <p class="hint" id="attachments-note" data-i18n="att_note"><?= $t('att_note') ?></p>
                <ul id="attachments-list" class="file-list" aria-live="polite"></ul>
            </div>
            <?php endif; ?>

            <!-- Honeypot: invisible to humans, tempting to bots. -->
            <div class="hp" aria-hidden="true">
                <label for="website" data-i18n="honeypot"><?= $t('honeypot') ?></label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" value="">
            </div>

            <div class="field field-check">
                <input type="checkbox" id="privacy" name="privacy" value="1" required aria-describedby="privacy-error">
                <?php
                // One sentence with the link inside ("I have read the [privacy
                // notice]"): the brackets in privacy_ack mark the link text.
                // In a new tab, so what was typed here is not lost.
                [$ackBefore, $ackLink, $ackAfter] = I18n::linkParts($t('privacy_ack'));
                ?>
                <label for="privacy"><span data-i18n-link="privacy_ack"><?= $ackBefore ?><a id="privacy-link" href="<?= e(Prefs::pageUrl($lang, 'privacy.php')) ?>" target="_blank" rel="noopener"><?= $ackLink ?></a><?= $ackAfter ?></span><span class="req" aria-hidden="true">*</span></label>
                <p class="field-error" id="privacy-error" hidden></p>
            </div>

            <?php
            // Official ALTCHA widget (public/assets/vendor/altcha). contact.js
            // starts it and feeds it challenges from challenge.php.
            $altchaConfig = [
                'hideLogo'   => Config::get('altcha.show_logo') !== true,
                'hideFooter' => Config::get('altcha.show_footer') !== true,
            ];
            ?>
            <div class="pow">
                <span class="pow-label" data-i18n="antispam"><?= $t('antispam') ?></span>
                <altcha-widget id="altcha" challenge="<?= e(url('challenge.php')) ?>" auto="off" name="altcha"
                               language="<?= e($lang) ?>" configuration="<?= e(json_encode($altchaConfig)) ?>"></altcha-widget>
                <p class="hint" data-i18n="pow_note"><?= $t('pow_note') ?></p>
            </div>

            <div class="actions">
                <button type="submit" id="submit-btn" class="btn" data-i18n="send"><?= $t('send') ?></button>
                <button type="button" id="cancel-btn" class="btn btn-secondary" data-i18n="reset" hidden><?= $t('reset') ?></button>
            </div>
        </form>
    </section>

    <section id="success-view" class="success" aria-labelledby="success-heading" tabindex="-1" hidden>
        <h1 id="success-heading" data-i18n="success_title"><?= $t('success_title') ?></h1>
        <p data-i18n="success_text"><?= $t('success_text') ?></p>
        <p class="actions">
            <button type="button" id="return-btn" class="btn" hidden></button>
            <button type="button" id="again-btn" class="btn btn-secondary" data-i18n="send_another"><?= $t('send_another') ?></button>
        </p>
        <p id="auto-return" class="hint" hidden><span id="auto-return-text"></span> <button type="button" id="stay-btn" class="linklike" data-i18n="stay"><?= $t('stay') ?></button></p>
    </section>
</main>

<?php require MCF_APP_DIR . '/views/footer.php'; ?>
<?= matomo_tags() ?>
</body>
</html>
