#!/bin/sh
#
# bin/fetch-vendor.sh — download the third-party libraries at their latest
# published version, laid out the way this project expects.
#
#   ./bin/fetch-vendor.sh                  # everything
#   ./bin/fetch-vendor.sh altcha           # one library
#   ./bin/fetch-vendor.sh --check          # only compare versions (for CI)
#
# Libraries: altcha (the widget, npm) and altcha-lib-php (GitHub releases).
# Requires curl (or wget), tar and php; zip to build the archive (without it
# the staged files are left in place). git is used only as a fallback when
# the GitHub API is unreachable. POSIX sh: runs under Termux's dash/ash too.
#
# ── IT INSTALLS NOTHING ──────────────────────────────────────────────────────
# The output is bin/dist/multicontactform-vendor-YYYYMMDD-HHMM.zip; the real vendor
# folders are never touched. Updating a library means reading what changed
# and testing the feature it drives (README.md, "Third-party code", names it), so
# the copy into the project stays a manual step.
#
# Inside the archive the paths are the project's real ones:
# public/assets/vendor/altcha/ and app/vendor/altcha-lib-php/, each vendor
# folder with its own index.php stub, so either can replace the project's as
# a whole: none of our notes live in there (they are in README.md).
# VERSIONS.txt lists the downloaded versions against app/includes/Vendor.php;
# HOW-TO-APPLY.txt says what to copy where.
#
# ── WHY VERSIONS ARE RESOLVED FIRST ──────────────────────────────────────────
# Minified builds carry no version marker, so a file fetched via @latest can
# never be identified afterwards. The version is resolved from the registry,
# used explicitly, and written to VERSIONS.txt.
#
# ── INTEGRITY ────────────────────────────────────────────────────────────────
# The widget comes from its npm tarball, checked against the SHA-512 the
# registry publishes before anything is unpacked. altcha-lib-php comes from
# the GitHub release tarball (GitHub publishes no checksum for it).
#
# --check resolves the latest versions only and compares them with
# app/includes/Vendor.php: exit 0 when equal, 2 when something newer is out, 1 on
# error. .github/workflows/checks.yml runs it weekly.

set -u

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
VENDOR_PHP="$ROOT/app/includes/Vendor.php"

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
	B=$(printf '\033[1m'); Y=$(printf '\033[33m'); R=$(printf '\033[0m')
else
	B=''; Y=''; R=''
fi
say() { printf '%s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

# ── Downloader: curl if present, wget otherwise ──────────────────────────────
if command -v curl >/dev/null 2>&1; then
	fetch() { curl -fsSL "$1" -o "$2"; }
	fetch_stdout() { curl -fsSL "$1"; }
elif command -v wget >/dev/null 2>&1; then
	fetch() { wget -q -O "$2" "$1"; }
	fetch_stdout() { wget -q -O - "$1"; }
else
	die "curl or wget is required"
fi
command -v php >/dev/null 2>&1 || die "php is required"

# php reads JSON properly (no jq needed) and compares versions.
json_get() { php -r '$j = json_decode(stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) { $j = is_array($j) ? ($j[$k] ?? null) : null; } echo is_string($j) ? $j : "";' "$1"; }
newer() { php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$1" "$2"; }

# declared_version <role>: the version app/includes/Vendor.php declares.
declared_version() {
	awk -v want="$1" "
		/'version'[[:space:]]*=>/ {
			v = \$0; sub(/.*=>[[:space:]]*'/, \"\", v); sub(/'.*/, \"\", v)
		}
		/'role'[[:space:]]*=>/ {
			r = \$0; sub(/.*=>[[:space:]]*'/, \"\", r); sub(/'.*/, \"\", r)
			if (r == want) { print v; exit }
		}
	" "$VENDOR_PHP" 2>/dev/null
}

NPM_META=""
npm_meta() { [ -n "$NPM_META" ] || NPM_META=$(fetch_stdout "https://registry.npmjs.org/altcha/latest" 2>/dev/null); printf '%s' "$NPM_META"; }
latest_altcha() { npm_meta | json_get version; }

# The GitHub release API gives the exact tag; git ls-remote is the fallback
# where the API is rate-limited or blocked.
latest_altcha_php_tag() {
	_t=$(fetch_stdout "https://api.github.com/repos/altcha-org/altcha-lib-php/releases/latest" 2>/dev/null | json_get tag_name)
	if [ -z "$_t" ] && command -v git >/dev/null 2>&1; then
		_t=$(git ls-remote --tags --refs https://github.com/altcha-org/altcha-lib-php 2>/dev/null | sed 's#.*refs/tags/##' \
			| grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' \
			| php -r '$t = file("php://stdin", FILE_IGNORE_NEW_LINES); usort($t, "version_compare"); echo end($t) ?: "";')
	fi
	printf '%s' "$_t"
}

# ── --check ──────────────────────────────────────────────────────────────────
if [ "${1:-}" = "--check" ]; then
	W_NEW=$(latest_altcha); T=$(latest_altcha_php_tag); L_NEW=${T#v}
	[ -n "$W_NEW" ] && [ -n "$L_NEW" ] || die "cannot resolve the latest versions (network?)"
	OUTDATED=0
	for pair in "altcha:$W_NEW" "altcha_php:$L_NEW"; do
		role=${pair%%:*}; new=${pair#*:}; cur=$(declared_version "$role")
		if newer "$new" "${cur:-0}"; then
			say "!!! $role: $cur -> $new"; OUTDATED=1
		else
			say "    $role: $cur (latest)"
		fi
	done
	[ "$OUTDATED" -eq 1 ] && { say "Run bin/fetch-vendor.sh and follow README.md, Third-party code."; exit 2; }
	exit 0
fi

# ── Staging ──────────────────────────────────────────────────────────────────
OUT=$(mktemp -d "${TMPDIR:-/tmp}/multicontactform-vendor.XXXXXX")
mkdir -p "$HERE/dist"
ARCHIVE="$HERE/dist/multicontactform-vendor-$(date '+%Y%m%d-%H%M').zip"
VERSIONS="$OUT/VERSIONS.txt"
KEEP_STAGING=0
FAILED=0
cleanup() { if [ "$KEEP_STAGING" -eq 0 ]; then rm -rf "$OUT"; else say "Staging kept at $OUT"; fi; }

ok()   { printf '    ok     %s\n' "$1"; }
bad()  { printf '    FAILED %s\n' "$1"; FAILED=1; }

# record <name> <downloaded-version> <role>: !!! marks a version that differs
# from app/includes/Vendor.php (shipped -> downloaded).
record() {
	_dec=$(declared_version "$3"); [ -n "$_dec" ] || _dec='?'
	if [ "$_dec" = "$2" ]; then
		printf '    %-20s %s\n' "$1" "$_dec" >> "$VERSIONS"
	else
		printf '!!! %-20s %s -> %s\n' "$1" "$_dec" "$2" >> "$VERSIONS"
	fi
}

# guard <dir-relative-to-staging>: writes the index.php silence stub into
# that directory and every subdirectory: a backstop for Options -Indexes on
# servers that ignore it in public/, and the same convention as every other
# folder in app/ (bin/lint.sh), in case the web root is set one level too high.
guard() {
	find "$OUT/$1" -type d | while IFS= read -r d; do printf '<?php // Silence...\n' > "$d/index.php"; done
}

lib_altcha() {
	say "${B}altcha${R} (widget)"
	v=$(latest_altcha)
	[ -n "$v" ] || { bad "could not resolve version"; return; }
	say "    version $v"
	url=$(npm_meta | json_get dist.tarball)
	sri=$(npm_meta | json_get dist.integrity)
	tgz="$OUT/.altcha.tgz"
	fetch "$url" "$tgz" || { bad "$url"; return; }
	# PHP, not the openssl command, which Termux does not install by default.
	got="sha512-$(php -r 'echo base64_encode(hash_file("sha512", $argv[1], true));' "$tgz")"
	[ "$got" = "$sri" ] || { bad "integrity check ($url)"; rm -f "$tgz"; return; }
	ok "npm tarball, SHA-512 verified"
	mkdir -p "$OUT/.altcha" && tar -xzf "$tgz" -C "$OUT/.altcha" || { bad "extract"; return; }
	d="$OUT/public/assets/vendor/altcha"; mkdir -p "$d"
	# dist/main UMD build, upstream name, never renamed or edited. See
	# README.md, Third-party code.
	for f in dist/main/altcha.umd.min.cjs README.md LICENSE.txt; do
		if [ -s "$OUT/.altcha/package/$f" ]; then cp "$OUT/.altcha/package/$f" "$d/"; ok "public/assets/vendor/altcha/$(basename "$f")"; else bad "$f not in the package"; fi
	done
	rm -rf "$OUT/.altcha" "$tgz"
	guard public/assets/vendor/altcha
	record altcha "$v" altcha
}

lib_altchaphp() {
	say "${B}altcha-lib-php${R} (server)"
	tag=$(latest_altcha_php_tag)
	[ -n "$tag" ] || { bad "could not resolve tag"; return; }
	say "    tag $tag"
	ex="$OUT/.alib"; mkdir -p "$ex"
	if fetch "https://codeload.github.com/altcha-org/altcha-lib-php/tar.gz/refs/tags/$tag" "$OUT/.alib.tgz" 2>/dev/null \
		&& tar -xzf "$OUT/.alib.tgz" -C "$ex" --strip-components=1 2>/dev/null; then
		ok "release tarball"
	elif command -v git >/dev/null 2>&1 && rm -rf "$ex" && git clone -q --depth 1 --branch "$tag" https://github.com/altcha-org/altcha-lib-php "$ex" 2>/dev/null; then
		ok "git clone (tarball unreachable)"
	else
		bad "download $tag"; return
	fi
	d="$OUT/app/vendor/altcha-lib-php"; mkdir -p "$d"
	# The package root minus composer.json: our autoloader (app/includes/bootstrap.php)
	# stands in for Composer's.
	if [ -d "$ex/src" ]; then cp -R "$ex/src" "$d/src"; ok "app/vendor/altcha-lib-php/src/"; else bad "src/ not in the release"; fi
	for f in LICENSE.txt README.md; do
		if [ -s "$ex/$f" ]; then cp "$ex/$f" "$d/"; ok "app/vendor/altcha-lib-php/$f"; else bad "$f not in the release"; fi
	done
	rm -rf "$ex" "$OUT/.alib.tgz"
	guard app/vendor/altcha-lib-php
	record altcha-lib-php "${tag#v}" altcha_php
}

ALL="altcha altcha-lib-php"
if [ "$#" -gt 0 ]; then TARGETS="$*"; IS_FULL_RUN=0; else TARGETS="$ALL"; IS_FULL_RUN=1; fi

{
	printf 'Downloaded on %s\n' "$(date '+%Y-%m-%d %H:%M')"
	printf 'Lines marked !!! differ from app/includes/Vendor.php: shipped -> downloaded.\n\n'
} > "$VERSIONS"

say "${B}Downloading into${R} $OUT"
say ""
for t in $TARGETS; do
	case "$t" in
		altcha)         lib_altcha ;;
		altcha-lib-php) lib_altchaphp ;;
		*)              say "unknown library: $t (valid: $ALL)"; FAILED=1 ;;
	esac
	say ""
done

say "${B}Versions${R}"
while IFS= read -r _line; do
	case "$_line" in '!!! '*) say "    ${Y}${_line}${R}" ;; *) say "    $_line" ;; esac
done < "$VERSIONS"
say ""

if [ "$FAILED" -ne 0 ]; then
	say "Some downloads failed: check the lines above. Nothing was archived."
	KEEP_STAGING=1; cleanup
	exit 1
fi

# The vendor folders' own stubs: the libraries' are written by their lib_*,
# these belong to none of them. With them in the archive app/vendor/ and
# public/assets/vendor/ replace the project's as a whole.
for v in app/vendor public/assets/vendor; do
	[ -d "$OUT/$v" ] && printf '<?php // Silence...\n' > "$OUT/$v/index.php"
done

if [ "$IS_FULL_RUN" -eq 1 ]; then SCOPE="both libraries"; else SCOPE="only: $TARGETS"; fi
cat > "$OUT/HOW-TO-APPLY.txt" <<EOF
Contents: $SCOPE, at the project's real paths.

To apply, for each vendor folder in here:
  1. Read the library's section in README.md, "Third-party code"
     (what to check).
  2. In the project, replace app/vendor/ and/or public/assets/vendor/
     with the one from here, as a whole: they hold only the libraries
     and their index.php stubs, all included.
  3. Update the version in app/includes/Vendor.php and in the table
     in README.md (bin/lint.sh fails if they disagree).
  4. Run bin/lint.sh and send a test message.
EOF

if command -v zip >/dev/null 2>&1; then
	if (cd "$OUT" && zip -qr "$ARCHIVE" .); then say "Archive ready: $ARCHIVE"; else say "Could not write $ARCHIVE"; KEEP_STAGING=1; fi
else
	say "zip is not installed; the files are in $OUT (not removed)"
	KEEP_STAGING=1
fi
say ""
say "${B}Done.${R} Before copying anything:"
say "  - read its section in README.md, Third-party code"
say "  - skim the release notes between your version and this one"
say "  - update app/includes/Vendor.php and the table in README.md"
say "  - send a test message (README.md says what to look for)"
cleanup
exit 0
