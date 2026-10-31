#!/bin/sh
#
# bin/lint.sh — pre-release checks for MultiContactForm. Run from anywhere.
#
#   ./bin/lint.sh                  run every check
#   ./bin/lint.sh --update-config  only rebuild a local config.php on
#                                  config-sample.php, your values kept (4)
#
# The option is an action, not a check: it does its job and stops. A normal
# run on a terminal offers it as a question when it is needed; the option is
# for when there is no one to answer (CI, a pipe, a script).
#
# Requires php. Optional: node (JavaScript syntax and smoke test), and
# PHPStan and Stylelint (14, each offered as a download when missing;
# Stylelint needs node and npm). On a terminal, 14 also looks up their latest
# releases once a day (MCF_LINT_OFFLINE=1 skips it); nothing else touches the
# network, and nothing is downloaded unless you accept a prompt.
# POSIX sh, so it also runs under Termux's dash/ash.
#
# The checks skip bin/tools/stylelint/ (Stylelint's node_modules, installed
# by 14, which would otherwise count as stray files and leftovers).
#
# Exit status: 0 if everything passed, 1 otherwise (warnings do not fail).

set -u

UPDATE_CONFIG=0
for _arg in "$@"; do
	case "$_arg" in
		--update-config) UPDATE_CONFIG=1 ;;
		*) printf 'unknown option: %s (only --update-config)\n' "$_arg" >&2; exit 2 ;;
	esac
done

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT" || exit 1

if ! command -v php >/dev/null 2>&1; then
	printf 'ERROR: php not found in PATH (required by this script).\n' >&2
	exit 1
fi

FAILED=0
# Every failure, reprinted together at the end: on a phone the output is
# several screens long and the FAIL lines are easy to scroll past.
FAIL_LIST=""
say() { printf '%s\n' "$*"; }
fail() {
	printf '  FAIL  %s\n' "$*"
	FAILED=1
	# First line only: a message carrying a whole PHP error stays readable.
	FAIL_LIST="${FAIL_LIST}  - $(printf '%s\n' "$*" | head -n 1)
"
}
warn() { printf '  %sWARN%s  %s\n' "$Y" "$R" "$*"; }

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
	B=$(printf '\033[1m'); Y=$(printf '\033[33m'); R=$(printf '\033[0m')
else
	B=''; Y=''; R=''
fi

# Runtime data and local settings are not part of the code, nor is
# Stylelint's node_modules.
php_files() { find . \( -path './_app-data*' -o -path ./bin/tools/stylelint \) -prune -o -name '*.php' -print | sort; }

# ── Shared by the check and the action ───────────────────────────────────────
# The local config.php compared with the sample (4). The helper in bin/tools/
# prints tab-separated lines and never a secret.
CFG_ARGS="--define=MCF_APP --free=attachments.types.smtp,attachments.types.brevo config-sample.php config.php"
cfg_check() {
	# shellcheck disable=SC2086 # the arguments split on purpose
	CFG_OUT=$(php bin/tools/config-update.php $CFG_ARGS 2>&1)
	CFG_RC=$?
}
cfg_write() {
	# shellcheck disable=SC2086
	if CFG_W=$(php bin/tools/config-update.php --write $CFG_ARGS 2>&1); then
		say "      config.php    updated from the sample"
	else
		say "      ${Y}$CFG_W${R}"
		return 1
	fi
}
cfg_show() {
	printf '%s\n' "$CFG_OUT" | while IFS='	' read -r ST K; do
		case "$ST" in
			ADDED)   say "        new key      $K (the sample's value)" ;;
			LOST)    say "        ${Y}not kept${R}     $K (not in the sample)" ;;
			TEXT)    say "        $K line(s) of comments or layout" ;;
		esac
	done
}

# ── The action ───────────────────────────────────────────────────────────────
# --update-config does its one job and stops: the checks are a separate,
# normal run.
if [ "$UPDATE_CONFIG" -eq 1 ]; then
	ACT_RC=0
	if [ ! -f config.php ]; then
		say "      config.php    absent, nothing to update"
	else
		cfg_check
		case "$CFG_RC" in
			0) say "      config.php    already matches the sample" ;;
			1) say "      config.php    older than the sample:"
			   cfg_show
			   if printf '%s\n' "$CFG_OUT" | grep -q '^LOST'; then
			       say "      not updated while a key is not kept: remove it, or add it to config-sample.php"
			       ACT_RC=1
			   else
			       cfg_write || ACT_RC=1
			   fi ;;
			*) say "      ${Y}config.php: $(printf '%s\n' "$CFG_OUT" | head -n 1)${R}"; ACT_RC=1 ;;
		esac
	fi
	exit "$ACT_RC"
fi

say "${B}MultiContactForm — pre-release checks${R}  ($ROOT)"
say ""

# ── 1. PHP files ─────────────────────────────────────────────────────────────
say "${B}[1/14] PHP files${R}"
# The loops below split on whitespace: only safe while no path contains any.
if php_files | grep -q '[[:space:]]'; then
	fail "a .php path contains whitespace; rename it before relying on this script"
fi
COUNT=0; BAD=0
for f in $(php_files); do
	COUNT=$((COUNT + 1))
	OUT=$(php -l "$f" 2>&1) || { printf '  %s\n' "$OUT"; BAD=$((BAD + 1)); }
done
[ "$BAD" -gt 0 ] && fail "$BAD of $COUNT PHP files failed to parse" || say "      php -l        OK — $COUNT files, PHP $(php -r 'echo PHP_VERSION;')"

# Every PHP file opens with the PSR-12 header (CONTRIBUTING.md), each block
# followed by one blank line: <?php, the file docblock if any,
# declare(strict_types=1), the namespace and use lines if any, and then
#   defined('MCF_APP') || exit;    as the first statement
# in all of app/ and the two samples (and so config.php and projects.php
# copied from them). The four entry points in public/ define MCF_APP there
# instead, and bin/ is tooling that never ships, with no guard. Not in scope:
# the vendored ALTCHA library (byte-identical to its release), the index.php
# silence stubs (one line, no code) and your own config.php and projects.php.
HEADER_OUT=$(for f in $(php_files); do
	case "$f" in ./config.php|./projects.php|./app/vendor/*) continue ;; esac
	[ "$(basename "$f")" = index.php ] && [ "$(cat "$f")" = '<?php // Silence...' ] && continue
	case "$f" in
		./public/*) echo "define $f" ;;
		./bin/*) echo "none $f" ;;
		*) echo "guard $f" ;;
	esac
done | php bin/tools/file-header.php MCF_APP)
if [ -n "$HEADER_OUT" ]; then
	printf '%s\n' "$HEADER_OUT"
	fail "$(printf '%s\n' "$HEADER_OUT" | wc -l | tr -d ' ') file(s) without the PSR-12 header and guard"
else
	say "      headers       OK — PSR-12, then defined('MCF_APP') || exit"
fi
say ""

# ── 2. JavaScript syntax ─────────────────────────────────────────────────────
say "${B}[2/14] node --check${R}"
if command -v node >/dev/null 2>&1; then
	BAD=0
	for f in public/assets/js/*.js; do
		node --check "$f" 2>/dev/null || { fail "$f: syntax error"; BAD=1; }
	done
	[ "$BAD" -eq 0 ] && say "      OK"
else
	warn "node not found: JavaScript syntax not checked"
fi
say ""

# ── 3. Language files ────────────────────────────────────────────────────────
# Every app/lang/<code>.php: a flat array of texts, no key unknown to the
# English fallback (a typo would be silently ignored) and the same
# {placeholders} as English (a missing one would drop a value from the
# message). Missing keys only warn: they fall back to English by design.
say "${B}[3/14] app/lang/${R}"
OUT=$(php -r '
define("MCF_APP", true);
$en = require "app/lang/en.php";
$ph = static function (string $s): array { preg_match_all("/\{[a-z_]+\}/", $s, $m); $m = array_unique($m[0]); sort($m); return $m; };
$ref = $en;
foreach (glob("app/lang/*.php") as $file) {
    $code = basename($file, ".php");
    if ($code === "index") continue; // the silence stub
    if (!preg_match("/^[a-z]{2,3}$/", $code)) { echo "FAIL $file: file name must be a 2-3 letter language code\n"; continue; }
    $l = require $file;
    if (!is_array($l) || !is_string($l["language.name"] ?? null)) { echo "FAIL $file: must return [\"language.name\" => …, key => text, …]\n"; continue; }
    $mine = $l;
    foreach ($mine as $k => $v) if (!is_string($v)) { echo "FAIL $file: $k is not a text (one key, one text: no nested arrays)\n"; unset($mine[$k]); }
    foreach (array_diff_key($mine, $ref) as $k => $_) echo "FAIL $file: unknown key $k\n";
    foreach (array_intersect_key($mine, $ref) as $k => $v) {
        if ($ph($v) !== $ph($ref[$k])) echo "FAIL $file: $k placeholders " . implode(" ", $ph($v)) . " ≠ English " . implode(" ", $ph($ref[$k])) . "\n";
    }
    $missing = count(array_diff_key($ref, $mine));
    echo $missing ? "WARN $file ({$l["language.name"]}): $missing keys missing, shown in English\n" : "OK $file ({$l["language.name"]}): complete\n";
}' 2>&1)
printf '%s\n' "$OUT" | while IFS= read -r line; do
	case "$line" in
		FAIL*) printf '  FAIL  %s\n' "${line#FAIL }" ;;
		WARN*) printf '  %sWARN%s  %s\n' "$Y" "$R" "${line#WARN }" ;;
		OK*)   printf '      %s\n' "${line#OK }" ;;
		*)     printf '  FAIL  %s\n' "$line" ;;
	esac
done
# The loop above runs in a pipe's subshell: the failures join the final list here.
LANG_FAILS=$(printf '%s\n' "$OUT" | sed -n 's/^FAIL //p')
if printf '%s\n' "$OUT" | grep -q 'Fatal\|Parse error'; then
	LANG_FAILS="${LANG_FAILS:+$LANG_FAILS
}app/lang/: PHP error while loading (see above)"
fi
if [ -n "$LANG_FAILS" ]; then
	FAILED=1
	FAIL_LIST="${FAIL_LIST}$(printf '%s\n' "$LANG_FAILS" | sed 's/^/  - /')
"
fi
# One quoting style: single quotes, as in the rest of the code, with the
# typographic apostrophe (’) inside the texts, so no string needs an escape.
LQ_BAD=$(php -r '
	foreach (glob("app/lang/*.php") as $f) {
		if (basename($f) === "index.php") {
			continue;
		}
		foreach (token_get_all((string) file_get_contents($f)) as $t) {
			if (!is_array($t)) {
				continue;
			}
			if ($t[0] === T_CONSTANT_ENCAPSED_STRING && $t[1][0] !== "\x27") {
				echo basename($f), ":", $t[2], ": double-quoted string\n";
			} elseif ($t[0] === T_CONSTANT_ENCAPSED_STRING && str_contains($t[1], "\\\x27")) {
				echo basename($f), ":", $t[2], ": escaped apostrophe (write ’)\n";
			} elseif (in_array($t[0], [T_ENCAPSED_AND_WHITESPACE, T_VARIABLE], true)) {
				echo basename($f), ":", $t[2], ": a variable in a string\n";
			}
		}
	}
' 2>&1)
if [ -n "$LQ_BAD" ]; then
	printf '%s\n' "$LQ_BAD" | head -n 10 | sed 's/^/  /'
	fail "app/lang/: strings must be single-quoted, with ’ for apostrophes"
else
	say "      quotes: single, ’ for apostrophes  OK"
fi
say ""

# ── 4. Sample configuration ──────────────────────────────────────────────────
# config-sample.php (once per mail client: mail.client also selects the
# attachment types) and projects-sample.php must pass the same validation the
# service runs at start-up, with the placeholders filled in; every project
# text must exist in app/lang/en.php.
say "${B}[4/14] settings${R}  (samples with smtp and brevo; config.php and projects.php if present)"
TMP=$(mktemp -d "${TMPDIR:-/tmp}/mcf-lint.XXXXXX")
cp projects-sample.php "$TMP/projects.php"
for CLIENT in smtp brevo; do
	OUT=$(php -d display_errors=stderr -r '
define("MCF_APP", true);
define("MCF_ROOT", $argv[1]);
$code = $argv[2];
define("MCF_APP_DIR", "$code/app");
spl_autoload_register(static function (string $c): void { $f = MCF_APP_DIR . "/includes/" . substr((string) strrchr("\\" . $c, "\\"), 1) . ".php"; if (str_starts_with($c, "MCF\\") && is_file($f)) require $f; });
require MCF_APP_DIR . "/includes/Config.php"; // also defines ConfigException
copy("$code/config-sample.php", $argv[1] . "/config.php"); // a real file: same loading as the service
$s = require $argv[1] . "/config.php";
$s["mail"]["client"] = $argv[3];
$s["secret"] = str_repeat("x", 64);
$s["mail"]["smtp"]["password"] = "x";
$s["mail"]["brevo"]["api_key"] = "xkeysib-" . str_repeat("0", 32);
$s["data_path"] = $argv[1] . "/_app-data";
try {
    MCF\Config::override($s);
    echo "OK — " . $argv[3] . ": " . count(MCF\Attachments::limits()["types"]) . " attachment types, " . count(MCF\Projects::all()) . " sample projects\n";
} catch (Throwable $e) {
    echo "FAIL " . $argv[3] . ": " . $e->getMessage() . "\n";
}' "$TMP" "$ROOT" "$CLIENT" 2>&1)
	case "$OUT" in
		OK*) say "      $OUT" ;;
		*)   fail "$OUT" ;;
	esac
done
rm -rf "$TMP"
# Your own settings, when present: the same checks the service runs at
# start-up, so a mistake shows here before uploading instead of as a 503.
if [ -f config.php ] || [ -f projects.php ]; then
	OUT=$(php -d display_errors=stderr -r '
define("MCF_APP", true);
define("MCF_ROOT", $argv[1]);
define("MCF_APP_DIR", $argv[1] . "/app");
spl_autoload_register(static function (string $c): void { $f = MCF_APP_DIR . "/includes/" . substr((string) strrchr("\\" . $c, "\\"), 1) . ".php"; if (str_starts_with($c, "MCF\\") && is_file($f)) require $f; });
require MCF_APP_DIR . "/includes/Config.php";
try {
    $s = MCF\Config::all();
    echo "OK — your config.php (" . $s["mail"]["client"] . ") and projects.php (" . count(MCF\Projects::all()) . " projects)\n";
    // Keys the code never reads (typos) are ignored silently at run time:
    // they are listed here.
    // Free-form maps (attachment types) and lists are not checked inside.
    $unknown = static function (array $given, array $known, string $path) use (&$unknown): array {
        $out = [];
        foreach ($given as $k => $v) {
            $p = $path === "" ? (string) $k : "$path.$k";
            if (!array_key_exists($k, $known)) { $out[] = $p; continue; }
            if (is_array($v) && is_array($known[$k]) && !array_is_list($known[$k]) && !in_array($p, ["attachments.types.smtp", "attachments.types.brevo"], true)) {
                $out = array_merge($out, $unknown($v, $known[$k], $p));
            }
        }
        return $out;
    };
    foreach ($unknown(require MCF_ROOT . "/config.php", MCF\Config::defaults(), "") as $k) {
        echo "WARN config.php: unknown key \"$k\" (a typo? see config-sample.php)\n";
    }
} catch (Throwable $e) {
    echo "your settings: " . $e->getMessage() . "\n";
}' "$ROOT" 2>&1)
	case "$OUT" in
		OK*) printf '%s\n' "$OUT" | while IFS= read -r l; do
				case "$l" in WARN*) say "      ${Y}${l#WARN }${R}" ;; *) say "      $l" ;; esac
			done ;;
		*)   fail "$OUT" ;;
	esac
fi
# config.php, when present, is a copy of the sample filled in by hand: after
# an update it keeps the old comments and lacks the new keys.
# bin/tools/config-update.php rebuilds it on the current sample with your
# values, each written as you wrote it, and never prints them. The attachment
# types are your own maps: taken whole. Nothing else rewrites config.php, so
# the rebuild is offered here.
if [ -f config.php ]; then
	cfg_check
	if [ "$CFG_RC" -eq 0 ]; then
		say "      config.php    matches the sample OK"
	elif [ "$CFG_RC" -eq 1 ]; then
		say "      config.php    ${Y}older than the sample${R} (local install, not shipped)"
		cfg_show
		if printf '%s\n' "$CFG_OUT" | grep -q '^LOST'; then
			say "      not updatable while a key is not kept: remove it, or add it to config-sample.php"
		elif [ -t 0 ] && [ -t 1 ]; then
			printf '      Update config.php? [Y/n] '
			read -r REPLY
			case "$REPLY" in
				""|[yY]*) cfg_write ;;
				*) say "      to update it: ./bin/lint.sh --update-config" ;;
			esac
		else
			say "      to update it: ./bin/lint.sh --update-config"
		fi
	else
		say "      ${Y}config.php: $(printf '%s\n' "$CFG_OUT" | head -n 1)${R}"
	fi
fi
say ""

# ── 5. Security invariants ───────────────────────────────────────────────────
# The CSP has no 'unsafe-inline'/'unsafe-eval': that only holds while the
# markup carries no inline script, style attribute or event handler.
say "${B}[5/14] CSP invariants${R}"
BAD=0
if grep -n "unsafe-inline\|unsafe-eval" app/includes/*.php public/.htaccess >/dev/null; then
	fail "CSP must not allow unsafe-inline/unsafe-eval"; BAD=1
fi
# <script> without src= is only allowed as a JSON data block.
if grep -n '<script' app/views/*.php | grep -v 'src=' | grep -v 'type="application/json"'; then
	fail "inline <script> in app/views (only src= or type=\"application/json\" allowed)"; BAD=1
fi
if grep -n ' style=\| on[a-z]*="' app/views/*.php; then
	fail "style= or on*= attribute in app/views (blocked by the CSP)"; BAD=1
fi
if grep -n "setAttribute('style'\|\.innerHTML\s*=\|insertAdjacentHTML\|document\.write" public/assets/js/*.js; then
	fail "JavaScript must build DOM nodes (no innerHTML/style attribute/document.write)"; BAD=1
fi
[ "$BAD" -eq 0 ] && say "      markup        OK — no inline script, style or handler, no innerHTML"
say ""

# ── 6. No stray files ────────────────────────────────────────────────────────
# Only the expected top-level entries, and images only under public/assets/img:
# catches screenshots, logs, archives or backups left behind before a release.
say "${B}[6/14] stray files${R}"
BAD=0
for f in $(ls -A); do
	case "$f" in
		.git|.github|.gitignore|.gitattributes|.editorconfig|.htaccess|README.md|CHANGELOG.md|SECURITY.md|CONTRIBUTING.md|LICENSE|LICENSE.*|config-sample.php|projects-sample.php|config.php|projects.php) ;;
		_app-data*|app|bin|docs|public) ;;
		*) fail "unexpected top-level entry: $f"; BAD=1 ;;
	esac
done
for f in $(find . -path './_app-data*' -prune -o -path ./.git -prune -o -path ./bin/dist -prune -o -path ./bin/tools/stylelint -prune -o -type f \( -iname '*.png' -o -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.gif' -o -iname '*.webp' -o -iname '*.zip' -o -iname '*.log' -o -iname '*.eml' -o -iname '*.bak' -o -iname '*~' \) -print); do
	case "$f" in
		./public/assets/img/*|./docs/screenshot.png|./docs/banner.webp) ;;
		*) fail "unexpected file: $f"; BAD=1 ;;
	esac
done
[ "$BAD" -eq 0 ] && say "      OK"
say ""

# ── 7. Vendored libraries ────────────────────────────────────────────────────
# app/includes/Vendor.php is the single source of the versions and
# the table in README.md (§ Third-party code) must agree; every library keeps its upstream
# README.md and LICENSE.txt; every public folder carries the index.php guard.
say "${B}[7/14] vendored libraries${R}  (app/includes/Vendor.php)"
BAD=0
VENDORS=$(php -r 'define("MCF_APP", true); require "app/includes/Vendor.php"; foreach (MCF\Vendor::LIBRARIES as $l) echo $l["name"], "|", $l["version"], "\n";')
OLDIFS=$IFS; IFS='
'
for line in $VENDORS; do
	name=${line%%|*}; ver=${line#*|}
	if ! grep -q "| $name | $ver |" README.md; then
		fail "README.md (Third-party code) does not list $name $ver (as app/includes/Vendor.php does)"; BAD=1
	fi
done
IFS=$OLDIFS
for d in app/vendor/altcha-lib-php public/assets/vendor/altcha; do
	for f in README.md LICENSE.txt; do
		[ -s "$d/$f" ] || { fail "$d/$f missing (keep the upstream file)"; BAD=1; }
	done
done
# Every folder under public/ (a backstop for Options -Indexes) and under app/
# (no URL, unless the web root is set one level too high): the vendor
# folders included, their stubs written by bin/fetch-vendor.sh.
for d in $(find public app -type d); do
	[ "$d" = public ] && continue # its index.php is the form itself
	[ "$(cat "$d/index.php" 2>/dev/null)" = '<?php // Silence...' ] || { fail "$d/index.php missing (guard: <?php // Silence...)"; BAD=1; }
done
[ "$BAD" -eq 0 ] && say "      OK — $(printf '%s' "$VENDORS" | tr '|' ' ' | paste -sd ',' - | sed 's/,/, /g'); bin/fetch-vendor.sh --check looks for newer ones"
say ""

# ── 8. Package ───────────────────────────────────────────────────────────────
# What GitHub's "Source code" zip of a tag will hold: `git archive`, which
# honours export-ignore in .gitattributes, is exactly how GitHub makes it. That
# list keeps things OUT, so a new top-level file or folder would ship unless
# it is excluded too: the top level must be exactly the site. `git stash
# create` adds uncommitted changes to tracked files without touching the
# stash, so an edit is checked before its commit. Outside a git checkout (a
# copy unpacked from a zip, as on a phone) the check goes through a throwaway
# repository, as bin/make-release.sh does: it reads .gitignore and
# .gitattributes, so it answers as the real one would. Skipped only without
# git.
say "${B}[8/14] package${R}  (GitHub's Source code archive)"
# Byte order (LC_ALL=C): "_" sorts differently from one locale to another.
PACKAGE_WANT=".htaccess LICENSE app config-sample.php projects-sample.php public"
PACKAGE_HAVE=""
PACKAGE_HOW=""
if command -v git >/dev/null 2>&1 && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	PACKAGE_REV=$(git stash create 2>/dev/null)
	[ -n "$PACKAGE_REV" ] || PACKAGE_REV=HEAD
	# This project's own folder ("" when it is the repository's root), archived
	# from the top: run from a subfolder, git archive would look for that
	# folder again inside it.
	PACKAGE_DIR=$(git rev-parse --show-prefix)
	PACKAGE_HAVE=$(git -C "$(git rev-parse --show-toplevel)" archive --format=tar "$PACKAGE_REV:$PACKAGE_DIR" | tar -t 2>/dev/null \
		| sed 's#/.*##' | LC_ALL=C sort -u | tr '\n' ' ' | sed 's/ $//')
elif command -v git >/dev/null 2>&1; then
	# A subshell, so GIT_DIR and the trap end with it; the temporary
	# repository is deleted on the way out.
	PACKAGE_HAVE=$(
		_tmp=$(mktemp -d) || exit 1
		trap 'rm -rf "$_tmp"' EXIT
		export GIT_DIR="$_tmp" GIT_WORK_TREE="$ROOT"
		git init -q && git add -A &&
			git -c user.name=lint -c user.email=lint@localhost -c commit.gpgsign=false \
				commit -q -m lint &&
			git archive --format=tar HEAD | tar -t 2>/dev/null \
				| sed 's#/.*##' | LC_ALL=C sort -u | tr '\n' ' ' | sed 's/ $//'
	)
	PACKAGE_HOW=" (via a temporary repository)"
fi
if ! command -v git >/dev/null 2>&1; then
	say "      skipped (needs git)"
elif [ "$PACKAGE_HAVE" = "$PACKAGE_WANT" ]; then
	say "      OK — the site only, as GitHub will zip it$PACKAGE_HOW"
else
	printf '  expected: %s\n  found:    %s\n' "$PACKAGE_WANT" "$PACKAGE_HAVE"
	fail "the Source code archive is not the site only: add export-ignore lines to .gitattributes (or update PACKAGE_WANT)"
fi
say ""

# ── 9. Release hygiene ──────────────────────────────────────────────────────
# bin/ is excluded from the debug grep: developer tooling
# that does not ship. Do not widen the exclusion to silence a hit.
say "${B}[9/14] hygiene${R}"
LEFTOVER=$(find . \( -path ./.git -o -path './_app-data*' -o -path ./bin/dist -o -path ./bin/tools/stylelint -o -path ./app/vendor -o -path ./public/assets/vendor \) -prune -o \( -name '*.orig' -o -name '*.rej' -o -name '*.bak' -o -name '.DS_Store' -o -name '*.swp' \) -print 2>/dev/null)
if [ -n "$LEFTOVER" ]; then
	printf '%s\n' "$LEFTOVER" | sed 's/^/  /'; fail "editor or merge leftovers present"
else
	say "      leftovers     none    OK"
fi
VERSION=$(sed -n "s/^define('MCF_VERSION', '\([^']*\)');.*/\1/p" app/includes/bootstrap.php)
CHANGED=$(grep -m1 -oE '^## \[[0-9]+\.[0-9]+\.[0-9]+\]' CHANGELOG.md | tr -d '#[] ')
if [ -n "$VERSION" ] && [ "$VERSION" = "$CHANGED" ]; then
	say "      version       $VERSION (MCF_VERSION = CHANGELOG.md)  OK"
else
	fail "MCF_VERSION ($VERSION) differs from CHANGELOG.md's latest release ($CHANGED)"
fi
DEBUG_HITS=$(grep -rn 'var_dump(\|print_r(\|error_log("DEBUG\|console\.log(\|debugger;' \
	--include='*.php' --include='*.js' app public 2>/dev/null \
	| grep -v '^app/vendor/\|^public/assets/vendor/')
if [ -n "$DEBUG_HITS" ]; then
	printf '%s\n' "$DEBUG_HITS" | sed 's/^/  /'; fail "debug output left in the code"
else
	say "      debug output  none    OK"
fi
# Alternative syntax (if: / endif;) balanced: php -l only catches an imbalance
# that stops the file parsing; otherwise it just yields bad HTML. Tokenizer
# rather than grep: a regex cannot tell "if (...) {" from "if (...):".
# shellcheck disable=SC2046 # paths have no whitespace (section 1)
ALT_BAD=$(php -r '
	$exit = 0;
	foreach (array_slice($argv, 1) as $path) {
		$src = file_get_contents($path);
		if ($src === false) {
			fwrite(STDERR, "cannot read $path\n");
			$exit = 1;
			continue;
		}
		$tokens = token_get_all($src);
		$n = count($tokens);

		// Next significant token from $i inclusive, skipping whitespace and
		// comments. Returns [index, text], or null at end of stream.
		$nextSignificant = function (int $i) use ($tokens, $n): ?array {
			for (; $i < $n; $i++) {
				$t = $tokens[$i];
				if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
					continue;
				}
				return [$i, is_array($t) ? $t[1] : $t];
			}
			return null;
		};

		$openerFor = [T_IF => "if", T_FOREACH => "foreach", T_WHILE => "while", T_FOR => "for"];
		$closerFor = [T_ENDIF => T_IF, T_ENDFOREACH => T_FOREACH, T_ENDWHILE => T_WHILE, T_ENDFOR => T_FOR];

		$stack = [];
		$bad = [];

		for ($i = 0; $i < $n; $i++) {
			$t = $tokens[$i];
			if (!is_array($t)) {
				continue;
			}
			[$id, , $line] = $t;

			if (isset($openerFor[$id])) {
				// Find the "(" that follows and its matching ")", counting depth so a
				// nested call in the condition does not end it early, then look at
				// what comes IMMEDIATELY after: ":" is alternative syntax (push),
				// "{" or anything else is brace syntax (ignore).
				$j = $nextSignificant($i + 1);
				if ($j === null || $j[1] !== "(") {
					continue; // should not happen in valid PHP
				}
				$depth = 0;
				$k = $j[0];
				for (; $k < $n; $k++) {
					$tx = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
					if ($tx === "(") {
						$depth++;
					} elseif ($tx === ")") {
						$depth--;
						if ($depth === 0) {
							break;
						}
					}
				}
				$after = $nextSignificant($k + 1);
				if ($after !== null && $after[1] === ":") {
					$stack[] = [$id, $line];
				}
				// elseif pushes nothing of its own: it closes the if/endif chain
				// already open, or with braces there is nothing on the stack
				// and no endif is expected there anyway.
			} elseif (isset($closerFor[$id])) {
				$want = $closerFor[$id];
				$top = end($stack);
				if ($top !== false && $top[0] === $want) {
					array_pop($stack);
				} else {
					$bad[] = "line $line: " . token_name($id)
						. " with no matching " . $openerFor[$want] . "(...): on top of the stack";
				}
			}
		}

		foreach ($stack as [$id, $line]) {
			$bad[] = "line $line: " . $openerFor[$id] . "(...): opened and never closed by end" . $openerFor[$id] . ";";
		}

		if ($bad) {
			foreach ($bad as $b) {
				echo "$path: $b\n";
			}
			$exit = 1;
		}
	}
	exit($exit);
' -- $(php_files | grep -v '/vendor/') 2>&1)
if [ -n "$ALT_BAD" ]; then
	printf '%s\n' "$ALT_BAD" | sed 's/^/  /'; fail "alternative PHP syntax not balanced"
else
	say "      alt syntax    balanced OK"
fi
# $x = ...; followed straight away by another $x = ...; discards the first
# value unread, usually a copy-paste leftover. Valid syntax, so php -l is silent.
# shellcheck disable=SC2046
DEAD_STORE=$(php -r '
	$exit = 0;
	$skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

	foreach (array_slice($argv, 1) as $path) {
		$src = file_get_contents($path);
		if ($src === false) {
			continue;
		}
		$tokens = token_get_all($src);
		$n = count($tokens);

		$nextSig = function (int $i) use ($tokens, $n, $skip): ?array {
			for (; $i < $n; $i++) {
				$t = $tokens[$i];
				if (is_array($t) && in_array($t[0], $skip, true)) {
					continue;
				}
				return [$i, is_array($t) ? $t[1] : $t, is_array($t) ? $t[2] : null];
			}
			return null;
		};

		for ($i = 0; $i < $n; $i++) {
			$t = $tokens[$i];
			if (!is_array($t) || $t[0] !== T_VARIABLE) {
				continue;
			}
			$name = $t[1];
			$line1 = $t[2];

			// Simple assignment only: "=" immediately after the variable, not
			// "[", "->" or "+=". Those stay out on purpose.
			$eq = $nextSig($i + 1);
			if ($eq === null || $eq[1] !== "=") {
				continue;
			}

			$depth = 0;
			$k = $eq[0] + 1;
			for (; $k < $n; $k++) {
				$tk = $tokens[$k];
				$txt = is_array($tk) ? $tk[1] : $tk;
				if ($txt === "(" || $txt === "[" || $txt === "{") {
					$depth++;
				} elseif ($txt === ")" || $txt === "]" || $txt === "}") {
					$depth--;
				} elseif ($depth === 0 && $txt === ";") {
					break;
				}
			}
			if ($k >= $n) {
				continue; // statement never closed; does not happen in valid PHP
			}

			// The second assignment must start IMMEDIATELY after, with only
			// whitespace or comments between: same variable, simple
			// assignment again.
			$next = $nextSig($k + 1);
			if ($next === null || $next[1] !== $name) {
				continue;
			}
			$eq2 = $nextSig($next[0] + 1);
			if ($eq2 === null || $eq2[1] !== "=") {
				continue;
			}

			$depth2 = 0;
			$m = $eq2[0] + 1;
			$selfRef = false;
			for (; $m < $n; $m++) {
				$tk = $tokens[$m];
				$txt = is_array($tk) ? $tk[1] : $tk;
				if ($txt === "(" || $txt === "[" || $txt === "{") {
					$depth2++;
				} elseif ($txt === ")" || $txt === "]" || $txt === "}") {
					$depth2--;
				} elseif ($depth2 === 0 && $txt === ";") {
					break;
				}
				if (is_array($tk) && $tk[0] === T_VARIABLE && $tk[1] === $name) {
					$selfRef = true;
				}
			}
			$line2 = is_array($tokens[$next[0]]) ? $tokens[$next[0]][2] : null;

			if (!$selfRef) {
				echo "$path: line $line1: $name reassigned on line $line2 with nothing reading it in between; the first value is lost\n";
				$exit = 1;
			}
			$i = $m; // do not re-scan these tokens in the outer loop
		}
	}
	exit($exit);
' -- $(php_files | grep -v '/vendor/') 2>&1)
if [ -n "$DEAD_STORE" ]; then
	printf '%s\n' "$DEAD_STORE" | sed 's/^/  /'; fail "variable reassigned before being read"
else
	say "      dead stores   none    OK"
fi
say ""

# ── 10. Language keys ────────────────────────────────────────────────────────
# Keys defined and never used, and keys the JavaScript
# asks for that English does not define. These families are built at run time
# from an id, so no literal search finds them:
#   fe_*  err_*        'fe_' + code, 'err_' + code         contact.js
#   reason.* notice.* field.* placeholder.* help.* option.*  Projects.php
#   altcha.*           I18n::altchaStrings()
#   privacy.*          'privacy.' + key, I18n::items()       app/views/privacy.php
#   language.name      I18n::names()
#   error.*            'error.' + key                        app/views/error.php
say "${B}[10/14] language keys${R}"
LANG_KEYS="$(mktemp)"; USED_KEYS="$(mktemp)"; CSS_CLASSES="$(mktemp)"; USED_CLASSES="$(mktemp)"
trap 'rm -f "$LANG_KEYS" "$USED_KEYS" "$CSS_CLASSES" "$USED_CLASSES"' EXIT
php -r 'define("MCF_APP", true); $l = require "app/lang/en.php"; echo implode("\n", array_keys($l)), "\n";' \
	| grep -vE '^(fe_|err_|reason\.|notice\.|field\.|placeholder\.|help\.|option\.|altcha\.|privacy\.|error\.|language\.name$)' | sort -u > "$LANG_KEYS"
find app/includes app/views public -name '*.php' -o -name '*.js' | grep -v '/vendor/' \
	| xargs grep -ohwF -f "$LANG_KEYS" 2>/dev/null | sort -u > "$USED_KEYS"
ORPHANS="$(comm -23 "$LANG_KEYS" "$USED_KEYS")"
if [ -n "$ORPHANS" ]; then
	printf '%s\n' "$ORPHANS" | while IFS= read -r k; do say "      ${Y}orphan${R} $k"; done
	fail "language keys never used: remove them from every language, or use them"
else
	say "      $(wc -l < "$LANG_KEYS" | tr -d ' ') keys, none orphaned OK"
fi
# A key ending in "_" is a prefix completed at run time (t('fe_' + code)).
JS_MISSING=$(grep -ohE "\bt\('[a-z0-9_]+'" public/assets/js/*.js | sed "s/t('//; s/'//" | grep -v '_$' | sort -u | while IFS= read -r k; do
	php -r 'define("MCF_APP", true); $l = require "app/lang/en.php"; exit(array_key_exists($argv[1], $l) ? 0 : 1);' "$k" || printf '%s ' "$k"
done)
if [ -n "$JS_MISSING" ]; then
	fail "used by the JavaScript but missing from app/lang/en.php: $JS_MISSING"
else
	say "      keys used by the JavaScript all defined OK"
fi
say ""

# ── 11. CSS classes never used ───────────────────────────────────────────────
# Comments are stripped first (they span lines). altcha-*
# belong to the widget's own stylesheet.
say "${B}[11/14] CSS classes${R}"
awk '
	{ line = $0; out = ""
	  while (length(line) > 0) {
	    if (inc) { i = index(line, "*/"); if (i == 0) { line = ""; break }
	               line = substr(line, i + 2); inc = 0 }
	    else     { i = index(line, "/*"); if (i == 0) { out = out line; break }
	               out = out substr(line, 1, i - 1); line = substr(line, i + 2); inc = 1 }
	  }
	  print out }
' public/assets/css/*.css \
	| sed 's/url([^)]*)//g; s/[0-9]*\.[0-9][0-9]*//g' \
	| grep -oE '\.[a-zA-Z_-][a-zA-Z0-9_-]*' | tr -d '.' | grep -vE '^altcha' | sort -u > "$CSS_CLASSES"
find app/includes app/views public -name '*.php' -o -name '*.js' -o -name '*.html' | grep -v '/vendor/' \
	| xargs grep -ohwF -f "$CSS_CLASSES" 2>/dev/null | sort -u > "$USED_CLASSES"
DEAD_CSS="$(comm -23 "$CSS_CLASSES" "$USED_CLASSES")"
if [ -n "$DEAD_CSS" ]; then
	printf '%s\n' "$DEAD_CSS" | while IFS= read -r c; do say "      ${Y}never used${R} .$c"; done
	fail "CSS classes never used in markup or JavaScript"
else
	say "      $(wc -l < "$CSS_CLASSES" | tr -d ' ') classes, none dead OK"
fi
say ""

# ── 12. Files cited in the documentation ─────────────────────────────────────
# Not shipped: bin/tools/phpstan.phar, bin/tools/stylelint/ and
# bin/tools/.latest-versions (fetched or written on demand by 14),
# bin/dist/ (archives), _app-data/ (created at run time, any suffix) and the
# app/lang/xx.php example of a new language.
say "${B}[12/14] files cited in the docs${R}"
MISSING_DOC=""
DOC_REFS="$(cat README.md CHANGELOG.md SECURITY.md CONTRIBUTING.md 2>/dev/null \
	| grep -ohE '`(_app-data|app|public|bin|docs)/[A-Za-z0-9_./-]+`' \
	| tr -d '`' | grep -vE '^(bin/tools/phpstan\.phar|bin/tools/stylelint(/.*)?|bin/tools/\.latest-versions|bin/dist/.*|_app-data(/.*)?|app/lang/xx\.php)$' | sed 's#/$##' | sort -u)"
while IFS= read -r ref; do
	[ -z "$ref" ] && continue
	case "$ref" in *'<'*|*'*'*) continue ;; esac
	[ -e "$ref" ] || MISSING_DOC="$MISSING_DOC $ref"
done <<EOF
$DOC_REFS
EOF
if [ -n "$MISSING_DOC" ]; then
	for f in $MISSING_DOC; do say "      ${Y}cited but missing${R} $f"; done
	fail "the documentation cites files that do not exist"
else
	say "      all present OK"
fi
say ""

# ── 13. JavaScript smoke test ────────────────────────────────────────────────
# Loads the page scripts in a fake DOM (bin/tools/smoke.js): catches a
# function used but deleted, which node --check cannot.
say "${B}[13/14] JavaScript smoke test${R}"
if command -v node >/dev/null 2>&1; then
	if SMOKE=$(node bin/tools/smoke.js 2>&1); then
		say "      $(printf '%s\n' "$SMOKE" | grep -c '^  OK') scripts start OK"
	else
		printf '%s\n' "$SMOKE" | grep '^  NO' | sed 's/^/  /'
		fail "smoke test found problems"
	fi
else
	say "      node not installed, skipped"
fi
say ""

# ── 14. Static analysis ──────────────────────────────────────────────────────
# Catches what php -l cannot, such as a variable used but never set (PHP 8
# only warns at runtime), and keeps the stylesheets to their rules.
#
# Two tools, two versions on purpose:
#   - here, on your computer, the LATEST release, so a new check shows up at
#     the first run after it is published, before anyone else sees it;
#   - in CI, a PINNED release already known to pass (tools: phpstan:X and
#     stylelint@X in .github/workflows/checks.yml), so the public badge changes
#     colour only when the code does.
# When the latest one passes here and is newer than the pin, the run offers
# to raise the pin (one line of checks.yml, for you to commit). The latest
# versions are looked up at most once a day, on a terminal only (see
# LATEST_CACHE below); under CI, a pipe, offline or with
# MCF_LINT_OFFLINE=1, whatever is installed is used.
#
# The tools are not in the repository, so they are offered, never fetched
# unasked: the rest of the script must work offline and in CI.
say "${B}[14/14] static analysis${R}"

LINT_YML=".github/workflows/checks.yml"
interactive() { [ -t 0 ] && [ -t 1 ]; }
# True when version $1 is older than $2.
ver_lt() { php -r 'exit(version_compare($argv[1], $argv[2], "<") ? 0 : 1);' "$1" "$2"; }
# Body of an https URL on stdout, quietly, within a few seconds.
fetch() {
	if command -v curl >/dev/null 2>&1; then
		curl -fsS --connect-timeout 4 --max-time 8 "$1" 2>/dev/null
	elif command -v wget >/dev/null 2>&1; then
		wget -qO- -T 8 -t 1 "$1" 2>/dev/null
	fi
}
# The version checks.yml pins for $1 (phpstan or stylelint), or "".
ci_pin() {
	case "$1" in
		phpstan) sed -n 's/^[[:space:]]*tools:[[:space:]]*phpstan:\([0-9][0-9.]*\).*/\1/p' "$LINT_YML" | head -n 1 ;;
		stylelint) sed -n 's/.*stylelint@\([0-9][0-9.]*\).*/\1/p' "$LINT_YML" | head -n 1 ;;
	esac
}
# After a clean run of $1 at version $2: offer to raise the CI pin to it.
# Through php, not sed -i, which differs between GNU and BSD/macOS.
offer_ci_bump() {
	_pin=$(ci_pin "$1")
	[ -n "$_pin" ] && [ -n "$2" ] && ver_lt "$_pin" "$2" || return 0
	say "      ${Y}$1 $2 passes here; CI is pinned at $_pin${R}"
	_do=0
	if interactive; then
		printf '      Raise the pin in %s to %s? [y/N] ' "$LINT_YML" "$2"
		read -r REPLY
		case "$REPLY" in [yY]*) _do=1 ;; esac
	fi
	if [ "$_do" -eq 1 ] && php -r '
		[$f, $tool, $v] = [$argv[1], $argv[2], $argv[3]];
		$s = file_get_contents($f);
		$re = $tool === "phpstan" ? "/(tools:\\s*phpstan:)[0-9][0-9.]*/" : "/(stylelint@)[0-9][0-9.]*/";
		$n = preg_replace($re, "\${1}" . $v, $s, -1, $count);
		exit($count > 0 && file_put_contents($f, $n) !== false ? 0 : 1);
	' "$LINT_YML" "$1" "$2"; then
		say "      $LINT_YML now pins $1 $2: commit it with your next change."
	else
		say "      To raise it by hand: in $LINT_YML replace $_pin with $2 on the"
		case "$1" in
			phpstan) say "      line \"tools: phpstan:$_pin\", then commit the file." ;;
			stylelint) say "      line holding \"stylelint@$_pin\", then commit the file." ;;
		esac
	fi
}
# After a failed run of $1 at version $2: say whether CI would see it too.
explain_failure() {
	_pin=$(ci_pin "$1")
	if [ -n "$_pin" ] && [ -n "$2" ] && ver_lt "$_pin" "$2"; then
		say "      ${Y}$1 $2 reports this; CI, pinned at $_pin, does not yet.${R}"
		say "      Fix it before raising the pin in $LINT_YML."
	fi
}

# ── PHPStan ──
# The latest releases, looked up at most once a day and remembered in
# bin/tools/.latest-versions (git-ignored): the only requests this script
# makes without asking, and only on a terminal. MCF_LINT_OFFLINE=1 skips
# them. A failed lookup (offline, a proxy), even of just one of the two, is
# remembered for an hour, so an offline machine waits for the timeouts once,
# not on every run.
LATEST_CACHE="bin/tools/.latest-versions"
LATEST_OK_TTL=86400
LATEST_FAIL_TTL=3600
cache_get() { [ -f "$LATEST_CACHE" ] && sed -n "s/^$1=//p" "$LATEST_CACHE" | head -n 1; }
now() { php -r 'echo time();'; }
# PHPStan: where GitHub's "latest" page redirects (no API rate limit), else
# the API. No second try when the first could not connect at all.
lookup_phpstan() {
	_v=""
	if command -v curl >/dev/null 2>&1; then
		_u=$(curl -sS -o /dev/null -w '%{redirect_url}' --connect-timeout 4 --max-time 8 \
			https://github.com/phpstan/phpstan/releases/latest 2>/dev/null) || return 0
		_v=$(printf '%s' "$_u" | sed -n 's#.*/tag/v\{0,1\}\([0-9][0-9.]*\)$#\1#p')
	fi
	[ -n "$_v" ] || _v=$(fetch https://api.github.com/repos/phpstan/phpstan/releases/latest |
		sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"v\{0,1\}\([0-9][0-9.]*\)".*/\1/p' | head -n 1)
	printf '%s' "$_v"
}
# Stylelint: npm's own timeouts do not cover every stall (a proxy that
# accepts the connection and never answers), so the whole lookup is capped
# where timeout(1) exists (GNU coreutils; gtimeout on macOS with Homebrew).
lookup_stylelint() {
	command -v npm >/dev/null 2>&1 || return 0
	_cap=""
	if command -v timeout >/dev/null 2>&1; then _cap="timeout 15"
	elif command -v gtimeout >/dev/null 2>&1; then _cap="gtimeout 15"
	fi
	$_cap npm view --fetch-timeout=8000 --fetch-retries=0 stylelint version 2>/dev/null </dev/null | tail -n 1
}
PHPSTAN_LATEST=""
STYLELINT_LATEST=""
if interactive && [ "${MCF_LINT_OFFLINE:-0}" != 1 ]; then
	_checked=$(cache_get checked); _checked=${_checked:-0}
	_ok=$(cache_get ok)
	_age=$(( $(now) - _checked ))
	if { [ "$_ok" = 1 ] && [ "$_age" -lt "$LATEST_OK_TTL" ]; } ||
		{ [ "$_ok" = 0 ] && [ "$_age" -lt "$LATEST_FAIL_TTL" ]; }; then
		PHPSTAN_LATEST=$(cache_get phpstan)
		STYLELINT_LATEST=$(cache_get stylelint)
	else
		say "      checking the latest PHPStan and Stylelint (once a day)"
		PHPSTAN_LATEST=$(lookup_phpstan)
		STYLELINT_LATEST=$(lookup_stylelint)
		# A success only when both answered (Stylelint does not count without
		# npm: there is nothing to ask there). With one answer the run uses it,
		# but the cache lasts an hour, not a day: otherwise the missing version
		# would stay unknown until tomorrow.
		_ok=0
		[ -n "$PHPSTAN_LATEST" ] &&
			{ [ -n "$STYLELINT_LATEST" ] || ! command -v npm >/dev/null 2>&1; } && _ok=1
		if [ "$_ok" != 1 ]; then
			if [ -n "$PHPSTAN_LATEST" ] || [ -n "$STYLELINT_LATEST" ]; then
				say "      ${Y}partial answer (PHPStan: ${PHPSTAN_LATEST:-?}, Stylelint: ${STYLELINT_LATEST:-?}); retried in an hour${R}"
			else
				say "      ${Y}no answer; retried in an hour (MCF_LINT_OFFLINE=1 skips the check)${R}"
			fi
		fi
		printf 'checked=%s\nok=%s\nphpstan=%s\nstylelint=%s\n' "$(now)" "$_ok" \
			"$PHPSTAN_LATEST" "$STYLELINT_LATEST" > "$LATEST_CACHE" 2>/dev/null || true
	fi
fi
if [ -n "$PHPSTAN_LATEST" ]; then
	PHPSTAN_URL="https://github.com/phpstan/phpstan/releases/download/$PHPSTAN_LATEST/phpstan.phar"
else
	PHPSTAN_URL="https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar"
fi

phpstan_version() { $PHPSTAN --version 2>/dev/null | sed -n 's/.*[[:space:]]v\{0,1\}\([0-9][0-9.]*\)[[:space:]]*$/\1/p' | head -n 1; }

if [ -f bin/tools/phpstan.phar ]; then
	PHPSTAN="php bin/tools/phpstan.phar"
elif command -v phpstan >/dev/null 2>&1; then
	PHPSTAN="phpstan"
else
	PHPSTAN=""
fi
PHPSTAN_HAVE=""
[ -n "$PHPSTAN" ] && PHPSTAN_HAVE=$(phpstan_version)

# Offered when missing, or when the phar is older than the latest release.
# A phpstan on the PATH is not ours to replace: only reported.
PHPSTAN_ASK=""
if [ -z "$PHPSTAN" ]; then
	PHPSTAN_ASK="phpstan not found. It would be downloaded from:"
elif [ -n "$PHPSTAN_LATEST" ] && [ -n "$PHPSTAN_HAVE" ] && ver_lt "$PHPSTAN_HAVE" "$PHPSTAN_LATEST"; then
	if [ "$PHPSTAN" = "phpstan" ]; then
		say "      ${Y}phpstan on the PATH is $PHPSTAN_HAVE, $PHPSTAN_LATEST is out: update it the way you installed it${R}"
	else
		PHPSTAN_ASK="phpstan $PHPSTAN_HAVE is here, $PHPSTAN_LATEST is out. It would be downloaded from:"
	fi
fi

# Only asks on a terminal; under CI or a pipe a read could hang or eat input.
if [ -n "$PHPSTAN_ASK" ] && interactive; then
	# Show the source URL before asking for consent.
	say "      $PHPSTAN_ASK"
	say "      $PHPSTAN_URL"
	printf '      Download it to bin/tools/ (~30 MB)? [Y/n] '
	read -r REPLY
	case "$REPLY" in
	""|[yY]*)
		# Downloaded under .part and renamed on success, so an interrupted
		# transfer never leaves a truncated phar under the real name.
		if command -v curl >/dev/null 2>&1; then
			curl -fSL --progress-bar -o bin/tools/phpstan.phar.part "$PHPSTAN_URL"
		elif command -v wget >/dev/null 2>&1; then
			wget -q --show-progress -O bin/tools/phpstan.phar.part "$PHPSTAN_URL" ||
				wget -qO bin/tools/phpstan.phar.part "$PHPSTAN_URL"
		else
			say "      neither curl nor wget found. Install one, or fetch by hand:"
			say "      $PHPSTAN_URL"
		fi
		# Run --version rather than test the size: a truncated archive or a
		# saved HTML error page is still non-empty.
		if php bin/tools/phpstan.phar.part --version >/dev/null 2>&1; then
			mv bin/tools/phpstan.phar.part bin/tools/phpstan.phar
			PHPSTAN="php bin/tools/phpstan.phar"
			PHPSTAN_HAVE=$(phpstan_version)
		else
			rm -f bin/tools/phpstan.phar.part
			say "      download failed or incomplete"
		fi
		;;
	esac
fi

if [ -z "$PHPSTAN" ]; then
	say "      phpstan       not installed, skipped"
# Judged by the exit status, not by whether PHPStan printed anything: from
# 2.3.2 it prints a note about PHPStan Turbo on a clean run too, which a test
# for "any output" read as problems.
elif PHPSTAN_OUT=$($PHPSTAN analyse -c bin/tools/phpstan.neon --memory-limit=1G \
	--no-progress --error-format=raw 2>&1); [ $? -ne 0 ]; then
	printf '%s\n' "$PHPSTAN_OUT" | sed 's/^/  /' | grep .
	fail "static analysis found problems"
	explain_failure phpstan "$PHPSTAN_HAVE"
else
	# Level and PHP range are read back from the config (80300 → 8.3).
	PHPSTAN_LEVEL=$(sed -n 's/^[[:space:]]*level:[[:space:]]*\([0-9]*\).*/\1/p' bin/tools/phpstan.neon)
	PHP_MIN_ID=$(sed -n 's/^[[:space:]]*min:[[:space:]]*\([0-9]*\).*/\1/p' bin/tools/phpstan.neon)
	PHP_MAX_ID=$(sed -n 's/^[[:space:]]*max:[[:space:]]*\([0-9]*\).*/\1/p' bin/tools/phpstan.neon)
	PHP_RANGE=""
	if [ -n "$PHP_MIN_ID" ] && [ -n "$PHP_MAX_ID" ]; then
		PHP_RANGE="  PHP $((PHP_MIN_ID / 10000)).$((PHP_MIN_ID / 100 % 100))–$((PHP_MAX_ID / 10000)).$((PHP_MAX_ID / 100 % 100))"
	fi
	say "      phpstan       ${PHPSTAN_HAVE:-?}  level ${PHPSTAN_LEVEL:-?}${PHP_RANGE}  OK"
	offer_ci_bump phpstan "$PHPSTAN_HAVE"
fi
say ""

# ── Stylelint ──
# The rules in bin/tools/stylelint.json, shared with the CI job. Installed
# with npm into bin/tools/stylelint/ (git-ignored); Node.js 20.19 or later,
# as Stylelint itself requires.
STYLELINT_DIR="bin/tools/stylelint"
STYLELINT_PKG="$STYLELINT_DIR/node_modules/stylelint/package.json"
# Run through node, from the script its package.json names: no
# node_modules/.bin link and no exec bit needed. Android's shared storage
# (/storage/emulated/0, where Termux users often keep the project) has
# neither symlinks nor exec bits, so npm installs there with --no-bin-links.
stylelint_js() {
	[ -f "$STYLELINT_PKG" ] && command -v node >/dev/null 2>&1 || return 1
	node -e '
		const path = require("path");
		const pkg = path.resolve(process.argv[1]);
		const p = require(pkg);
		const bin = typeof p.bin === "string" ? p.bin : (p.bin || {}).stylelint;
		if (!bin) process.exit(1);
		console.log(path.join(path.dirname(pkg), bin));
	' "$STYLELINT_PKG"
}
stylelint_version() {
	[ -f "$STYLELINT_PKG" ] && node -p 'require(require("path").resolve(process.argv[1])).version' "$STYLELINT_PKG" 2>/dev/null
}
STYLELINT_JS=$(stylelint_js || true)
STYLELINT_HAVE=$(stylelint_version || true)

STYLELINT_ASK=""
if [ -z "$STYLELINT_JS" ]; then
	STYLELINT_ASK="Stylelint not found. npm would install stylelint@${STYLELINT_LATEST:-latest}"
elif [ -n "$STYLELINT_LATEST" ] && [ -n "$STYLELINT_HAVE" ] && ver_lt "$STYLELINT_HAVE" "$STYLELINT_LATEST"; then
	STYLELINT_ASK="Stylelint $STYLELINT_HAVE is here, $STYLELINT_LATEST is out. npm would install it"
fi
if [ -n "$STYLELINT_ASK" ] && command -v npm >/dev/null 2>&1 && interactive; then
	say "      $STYLELINT_ASK"
	printf '      into %s/ (~30 MB)? [Y/n] ' "$STYLELINT_DIR"
	read -r REPLY
	case "$REPLY" in
	""|[yY]*)
		# npm shows nothing here (output hidden below), unlike the PHPStan download's
		# progress bar: say what is going on, or the screen looks frozen.
		say "      installing, this can take a minute..."
		if npm install --prefix "$STYLELINT_DIR" --no-save --no-audit --no-fund \
			--no-bin-links --loglevel=error "stylelint@${STYLELINT_LATEST:-latest}" >/dev/null; then
			STYLELINT_JS=$(stylelint_js || true)
			STYLELINT_HAVE=$(stylelint_version || true)
		else
			# A half-written install would pass the check above next time
			# and fail as "problems found".
			rm -rf "$STYLELINT_DIR"
			STYLELINT_JS=""
			say "      npm install failed"
		fi
		;;
	esac
fi
if [ -z "$STYLELINT_JS" ]; then
	if command -v npm >/dev/null 2>&1; then
		say "      stylelint     not installed, skipped"
	else
		say "      stylelint     needs node and npm, skipped"
	fi
elif node "$STYLELINT_JS" --config bin/tools/stylelint.json --quiet-deprecation-warnings \
	"public/assets/css/*.css" 2>&1 | sed 's/^/  /' | grep . ; then
	fail "Stylelint found problems"
	explain_failure stylelint "$STYLELINT_HAVE"
else
	say "      stylelint     ${STYLELINT_HAVE:-?}  OK"
	offer_ci_bump stylelint "$STYLELINT_HAVE"
fi
say ""

if [ "$FAILED" -eq 0 ]; then
	say "${B}All checks passed.${R}"
	exit 0
fi
say "${B}Checks failed:${R}"
printf '%s' "$FAIL_LIST"
say "Fix the items above before releasing."
exit 1
