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
 * README.md § Third-party code, one section per library.
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
