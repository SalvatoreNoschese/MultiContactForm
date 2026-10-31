<?php

/**
 * bin/tools/config-update.php — a local config.php compared with, and
 * optionally rebuilt on, config-sample.php.
 *
 * Called by bin/lint.sh when a config.php sits in the project root.
 * config.php is a copy of the sample filled in by hand: after an update of
 * the code its comments are the old ones and the keys added since are
 * missing. Rebuilt, it is the current sample with your values in
 * it, each written as you wrote it (__DIR__ . '/x' stays an expression).
 *
 *   php bin/tools/config-update.php [--write] [--define=NAME] [--free=a.b,c] config-sample.php config.php
 *
 * --define names the constant the application defines before it loads
 * config.php: both files stop at their first line without it.
 * --free lists the maps whose keys are yours (donations, attachment types):
 * taken whole from config.php, not key by key from the sample.
 *
 * Output, one line per finding, tab-separated, for lint.sh to word:
 *   SAME                 nothing to do
 *   ADDED   <key>        new in the sample, written with the sample's value
 *   LOST    <key>        not in the sample (not even commented out): the
 *                        rebuild would drop it, so it is refused
 *   TEXT    <n>          lines of comments or layout that differ
 *   WROTE                --write: config.php rewritten
 * Values are never printed: config.php holds passwords and keys.
 *
 * --write writes beside config.php under a hidden name, loads that copy the
 * way PHP loads config.php, and renames it into place only when every value
 * of the old file comes back identical; otherwise it is deleted and nothing
 * changes. Exit status: 0 when SAME or WROTE, 1 when an update is available,
 * 2 on an error or a refusal.
 */

declare(strict_types=1);

$args = array_slice($argv, 1);
$write = false;
$free = [];
$files = [];
foreach ($args as $a) {
    if ($a === "--write") {
        $write = true;
    } elseif (str_starts_with($a, "--define=")) {
        define(substr($a, 9), true);
    } elseif (str_starts_with($a, "--free=")) {
        $free = array_filter(explode(",", substr($a, 7)));
    } else {
        $files[] = $a;
    }
}
[$samplePath, $configPath] = array_pad($files, 2, "");
if (!is_file($samplePath) || !is_file($configPath)) {
    fwrite(STDERR, "usage: config-update.php [--write] [--define=NAME] [--free=a.b,c] <config-sample.php> <config.php>\n");
    exit(2);
}

$load = static function (string $path): array {
    $v = (static fn() => require $path)();
    if (!is_array($v)) {
        fwrite(STDERR, basename($path) . " does not return an array\n");
        exit(2);
    }
    return $v;
};
$sample = $load($samplePath);
$config = $load($configPath);
$sampleText = (string) file_get_contents($samplePath);
$configText = (string) file_get_contents($configPath);

/**
 * The array literal after "return": for every string key, the byte range of
 * its value and, for a nested [ … ], its own entries. Comments and the
 * layout around the values are not part of any range.
 *
 * @return array{0: array<string, array{s: int, e: int, kids: ?array}>, 1: int, 2: int}|null
 *         [entries, start of "[", end after "]"]
 */
$parse = static function (string $src): ?array {
    $tokens = token_get_all($src);
    $n = count($tokens);
    $off = [];
    $pos = 0;
    foreach ($tokens as $i => $t) {
        $off[$i] = $pos;
        $pos += strlen(is_array($t) ? $t[1] : $t);
    }
    $off[$n] = $pos;
    $text = static fn(int $i): string => is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
    $skip = static fn(int $i): bool => is_array($tokens[$i])
        && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    $next = static function (int $i) use ($n, $skip): int {
        while ($i < $n && $skip($i)) {
            $i++;
        }
        return $i;
    };
    // From the "[" at $i: [entries, index after the matching "]"].
    $array = static function (int $i) use (&$array, $n, $text, $next, $skip, $tokens, $off): array {
        $entries = [];
        $i++;
        while (true) {
            $i = $next($i);
            if ($i >= $n || $text($i) === "]") {
                return [$entries, $i + 1];
            }
            $key = null;
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CONSTANT_ENCAPSED_STRING) {
                $j = $next($i + 1);
                if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW) {
                    $raw = $tokens[$i][1];
                    $key = $raw[0] === "'"
                        ? strtr(substr($raw, 1, -1), ["\\'" => "'", "\\\\" => "\\"])
                        : stripcslashes(substr($raw, 1, -1));
                    $i = $next($j + 1);
                }
            }
            $start = $i;
            $kids = null;
            if ($text($i) === "[") {
                [$kids, $i] = $array($i);
            } else {
                $depth = 0;
                for (; $i < $n; $i++) {
                    $c = $text($i);
                    if ($c === "(" || $c === "[" || $c === "{") {
                        $depth++;
                    } elseif ($c === ")" || $c === "]" || $c === "}") {
                        if ($depth === 0) {
                            break;
                        }
                        $depth--;
                    } elseif ($c === "," && $depth === 0) {
                        break;
                    }
                }
            }
            $end = $i;
            while ($end > $start && $skip($end - 1)) {
                $end--;
            }
            if ($key !== null) {
                $entries[$key] = ["s" => $off[$start], "e" => $off[$end], "kids" => $kids];
            }
            $i = $next($i);
            if ($i < $n && $text($i) === ",") {
                $i++;
            }
        }
    };
    for ($i = 0; $i < $n; $i++) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_RETURN) {
            $j = $next($i + 1);
            if ($j < $n && $text($j) === "[") {
                [$entries, $after] = $array($j);
                return [$entries, $off[$j], $off[$after]];
            }
        }
    }
    return null;
};

$sTree = $parse($sampleText);
$cTree = $parse($configText);
if ($sTree === null || $cTree === null) {
    fwrite(STDERR, "no \"return [ … ];\" found\n");
    exit(2);
}

$edits = [];   // [start, end, text] on the sample's text
$added = [];
$lost = [];
$src = static fn(array $e): string => substr($configText, $e["s"], $e["e"] - $e["s"]);

// Walks one array level: $s/$c the values, $st/$ct the parsed entries,
// [$from, $to] the byte range of this array in the sample.
$walk = static function (array $s, array $c, array $st, ?array $ct, string $path, int $from, int $to)
    use (&$walk, &$edits, &$added, &$lost, $free, $src, $sampleText): void {
    foreach ($s as $k => $sv) {
        $key = $path . $k;
        if (!is_string($k) || !isset($st[$k])) {
            continue;
        }
        if (!array_key_exists($k, $c)) {
            $added[] = $key;
            continue;
        }
        $cv = $c[$k];
        $branch = is_array($sv) && $sv !== [] && !array_is_list($sv) && !in_array($key, $free, true);
        if ($branch && is_array($cv) && $st[$k]["kids"] !== null) {
            $walk($sv, $cv, $st[$k]["kids"], $ct[$k]["kids"] ?? null, $key . ".", $st[$k]["s"], $st[$k]["e"]);
            continue;
        }
        if ($cv === $sv) {
            continue; // the sample's text stays: the same value, its own way of writing it
        }
        $text = isset($ct[$k]) ? $src($ct[$k]) : var_export($cv, true);
        $edits[] = [$st[$k]["s"], $st[$k]["e"], $text];
    }
    foreach ($c as $k => $cv) {
        if (!is_string($k) || array_key_exists($k, $s)) {
            continue;
        }
        // A key the sample shows commented out ("// 'privacy_email' => '',")
        // takes that line's place.
        $re = "#^([ \t]*)//[ \t]*'" . preg_quote($k, "#") . "'[ \t]*=>[^\n]*#m";
        $area = substr($sampleText, $from, $to - $from);
        if (preg_match($re, $area, $m, PREG_OFFSET_CAPTURE)) {
            $text = isset($ct[$k]) ? $src($ct[$k]) : var_export($cv, true);
            $at = $from + $m[0][1];
            $edits[] = [$at, $at + strlen($m[0][0]), $m[1][0] . var_export($k, true) . " => " . $text . ","];
            continue;
        }
        $lost[] = $path . $k;
    }
};
$walk($sample, $config, $sTree[0], $cTree[0], "", $sTree[1], $sTree[2]);

usort($edits, static fn($a, $b) => $b[0] <=> $a[0]);
$new = $sampleText;
foreach ($edits as [$s, $e, $t]) {
    $new = substr($new, 0, $s) . $t . substr($new, $e);
}

if ($new === $configText) {
    echo "SAME\n";
    exit(0);
}
foreach ($added as $k) {
    echo "ADDED\t$k\n";
}
foreach ($lost as $k) {
    echo "LOST\t$k\n";
}
// Lines present on one side only, counted as a multiset.
$a = array_count_values(explode("\n", $configText));
$b = array_count_values(explode("\n", $new));
$diff = 0;
foreach ($a + $b as $line => $_) {
    $diff += abs(($a[$line] ?? 0) - ($b[$line] ?? 0));
}
echo "TEXT\t" . intdiv($diff + 1, 2) . "\n";

if (!$write) {
    exit(1);
}
if ($lost !== []) {
    fwrite(STDERR, "not written: the keys marked LOST would be dropped\n");
    exit(2);
}

// Beside config.php, hidden, so __DIR__ means the same in the copy; loaded
// back and compared before it replaces anything.
$tmp = dirname($configPath) . "/." . basename($configPath) . "." . bin2hex(random_bytes(6)) . ".tmp";
$mode = fileperms($configPath) & 0777;
// No LOCK_EX: the name is random, so nothing else writes this file, and
// some filesystems refuse the lock (Android shared storage).
if (file_put_contents($tmp, $new) !== strlen($new)) {
    @unlink($tmp);
    fwrite(STDERR, "cannot write beside $configPath\n");
    exit(2);
}
@chmod($tmp, $mode);
$flatten = static function (array $a, string $p = "") use (&$flatten): array {
    $out = [];
    foreach ($a as $k => $v) {
        if (is_array($v) && $v !== [] && !array_is_list($v)) {
            $out += $flatten($v, $p . $k . ".");
        } else {
            $out[$p . $k] = $v;
        }
    }
    return $out;
};
$check = (static fn() => require $tmp)();
$before = $flatten($config);
$after = is_array($check) ? $flatten($check) : [];
$same = $after !== [];
foreach ($before as $k => $v) {
    if (!array_key_exists($k, $after) || $after[$k] !== $v) {
        $same = false;
    }
}
if (!$same) {
    @unlink($tmp);
    fwrite(STDERR, "not written: the rebuilt file does not give back every value\n");
    exit(2);
}
if (!rename($tmp, $configPath)) {
    @unlink($tmp);
    fwrite(STDERR, "cannot replace $configPath\n");
    exit(2);
}
if (function_exists("opcache_invalidate")) {
    @opcache_invalidate($configPath, true);
}
echo "WROTE\n";
exit(0);
