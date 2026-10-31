<?php

/**
 * Copy this file to config.php (same directory, outside public/) and adjust it.
 *
 * config.php is REQUIRED and validated at start-up: with a missing or
 * placeholder value among those marked REQUIRED, the service answers 503 and
 * the server log says which one. Everything else shows its default: change it
 * only if you need to.
 *
 * Projects, reasons and fields are configured in projects.php (copy
 * projects-sample.php), also required.
 */

declare(strict_types=1);

defined('MCF_APP') || exit;

return [
    // REQUIRED: shown in the top bar and in the page title.
    'site_name' => 'Your Name',

    // Optional: where that name links to, e.g. your home page; '' = the form.
    'site_url' => '',

    // Optional: after sending, "Back to …" returns by itself to the page the
    // visitor came from after this many seconds, with a live countdown and a
    // "Stay here" button; 0 = only when they click it (up to 60). Only when
    // there is a page to go back to: see projects-sample.php.
    'auto_return' => 0,

    // Optional: visit statistics with Matomo, self-hosted, always cookieless.
    // Fill in both to turn it on, leave both '' to keep it off.
    //
    //   url      your Matomo's address, ending in '/': https://stats.example.com/
    //   site_id  this site's ID in Matomo (Administration → Websites)
    //
    // Only Matomo, and only cookieless, on purpose: this service has no
    // consent banner. Analytics that set cookies or identify visitors need
    // one (and Google Analytics also sends data outside the EU). Without
    // cookies and with the IP anonymised, no consent is needed:
    //
    //   - in Matomo, turn on IP anonymisation (Administration → Privacy →
    //     Anonymize data, at least 2 bytes): it is a setting of your Matomo
    //     instance, not of this file;
    //   - cookies are disabled here, always (disableCookies).
    //
    // When it is on, the privacy page gains a "Visit statistics" section and
    // a line in the cookie section: nothing else to edit. The CSP opens to
    // Matomo's origin only.
    'matomo' => [
        'url'     => '',
        'site_id' => '',
    ],

    // REQUIRED: at least 32 random characters, never generated automatically.
    // Generate with: php -r 'echo bin2hex(random_bytes(32));'
    'secret' => 'CHANGE_ME',

    // Time zone of the dates in the emails: any PHP time zone name, e.g.
    // 'Europe/Rome', 'Europe/London', 'America/New_York', 'Asia/Tokyo' or
    // 'UTC' (the default if this line is missing). Full list:
    // https://www.php.net/manual/en/timezones.php
    // The date is never ambiguous: the message shows the zone's abbreviation
    // (e.g. 2026-10-04 19:30:00 CEST, CET in winter), and the email's Date
    // header carries the offset from UTC, which your mail client converts.
    'timezone' => 'Europe/Rome',

    // HTTPS is detected automatically (directly, or via X-Forwarded-Proto from
    // a trusted proxy, see 'client_ip'). Set true only if the host terminates
    // HTTPS in front of PHP without telling it (e.g. some shared hosting).
    'force_https' => false,

    // Folder for rate-limit counters and used anti-spam challenges.
    // Created on the first request; the site answers 503 if PHP cannot.
    // A suffix of your own is fine (__DIR__ . '/_app-data-x7k2'): git and
    // bin/lint.sh ignore any _app-data* folder.
    'data_path' => __DIR__ . '/_app-data',

    'mail' => [
        // REQUIRED: how mail is delivered.
        //   'smtp'  — your mail provider's SMTP server (block 'smtp' below);
        //   'brevo' — Brevo's HTTP API (block 'brevo' below): HTTPS on port 443
        //             only, for hosts that block outbound mail ports (e.g. Altervista).
        // Only the chosen block is used and checked; the other can stay as it is.
        'client'    => 'smtp',

        // REQUIRED: your mailbox, where messages arrive.
        'to'        => 'you@example.com',

        // REQUIRED: sender address on your own domain (SPF/DKIM). With Brevo it
        // must be a sender verified in your Brevo account.
        'from'      => 'contact@example.com',

        // Sender NAME shown in your inbox. Placeholders: {name} = visitor's name,
        // {project} = project tag, {site} = site_name.
        // '{name} via {project}' → "Mario Rossi via My Blog".
        'from_name' => '{name} via {project}',

        // Language of the notification YOU receive (any app/lang/<code>.php); the
        // visitor's own language is written inside each message.
        'language'  => 'en',

        // Used when client = 'smtp'.
        'smtp' => [
            'host'        => 'smtp.example.com',  // REQUIRED for smtp
            'port'        => 587,
            'encryption'  => 'tls',               // tls = STARTTLS (587) | ssl = implicit TLS (465) | none (credentials only to localhost)
            'username'    => 'contact@example.com',
            'password'    => 'CHANGE_ME',
            'timeout'     => 15,                  // seconds
            'verify_peer' => true,                // keep true: certificate verification
        ],

        // Used when client = 'brevo'.
        'brevo' => [
            'api_key' => 'CHANGE_ME',             // REQUIRED for brevo: "xkeysib-…" (SMTP & API → API keys)
            'timeout' => 15,                      // seconds
        ],
    ],

    // Optional attachments. php.ini limits (upload_max_filesize, post_max_size,
    // max_file_uploads) win if lower, and the form always shows the real ones.
    // If you raise max_total_bytes, raise LimitRequestBody in public/.htaccess.
    'attachments' => [
        'enabled'         => true,
        'max_files'       => 3,
        'max_file_bytes'  => 4 * 1024 * 1024,     // per file
        'max_total_bytes' => 10 * 1024 * 1024,    // all files together
        // Allowed types, one list per mail client: the list of mail.client is
        // used. extension => the ONLY MIME type accepted for it (checked on the
        // file's bytes, not its name). Brevo accepts a fixed set of extensions
        // (no webp): the service refuses to start if its list has one Brevo
        // would reject.
        'types' => [
            'smtp' => [
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'webp' => 'image/webp',
                'pdf'  => 'application/pdf',
                'txt'  => 'text/plain',
                // 'zip'  => 'application/zip',   // archives can hide malware: enable only if you need them
            ],
            'brevo' => [
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'pdf'  => 'application/pdf',
                'txt'  => 'text/plain',
                // 'zip'  => 'application/zip',   // archives can hide malware: enable only if you need them
            ],
        ],
    ],

    // Form limits (characters, seconds, bytes of text fields).
    'limits' => [
        'name_min'         => 2,
        'name_max'         => 100,
        'email_max'        => 254,
        'message_min'      => 20,
        'message_max'      => 5000,
        'min_fill_seconds' => 4,                  // faster submissions are rejected as bots
        // How long an open form stays valid, at most 14400 (4 h, the CSRF
        // cookie's life). Past it, sending answers "session expired, press
        // Send again" with fresh tokens: nothing typed is lost.
        'max_form_age'     => 7200,
        'post_max_bytes'   => 32768,              // text fields; attachments come on top
    ],

    // Anti-spam proof-of-work solved by the visitor's browser while they type
    // (official ALTCHA library and widget: PBKDF2/SHA-256, 5000 iterations
    // per attempt). It makes bulk
    // sending costly, together with the honeypot, the minimum fill time and the
    // rate limits. It starts when the page loads and runs while the visitor
    // types, so they rarely wait, even on a device that needs 10 s or more.
    // Levels (attempts; time measured on a 4-core computer, several times more
    // on an old phone):
    //   'low'    1000–2500    about 2 s
    //   'medium' 2500–5000    about 4 s
    //   'high'   5000–10000   about 7 s (default, altcha.org's recommendation)
    'altcha' => [
        'level'       => 'high',
        'expires'     => 1200,                     // challenge validity, seconds
        'show_logo'   => true,                     // the widget's ALTCHA logo
        'show_footer' => true,                     // the widget's "Protected by ALTCHA" link
    ],

    // Each rule: [max requests, window in seconds]; windows up to 86400.
    // 'attempt' counts every press of Send, also the ones refused for a field
    // to correct; 'submit' counts the messages that would reach you.
    'rate_limit' => [
        'attempt'       => [[30, 600]],              // per visitor
        'submit'        => [[5, 600], [20, 86400]],  // per visitor
        'submit_global' => [[60, 3600]],             // whole service
        'challenge'     => [[30, 600]],              // per visitor
    ],

    // Visitor IP for rate limiting. Behind a reverse proxy or Cloudflare, set
    // the header carrying the real IP (e.g. 'HTTP_CF_CONNECTING_IP') AND the
    // proxies it may come from: the header is ignored from any other source.
    // Single addresses or CIDR ranges, IPv4 and IPv6: ['203.0.113.7'],
    // ['173.245.48.0/20', '2400:cb00::/32', …] (a CDN publishes its list).
    // Not needed when the server already puts the visitor's address in
    // REMOTE_ADDR (Apache mod_remoteip, nginx real_ip): leave both as they are.
    'client_ip' => [
        'header'          => null,
        'trusted_proxies' => [],
    ],

    // Shown on the privacy page, in the data controller box at the top.
    'privacy' => [
        'controller'       => 'Your Name',        // REQUIRED: data controller
        'address'          => [],                 // optional lines, e.g. ['42046 Reggiolo (RE)']
        'contact_email'    => 'privacy@example.com', // optional: shown once, obfuscated; '' = the form instead
        // Optional: the key in projects.php for questions about this form
        // itself (e.g. 'contact', see projects-sample.php). The privacy
        // page's links to the form then open it with that project and the
        // reason "Privacy" already chosen.
        'project'          => '',
        // How long you keep messages in your mailbox, in months: the GDPR
        // (art. 13) asks to state it, and only you know it ("...no longer
        // than 24 months").
        'retention_months' => 24,
    ],
];
