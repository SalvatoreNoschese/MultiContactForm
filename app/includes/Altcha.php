<?php

declare(strict_types=1);

namespace MCF;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha as AltchaLib;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Throwable;

defined('MCF_APP') || exit;

/**
 * Anti-spam proof-of-work with the official ALTCHA library
 * (app/vendor/altcha-lib-php) and widget (public/assets/vendor/altcha),
 * protocol v2: PBKDF2/SHA-256, the scheme altcha.org recommends.
 *
 * The browser tries counters until the derived key matches the challenge, so
 * the work grows with the level's counter range; verifying costs one HMAC
 * (keySignature). The library checks signature and expiry; this class adds
 * the one thing it leaves to us: each solution is accepted once.
 *
 * Updates: bin/fetch-vendor.sh and README.md § Third-party code.
 */
final class Altcha
{
    /** PBKDF2 iterations per attempt, as recommended by altcha.org. */
    public const COST = 5000;
    /**
     * Counter range per level: the average number of attempts. "high", the
     * default, is altcha.org's recommendation: a few seconds on a current
     * device, much more on an old phone, but solving starts when the page
     * loads, so it is usually done before the visitor has finished writing.
     */
    public const LEVELS = [
        'low'    => [1000, 2500],
        'medium' => [2500, 5000],
        'high'   => [5000, 10000],
    ];

    public static function create(): array
    {
        [$min, $max] = self::LEVELS[(string) Config::get('altcha.level')];
        return self::lib()->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2(),
            cost: self::COST,
            // Required: without a counter the library falls back to a fixed
            // one-byte prefix, a challenge of ~256 attempts.
            counter: random_int($min, $max),
            expiresAt: time() + (int) Config::get('altcha.expires'),
        ))->toArray();
    }

    /** Verifies and redeems a payload. Returns false on any failure, including replay. */
    public static function verify(mixed $payload): bool
    {
        if (!is_string($payload) || $payload === '' || strlen($payload) > 4096) {
            return false;
        }
        try {
            $options = new VerifySolutionOptions(payload: $payload, algorithm: new Pbkdf2());
            $parsed = Payload::fromBase64($payload);
            $params = $parsed->challenge->parameters;
            // Only our own kind of challenge: a signed challenge always is,
            // this keeps a future library default from widening what we accept.
            if ($params->algorithm !== 'PBKDF2/SHA-256' || $params->cost !== self::COST || !is_int($params->expiresAt)) {
                return false;
            }
            if (!self::lib()->verifySolution($options)->verified) {
                return false;
            }
        } catch (Throwable) {
            // A malformed payload makes the library throw: a failed check, not a 500.
            return false;
        }
        // The signature covers the random nonce and salt: unique per challenge.
        return self::redeem((string) $parsed->challenge->signature, $params->expiresAt);
    }

    private static function lib(): AltchaLib
    {
        return new AltchaLib(
            hmacSignatureSecret: Security::key('altcha'),
            hmacKeySignatureSecret: Security::key('altcha-key'),
        );
    }

    /** Atomically records the challenge as used; false if it already was. */
    private static function redeem(string $signature, int $expires): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            return false;
        }
        $file = Storage::dir('altcha') . '/' . $signature;
        $fh = @fopen($file, 'x');
        if ($fh === false) {
            return false;
        }
        fclose($fh);
        @touch($file, $expires);
        Storage::purge('altcha', time());
        return true;
    }
}
