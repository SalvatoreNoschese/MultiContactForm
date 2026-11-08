<?php

declare(strict_types=1);

use MCF\Config;
use MCF\I18n;
use MCF\Prefs;

defined('MCF_APP') || exit;

/**
 * Shared top bar: language drop-down (flag + code) and one theme toggle.
 * Both are plain links handled by Prefs::handleRequest(), so they work
 * without JavaScript; ui.js applies them live.
 *
 * Two toggles, one per target theme, and CSS shows the one pointing to the
 * opposite of the theme shown: the server cannot know the device theme nor
 * this visit's (sessionStorage, theme.js), and the page may follow either.
 *
 * @var string   $lang
 * @var callable $t
 */
// Flag icon only when public/assets/img/flags/<code>.svg exists.
$flag = static fn (string $code): string => is_file(MCF_ROOT . '/public/assets/img/flags/' . $code . '.svg')
    ? '<img class="flag" src="' . e(asset('assets/img/flags/' . $code . '.svg')) . '" alt="" width="21" height="14">' : '';
$toggles = ['dark' => 'theme-switch--to-dark', 'light' => 'theme-switch--to-light'];
?>
<header class="topbar">
    <div class="wrap topbar-inner">
        <a class="brand" href="<?= e(Prefs::brandUrl($lang)) ?>"<?= Prefs::brandAttrs() ?>><?= e((string) Config::get('site_name')) ?></a>
        <div class="switches">
            <details class="lang-menu">
                <summary class="topbar-btn" title="<?= $t('language') ?>" data-i18n-title="language">
                    <?= $flag($lang) ?>
                    <span aria-hidden="true"><?= e(strtoupper($lang)) ?></span>
                    <span class="sr-only"><span data-i18n="language"><?= $t('language') ?></span>: <?= e(I18n::names()[$lang]) ?></span>
                </summary>
                <ul class="lang-list">
                    <?php foreach (I18n::names() as $code => $label): ?>
                        <li>
                            <a class="lang-option" href="?lang=<?= e($code) ?>" data-lang="<?= e($code) ?>" lang="<?= e($code) ?>" hreflang="<?= e($code) ?>"<?= $code === $lang ? ' aria-current="true"' : '' ?>>
                                <?= $flag($code) ?>
                                <span><?= e($label) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <?php foreach ($toggles as $target => $class): ?>
                <a class="topbar-btn icon-btn theme-toggle <?= $class ?>" href="?theme=<?= $target ?>"
                   title="<?= $t('theme_toggle') ?>" aria-label="<?= $t('theme_toggle') ?>" data-i18n-label="theme_toggle">🌓</a>
            <?php endforeach; ?>
        </div>
    </div>
</header>
