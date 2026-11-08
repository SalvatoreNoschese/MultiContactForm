<?php

declare(strict_types=1);

namespace MCF;

defined('MCF_APP') || exit;

/**
 * Vendored third-party libraries: the single source for their versions.
 * bin/fetch-vendor.sh compares the downloaded releases against it;
 * bin/lint.sh checks that the table in README.md (§ Third-party code) agrees.
 *
 * Every library is byte-identical to its upstream release (the README.md in
 * its folder is the upstream one); what to watch for when updating is in
 * README.md § Third-party code, one section per library. The fonts are
 * listed too, unmodified, but taken from Google Fonts rather than from the
 * upstream package (see their entries).
 */
final class Vendor
{
    public const LIBRARIES = [
        [
            // public/assets/vendor/altcha/
            'name'    => 'ALTCHA (widget)',
            'version' => '3.5.0',
            'url'     => 'https://github.com/altcha-org/altcha',
            'license' => 'MIT',
            'role'    => 'altcha',
        ],
        [
            // app/vendor/altcha-lib-php/
            'name'    => 'altcha-lib-php',
            'version' => '2.3.0',
            'url'     => 'https://github.com/altcha-org/altcha-lib-php',
            'license' => 'MIT',
            'role'    => 'altcha_php',
        ],
        [
            // public/assets/fonts/: Google Fonts' Latin subset, variable
            // (wght), unmodified. Not in vendor/ because it is not the
            // upstream package byte for byte: LICENSE.txt there is a pointer.
            'name'    => 'Ubuntu Sans',
            'version' => '1.006', // interface font; see README.md § Fonts
            'url'     => 'https://github.com/canonical/Ubuntu-Sans-fonts',
            'license' => 'OFL-1.1',
            'role'    => 'ubuntu_sans',
        ],
        [
            // public/assets/fonts/: as above
            'name'    => 'Ubuntu Sans Mono',
            'version' => '1.006', // code font; see README.md § Fonts
            'url'     => 'https://github.com/canonical/Ubuntu-Sans-Mono-fonts',
            'license' => 'OFL-1.1',
            'role'    => 'ubuntu_sans_mono',
        ],
    ];

    public static function version(string $role): ?string
    {
        foreach (self::LIBRARIES as $lib) {
            if ($lib['role'] === $role) {
                return $lib['version'];
            }
        }
        return null;
    }
}
