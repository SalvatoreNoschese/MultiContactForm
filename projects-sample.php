<?php

/**
 * PROJECTS — the sites that use this contact form.
 *
 * Copy this file to projects.php (next to config.php, outside public/) and
 * edit it. projects.php is REQUIRED. This file is the configuration of the
 * live demo: https://contact.salvatorenoschese.it
 *
 * ── 1. A project ───────────────────────────────────────────────────────────
 *
 *   'webtools' => [                                  ← the project's key
 *       'name'       => 'WebTools',                  ← menu and email subject
 *       'site'       => 'https://tools.example.com', ← the project's site
 *       'reasons'    => [ … ],                       ← see 2.
 *   ],
 *
 *   key         lowercase letters, digits and "_"; it is used in the links (3.)
 *   name        any short name, up to 40 characters
 *   site        REQUIRED: your site's address, https; only the domain counts
 *               (www. or not is the same site). It is what keeps links out
 *               of your mail: the "link to the page" field (2.) accepts
 *               only addresses on this site, never someone else's (spam,
 *               phishing). It also names the way back (below)
 *
 * A visitor who arrives from another site gets a way back to the very page
 * they came from, under the form and after sending: "Back to WebTools" when
 * they came from this site, "Back to example.com" from a site that is not
 * in this file (after sending also by itself, after a countdown, with
 * config.php → auto_return). From a bookmark, a typed address, a new tab or
 * a site that sends no referrer there is nothing to go back to: the button
 * under the form becomes "Reset" and after sending there is none.
 *
 * ── 2. Reasons ─────────────────────────────────────────────────────────────
 *
 * The "Contact reason" menu, in the order you list them. Pick from:
 *
 *   report         Report a problem
 *   support        Support
 *   feedback       Feedback
 *   feature        Suggest a feature
 *   collaboration  Collaboration
 *   account        Account problem
 *   security       Security issue   (shows a warning: no passwords or keys)
 *   abuse          Abuse report
 *   privacy        Privacy (also for GDPR requests)
 *   other          Other
 *
 * They are translated in every language of the form: you write only the key.
 *
 * A reason can add optional fields, written after "=>":
 *
 *   'other'    => ['text'],           "Topic": one short line (100 characters)
 *   'report'   => ['url'],            a link to the page concerned; only links
 *                                     on the project's site are accepted
 *                                     and the part after "#" is never sent
 *   'feedback' => ['rating'],         1–5 stars
 *   'feedback' => ['publish'],        a checkbox, never ticked in advance: the
 *                                     visitor agrees that the message may be
 *                                     published on your site, with the name
 *                                     shortened (Mario R.), never the email
 *                                     address; the email tells you either way,
 *                                     and the privacy page explains it
 *   'report'   => ['text', 'url'],    more than one
 *
 * ── 3. Links to the form ───────────────────────────────────────────────────
 *
 * On your site, link to the form with the project's key after "#":
 *
 *   https://contact.example.com/#webtools            project chosen
 *   https://contact.example.com/#webtools:feedback   project and reason chosen
 *   https://contact.example.com/                     visitor chooses both
 *   https://contact.example.com/es/#webtools         …in Spanish (it, en, es, fr, de)
 *   https://contact.example.com/de/?view=light#webtools:feedback
 *                                                    …in German, light theme
 *
 * e.g. a "Report a problem" link on every page and a "Leave feedback" one
 * on the home page. A key or reason that does not exist is simply ignored.
 * Without a language in the address the visitor's own is used.
 *
 * For continuity, your sites can pass along the language and theme they show
 * (without ?view= the visitor's choice or the device's theme is used). Both
 * are only shown, never stored: the form remembers a language or a theme
 * only when chosen with its own menu and 🌓 toggle.
 *
 * ── Errors ─────────────────────────────────────────────────────────────────
 *
 * A project can also be this form itself (see 'contact' below), for
 * questions about its privacy notice: config.php → privacy → project.
 *
 * A mistake in this file (unknown reason, missing name, http site…)
 * stops the service: the form shows an error page and the server's error
 * log says what to fix.
 */

declare(strict_types=1);

defined('MCF_APP') || exit;

return [

    'aesbin' => [
        'name'       => 'AESBin',
        'site'       => 'https://aesbin.salvatorenoschese.it',
        'reasons'    => [
            'report' => ['url'],
            'account',
            'security',
            'abuse' => ['url'],
            'privacy',
            'other' => ['text'],
        ],
    ],

    'blog' => [
        'name'       => 'SNBlog',
        'site'       => 'https://salvatorenoschese.it',
        'reasons'    => [
            'report' => ['url'],
            'support',
            'collaboration',
            'privacy',
            'other' => ['text'],
        ],
    ],

    'webtools' => [
        'name'       => 'WebTools',
        'site'       => 'https://tools.salvatorenoschese.it',
        'reasons'    => [
            'report' => ['url'],
            'account',
            'feature',
            'feedback' => ['rating', 'publish'],
            'privacy',
            'other' => ['text'],
        ],
    ],

    // This form itself: questions about its privacy notice and data. With
    // 'project' => 'contact' in config.php → privacy, the privacy page's
    // links open it with the reason "Privacy" already chosen.
    'contact' => [
        'name'       => 'MultiContactForm',
        'site'       => 'https://contact.salvatorenoschese.it',
        'reasons'    => [
            'privacy',
            'report' => ['url'],
            'other' => ['text'],
        ],
    ],

];
