# Changelog

All notable changes to MultiContactForm are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2026-11-20

### Changed

- ALTCHA widget updated from 3.5.0 to 3.7.0.
- altcha-lib-php updated from 2.3.0 to 2.4.0.

### Fixed

- README: Ubuntu Sans and Ubuntu Sans Mono are licensed under the Ubuntu
  Font Licence 1.0, not the SIL OFL 1.1.

## [1.0.1] - 2026-11-08

### Added

- The interface is set in Ubuntu Sans and Ubuntu Sans Mono (1.006,
  OFL-1.1), self-hosted in `public/assets/fonts/`, with Ubuntu Sans
  preloaded on every page. Characters outside the fonts' Latin subset fall
  back to the system font.

### Changed

- CSP: `font-src 'self'`. `public/.htaccess` serves `.woff2`.
- `?view=light|dark` is the theme for this visit, not saved: the new
  `public/assets/js/theme.js`, loaded in the `<head>` before anything is
  drawn, keeps it in `sessionStorage` (this tab) and takes it out of the
  address, other parameters and the `#fragment` kept. Internal links no
  longer carry it, so addresses stay clean and a shared one does not carry
  the theme. Theme shown: this visit's, then the saved choice, then the
  device's. The 🌓 toggle saves the choice and ends this visit's theme.
- The top bar always has both toggles and CSS shows the right one, also
  for this visit's theme, which the server cannot know.
- The privacy page lists this visit's theme (`mcf.view`, sessionStorage)
  under the cookies, with its state and a delete link, filled in by ui.js:
  only the browser knows it.
- `site_url` accepts `{lang}` and `{theme}`: a `{theme}` parameter is added
  only when the link is followed, with the theme on screen.

### Fixed

- The German and English flags filled their 3:2 box only in part (drawn
  5:3 and 2:1): a gap showed inside the frame. Both are now 3:2, cropped
  at the sides, not stretched.
- "Back to …" from a site that is not a project's says "Go back" instead
  of the site's host name, often too long for the button.
- "Reset" (shown when there is no page to go back to) is inactive, and grey, until
  there is something to reset: before, it reloaded the page for nothing.
- `theme-color` follows a device theme change while the page is open.
- A page restored from the back/forward cache re-aligns its theme without
  reloading.

## [1.0.0] - 2026-10-31

First public release.

- One contact form for several sites: `#project` and `#project:reason` links.
  "Back to <project>", under the form and after sending (optionally by itself
  after a countdown), returns to the very page the visitor came from; without
  one, "Reset" starts the form over.
- `projects.php`: name, site and a list of built-in, translated reasons, each
  with optional `text`, `url`, `rating` and `publish` fields (`publish` is
  opt-in consent to publish the message, and the privacy page then explains
  it).
- English, Italian, Spanish, French and German, discovered from `app/lang/`,
  each at its own address (`/es/`, `/es/privacy.php`), the 404 page included.
- Light and dark theme. Links can show a language (`/es/`) and a theme
  (`?view=dark`) without storing either: cookies are set only from the form's
  own menu and toggle.
- Accessible error summary, updated live.
- Delivery through SMTP or the Brevo API (`mail.client`), with optional
  attachments checked on their real bytes. No mail ever goes to the address
  the visitor typed: the confirmation is on the page.
- Anti-spam with the official ALTCHA library (altcha-lib-php 2.3.0) and widget
  (3.5.0), vendored unmodified, at three levels (low, medium, high); honeypot,
  minimum fill time, per-client and global rate limits (the global one counted
  only after the proof-of-work).
- CSRF protection without sessions, a strict CSP with no inline code, and in
  `public/` an allow-list of served files and `index.php` directory guards.
- Complete GDPR privacy page with a live cookie table.
- Footer with "© <year> MultiContactForm" linking to the repository (never the
  version), a short line of yours (`footer` in `app/lang/<code>.php`) and the
  privacy link.
- The widget's ALTCHA logo and "Protected by ALTCHA" link can be turned off
  (`altcha.show_logo`, `altcha.show_footer`).
- Optional visit statistics with a self-hosted Matomo, always cookieless
  (`matomo.url`, `matomo.site_id`): the privacy page describes it by itself,
  and the CSP opens to Matomo's origin only.
- `bin/make-release.sh` writes the release zip, as GitHub will build it from
  the tag, to test an install before tagging.
- `bin/fetch-vendor.sh` downloads new ALTCHA releases into an archive, with
  the npm integrity verified, and installs nothing.
- `bin/lint.sh`: syntax, strict types, language files, settings (samples and
  your own), CSP invariants, vendored versions, unused language keys and CSS
  classes, a JavaScript smoke test, PHPStan and Stylelint (latest locally,
  pinned in CI, with an offer to raise the pin), and that GitHub's *Source
  code* archive of a tag holds the site only.

[1.0.1]: https://github.com/SalvatoreNoschese/MultiContactForm/releases/tag/v1.0.1
[1.0.0]: https://github.com/SalvatoreNoschese/MultiContactForm/releases/tag/v1.0.0
