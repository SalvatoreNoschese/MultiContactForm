<?php

declare(strict_types=1);

namespace MCF;

use RuntimeException;

defined('MCF_APP') || exit;

/**
 * File-based sliding-window rate limiter.
 *
 * Each bucket/key pair is a small JSON file holding request timestamps only.
 * Keys are already keyed hashes (see Security::clientKey()), and the file name
 * is hashed again, so no IP address ever reaches the disk. Files expire after
 * at most 24 hours.
 */
final class RateLimiter
{
    private const MAX_WINDOW = 86400;

    /**
     * Records a hit and reports whether it is allowed.
     *
     * @param array<int, array{0:int,1:int}> $rules list of [max, windowSeconds]
     * @return int 0 if allowed, otherwise seconds until the next allowed attempt
     */
    public static function hit(string $bucket, string $key, array $rules): int
    {
        if ($rules === []) {
            return 0;
        }
        $now = time();
        $dir = Storage::dir('ratelimit');
        $file = $dir . '/' . hash('sha256', $bucket . "\0" . $key) . '.json';

        $fh = fopen($file, 'c+');
        if ($fh === false) {
            throw new RuntimeException('Rate limiter data file unavailable.');
        }
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $hits = json_decode($raw === false ? '' : $raw, true);
            $hits = is_array($hits) ? array_values(array_filter($hits, 'is_int')) : [];
            $hits = array_values(array_filter($hits, static fn (int $t) => $t > $now - self::MAX_WINDOW));

            $retry = 0;
            foreach ($rules as [$max, $window]) {
                $window = min((int) $window, self::MAX_WINDOW);
                $inWindow = array_values(array_filter($hits, static fn (int $t) => $t > $now - $window));
                if (count($inWindow) >= (int) $max) {
                    // Oldest hit that must leave the window before a new one fits.
                    $oldest = $inWindow[count($inWindow) - (int) $max];
                    $retry = max($retry, $oldest + $window - $now, 1);
                }
            }
            if ($retry === 0) {
                $hits[] = $now;
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($hits));
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }

        Storage::purge('ratelimit', $now - self::MAX_WINDOW);
        return $retry;
    }
}
