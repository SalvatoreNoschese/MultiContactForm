<?php

declare(strict_types=1);

namespace MCF;

use RuntimeException;

defined('MCF_APP') || exit;

/**
 * The data folder (rate-limit counters, used challenges). Outside public/.
 *
 * Not shipped: the folder ('data_path', _app-data/ by default) is created
 * on the first request, with its .htaccess, and again whenever it is deleted.
 * Every folder it makes gets the index.php stub the shipped ones carry.
 */
final class Storage
{
    public static function dir(string $sub): string
    {
        $base = rtrim((string) Config::get('data_path'), '/');
        $dir = $sub === '' ? $base : $base . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create $dir: PHP needs write access to its parent folder (or create it yourself).");
        }
        // Missing on the first request, or deleted with the folder: written
        // again. A backstop only, the folder is never under public/.
        if (!is_file($base . '/.htaccess')) {
            @file_put_contents($base . '/.htaccess', self::HTACCESS, LOCK_EX);
        }
        foreach (array_unique([$base, $dir]) as $folder) {
            if (!is_file($folder . '/index.php')) {
                @file_put_contents($folder . '/index.php', "<?php // Silence...\n", LOCK_EX);
            }
        }
        return $dir;
    }

    /**
     * Checked on every request by bootstrap.php, like the configuration: a
     * data folder that cannot be created or written stops the service (503)
     * instead of breaking the form at the first challenge.
     */
    public static function check(): void
    {
        $dir = self::dir('');
        if (!is_writable($dir)) {
            throw new RuntimeException("$dir is not writable by PHP.");
        }
    }

    private const HTACCESS = <<<'HTACCESS'
        # Data (rate-limit counters, used challenges): never serve anything from here.
        Require all denied

        HTACCESS;

    /**
     * Deletes the files in $sub whose mtime is older than $before.
     *
     * Runs at most once every $interval seconds per directory (marker file
     * ".purged"), whatever the traffic: the folder never accumulates expired
     * entries and busy periods do not rescan the directory on every request.
     */
    public static function purge(string $sub, int $before, int $interval = 300): void
    {
        $dir = self::dir($sub);
        $marker = $dir . '/.purged';
        $last = @filemtime($marker);
        if ($last !== false && $last > time() - $interval) {
            return;
        }
        @touch($marker);
        foreach (glob($dir . '/*') ?: [] as $file) { // glob('*') skips the dot marker
            if (basename($file) === 'index.php') {
                continue;
            }
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $before) {
                @unlink($file);
            }
        }
    }
}
