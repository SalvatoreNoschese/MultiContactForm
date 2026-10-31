# Contributing to MultiContactForm

Thanks for taking the time. The project is maintained by one person, so the
most useful contributions are the ones that are easy to verify.

## Before you open an issue

- Search existing issues first.
- For anything security-relevant, **do not open a public issue**; follow
  [SECURITY.md](SECURITY.md) instead.
- Include your PHP version, `mail.client` (smtp or brevo), web server and
  layout (document root on `public/`, or a fixed web root).

## Development setup

There is no build step and no package manager. Run it under Apache (or
LiteSpeed) as on a real host, `public/` as the document root, since the pages
per language and the error page come from `public/.htaccess`:

```sh
cp config-sample.php config.php      # fill in the REQUIRED values
cp projects-sample.php projects.php
```

Serve it over HTTPS locally too, as the service refuses plain HTTP: a
certificate from [mkcert](https://github.com/FiloSottile/mkcert) for
`localhost` is enough. Behind a local proxy that terminates TLS, set
`'force_https' => true`.

For mail without a real server, any SMTP sink on localhost works (port and
`encryption => 'none'` in `config.php`).

Do not add a Composer or npm dependency without discussing it in an issue
first. (Stylelint, which `bin/lint.sh` offers to install, is developer
tooling outside the site, like PHPStan: it never ships.) Bundling libraries by hand is a choice, not an oversight: see
[README.md § Third-party code](README.md#third-party-code).

## Before you open a pull request

Run the checks:

```sh
./bin/lint.sh
```

PHP syntax, `declare(strict_types=1)` and the `MCF_APP` guard, JavaScript syntax, language files,
settings, CSP invariants, stray files, vendored versions, hygiene, orphan
language keys and CSS classes, files cited in the docs, a JavaScript smoke
test, PHPStan and Stylelint (each offered for download the first time;
Stylelint needs Node.js and npm). CI runs the same
script on PHP 8.1 to 8.5, so a clean run locally means a clean run there.

Then:

- Keep the diff focused on one thing.
- Match the surrounding style (`.editorconfig`): 4 spaces in PHP, JavaScript
  and CSS, tabs in shell scripts. Every PHP file opens with the PSR-12
  header: `<?php`, the file's doc comment, `declare(strict_types=1);`, the
  `namespace` and `use` lines, one blank line after each block. Under `app/`
  and in the two samples the first statement after it is
  `defined('MCF_APP') || exit;` (the entry points in `public/` define it
  there). The classes in `app/includes/` are in the `MCF` namespace, with
  their doc comment above the class; whoever uses one imports it
  (`use MCF\Config;`). Every folder under `app/` and `public/` has an
  `index.php` silence stub. Language files: single quotes, `’` for
  apostrophes. `bin/lint.sh` checks all of it.
- Comments explain *why*, not *what*.
- Any user-facing string goes in all five language files under `app/lang/`.
- No inline script, style attribute or event handler: the CSP forbids them.
- Update `CHANGELOG.md` under an `## [Unreleased]` heading.

## Things that will be declined

- Loading any asset from a CDN, or any third-party request at run time.
- Analytics other than the optional cookieless Matomo, tracking, or storing
  what the privacy page says is not stored.
- Anything that weakens the CSP.
- A database: the service keeps nothing but short-lived counters on disk.

## Translations

New languages are welcome. Copy `app/lang/en.php` to `app/lang/<code>.php`, translate
the values (never the keys) and keep the keys in the same order. Words in
braces, such as `{min}` or `{project}`, are filled in by the code: keep each
one exactly as written. `./bin/lint.sh` checks that every language has the
same ones. A flag in `public/assets/img/flags/<code>.svg` is shown when it
exists.

Each key holds one text, nothing nested: the privacy notice uses keys such
as `privacy.data.title` and `privacy.data.1`, `.2` for the items of a list.
A text left out is shown in English. The `=>` of the whole file line up
under the longest key.

## Cutting a release

1. Update `CHANGELOG.md`: move `## [Unreleased]` to `## [x.y.z] - <date>`.
2. Update `MCF_VERSION` in `app/includes/bootstrap.php` to the same number
   (`bin/lint.sh` fails if they disagree). It is never shown on a page: an
   admin reads it in that file.
3. Run `./bin/lint.sh` (among the rest, it checks that the *Source code*
   archive will hold the site only), then `./bin/make-release.sh` and test an
   install from the zip it writes in `bin/dist/`: the same files GitHub's
   *Source code* zip of the tag will hold.
4. On github.com: **Releases → Draft a new release**, tag `vx.y.z`, and
   publish. There is nothing to attach: GitHub's own *Source code* zip of the
   tag is the package, and `.gitattributes` (`export-ignore`) keeps the
   documentation, `bin/` and CI out of it.
