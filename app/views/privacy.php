<?php

declare(strict_types=1);

use MCF\Config;
use MCF\I18n;
use MCF\Prefs;
use MCF\Projects;
use MCF\Security;

defined('MCF_APP') || exit;

/**
 * Privacy notice (GDPR art. 13). Texts: the privacy.* keys in app/lang/<code>.php (README.md
 * § Privacy says what to check for your case). One template for every
 * language. The statistics paragraphs appear only with Matomo configured.
 *
 * @var string   $lang
 * @var array    $privacy  settings from config.php
 * @var callable $t
 */
// privacy.<key> in $lang, else in English (HTML allowed, not escaped).
$P = static fn (string $key): string => I18n::t($lang, 'privacy.' . $key);
// The sections in page order, as bullet points ('list') or paragraphs
// ('text'): privacy.<section>.title, then privacy.<section>.1, .2, …
$sections = ['data' => 'list', 'purposes' => 'list', 'retention' => 'list', 'recipients' => 'text', 'anti_spam' => 'text'];
// Language texts are trusted (they ship with the code) and may contain HTML;
// the values substituted into them are escaped or built here.
$fill = static fn (string $text, array $vars): string => strtr($text, $vars);

$controller = e((string) $privacy['controller']);
$months = (string) (int) $privacy['retention_months'];
// Anti-bot: reversed hex of the UTF-8 bytes (no "@" or
// "." for a regex to find). ui.js turns the empty span into a mailto link;
// without JavaScript only a notice is shown, never the address.
$contact = (string) $privacy['contact_email'];
$contactHtml = null;
if ($contact !== '' && filter_var($contact, FILTER_VALIDATE_EMAIL) !== false) {
    $scramble = static fn (string $str): string => strrev(bin2hex($str));
    $contactHtml = '<span data-obf-m="' . e($scramble($contact)) . '"></span><noscript>' . $t('email_noscript') . '</noscript>';
}
// The address, if any, appears once, under the controller's name (what the
// GDPR asks for); everywhere else the channel is the form, opened with the
// form's own project (privacy.project) and the reason “Privacy” when set.
$project = (string) $privacy['project'];
$formHash = $project !== '' && isset(Projects::all()[$project]['reasons']['privacy']) ? '#' . $project . ':privacy' : '';
$formLink = '<a href="' . e(Prefs::pageUrl($lang, '') . $formHash) . '">' . $P('form_link') . '</a>';
$addressLines = array_map(static fn ($line): string => e((string) $line), (array) $privacy['address']);
$vars = [
    '{controller}' => $controller,
    '{contact}'    => (string) $contactHtml,
    '{form}'       => $formLink,
    '{months}'     => $months,
];
$matomo = Config::matomo();
if ($matomo !== null) {
    $vars['{matomo}'] = e($matomo['host']);
}

$csrfName = Security::csrfCookieName();
$cookies = [
    ['name' => $csrfName, 'key' => null, 'present' => isset($_COOKIE[$csrfName]),
     'duration' => $P('cookie_csrf_duration'), 'purpose' => $P('cookie_csrf_purpose')],
    ['name' => Prefs::cookieName(Prefs::LANG), 'key' => 'lang',
     'duration' => $P('cookie_lang_duration'), 'purpose' => $P('cookie_lang_purpose')],
    ['name' => Prefs::cookieName(Prefs::THEME), 'key' => 'theme',
     'duration' => $P('cookie_theme_duration'), 'purpose' => $P('cookie_theme_purpose')],
];
?><!DOCTYPE html>
<html <?= Prefs::htmlAttributes($lang) ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <?= Prefs::themeColorMeta() ?>

    <meta name="description" content="<?= e(strip_tags(I18n::t($lang, 'privacy_description'))) ?>">
    <title><?= $t('privacy_title') ?></title>
    <?= Prefs::alternateLinks($lang, 'privacy.php') ?>

    <?= head_tags($lang, 'privacy.php', I18n::t($lang, 'privacy_title'), strip_tags(I18n::t($lang, 'privacy_description'))) ?>
    <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
    <script src="<?= e(asset('assets/js/ui.js')) ?>" defer></script>
</head>
<body>
<?php require MCF_APP_DIR . '/views/topbar.php'; ?>
<main id="main" class="wrap prose">
    <p><a href="<?= e(Prefs::pageUrl($lang, '')) ?>"><?= $t('privacy_back') ?></a></p>
    <h1><?= $P('heading') ?></h1>
    <p><?= $P('intro') ?></p>

    <section class="controller-box" aria-labelledby="controller-title">
        <h2 id="controller-title"><?= $P('controller_title') ?></h2>
        <p><?= $controller ?><?php foreach ($addressLines as $line): ?><br><?= $line ?><?php endforeach; ?><br>
            <?= $contactHtml ?? $fill($P('controller_form'), $vars) ?></p>
    </section>

    <?php foreach ($sections as $section => $form): ?>
        <h2><?= $P($section . '.title') ?></h2>
        <?php if ($form === 'list'): ?>
            <ul>
                <?php foreach (I18n::items($lang, 'privacy.' . $section) as $item): ?>
                    <li><?= $fill($item, $vars) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <?php foreach (I18n::items($lang, 'privacy.' . $section) as $paragraph): ?>
                <p><?= $fill($paragraph, $vars) ?></p>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if (Projects::anyPublish()): ?>
        <h2 id="publish"><?= $P('publish_title') ?></h2>
        <p><?= $fill($P('publish_text'), $vars) ?></p>
    <?php endif; ?>

    <?php if ($matomo !== null): ?>
        <h2 id="stats"><?= $P('stats_title') ?></h2>
        <p><?= $fill($P('stats_text'), $vars) ?></p>
    <?php endif; ?>

    <h2 id="cookie"><?= $P('cookies_title') ?></h2>
    <p><?= $P('cookies_intro') ?></p>
    <div class="table-wrap">
        <table class="cookie-table">
            <thead>
                <tr>
                    <th scope="col"><?= $P('col_name') ?></th>
                    <th scope="col"><?= $P('col_duration') ?></th>
                    <th scope="col"><?= $P('col_status') ?></th>
                </tr>
            </thead>
            <?php foreach ($cookies as $c): ?>
                <tbody>
                    <tr>
                        <th scope="row"><code><?= e($c['name']) ?></code></th>
                        <td><?= $c['duration'] ?></td>
                        <?php
                        $value = match ($c['key']) { 'lang' => Prefs::lang(), 'theme' => Prefs::theme(), default => null };
                        $isSet = $c['key'] === null ? $c['present'] : $value !== null;
                        $status = '<span class="' . ($isSet ? 'state-present' : 'state-absent') . '">' . ($isSet ? $P('present') : $P('absent')) . '</span>';
                        if ($value !== null) {
                            // Deleted by the server (?forget_cookie=): the language cookie is httponly.
                            $status .= ' <code>' . e($value) . '</code> · <a href="?forget_cookie=' . $c['key'] . e(Prefs::viewQuery('&')) . '">' . $P('delete') . '</a>';
                        }
                        ?>
                        <td><?php if ($c['key'] === 'theme'): ?><span data-cookie-status="theme" aria-live="polite" data-present="<?= e(strip_tags($P('present'))) ?>" data-delete="<?= e(strip_tags($P('delete'))) ?>"><?= $status ?></span><?php else: ?><?= $status ?><?php endif; ?></td>
                    </tr>
                    <tr class="cookie-desc"><td colspan="3"><?= $c['purpose'] ?></td></tr>
                </tbody>
            <?php endforeach; ?>
        </table>
    </div>
    <p><?= $P('cookies_note') ?></p>
    <?php if ($matomo !== null): ?>
        <p><?= $P('cookies_stats') ?></p>
    <?php endif; ?>

    <h2><?= $P('rights_title') ?></h2>
    <?php foreach (I18n::items($lang, 'privacy.rights') as $paragraph): ?>
        <p><?= $fill($paragraph, $vars) ?></p>
    <?php endforeach; ?>
</main>
<?php $footerPrivacy = false; require MCF_APP_DIR . '/views/footer.php'; ?>
<?= matomo_tags() ?>
</body>
</html>
