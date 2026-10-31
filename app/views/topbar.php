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
 * Without a theme shown by cookie or ?view= the server cannot know the
 * device theme, so two toggles are rendered and CSS shows the one pointing
 * to the opposite theme. The language links keep ?view=; the toggle drops
 * it (a choice made here: the cookie decides from then on).
 *
 * @var string   $lang
 * @var callable $t
 */
$theme = Prefs::shownTheme();
// Flag icon only when public/assets/img/flags/<code>.svg exists.
$flag = static fn (string $code): string => is_file(MCF_ROOT . '/public/assets/img/flags/' . $code . '.svg')
    ? '<img class="flag" src="' . e(asset('assets/img/flags/' . $code . '.svg')) . '" alt="" width="21" height="14">' : '';
$toggles = $theme !== null ? [$theme === 'dark' ? 'light' : 'dark' => ''] : ['dark' => 'theme-switch--to-dark', 'light' => 'theme-switch--to-light'];
?>
<header class="topbar">
    <div class="wrap topbar-inner">
        <a class="brand" href="<?= e(Prefs::brandUrl($lang)) ?>"><?= e((string) Config::get('site_name')) ?></a>
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
                            <a class="lang-option" href="?lang=<?= e($code) ?><?= Prefs::viewQuery('&amp;') ?>" data-lang="<?= e($code) ?>" lang="<?= e($code) ?>" hreflang="<?= e($code) ?>"<?= $code === $lang ? ' aria-current="true"' : '' ?>>
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
