<?php

declare(strict_types=1);

use MCF\Prefs;

defined('MCF_APP') || exit;

/**
 * Shared footer: "© <year> MultiContactForm"
 * linking to the repository, then a short line (footer in app/lang/<code>.php,
 * yours to change) and the link to the privacy notice (not on the notice
 * itself). Never the version: it would tell anyone which
 * release runs here (the admin finds it in app/includes/bootstrap.php).
 *
 * @var string   $lang
 * @var callable $t
 * @var bool     $footerPrivacy  show the privacy link (default true)
 */
$footerPrivacy ??= true;
?>
<footer class="footer">
    <div class="wrap footer-inner">
        <p class="footer-brand">&copy; <?= date('Y') ?> <a href="<?= e(MCF_REPO) ?>" rel="noopener">MultiContactForm</a></p>
        <p><span data-i18n="footer"><?= $t('footer') ?></span><?php if ($footerPrivacy): ?>
            · <a id="privacy-footer-link" href="<?= e(Prefs::pageUrl($lang, 'privacy.php')) ?>" data-i18n="privacy_link"><?= $t('privacy_link') ?></a><?php endif; ?></p>
    </div>
</footer>
