#!/bin/sh
#
# bin/lint.sh — pre-release checks for AESBin. Run from anywhere.
#
# Requires php. Optional: node (JS checks), and phpstan and Stylelint
# (section 6, each offered as a download when missing; Stylelint needs node
# and npm). Nothing touches the network unless you accept those prompts.
# POSIX sh, so it also runs under Termux's dash/ash.
#
# Most checks skip app/vendor/ (third-party code, see docs/vendored-libraries.md),
# bin/dist/ (build output: a full staged copy that would double every count)
# and bin/tools/stylelint/ (Stylelint's node_modules, installed by section 6,
# which ships PHP files of its own).
# 3h is the exception for app/vendor/, because the guard files there are ours.
#
# Exit status: 0 if everything passed, 1 otherwise.
#
#   ./bin/lint.sh                 run every check
#   ./bin/lint.sh --fix-hashes    only rewrite the stale CSP hashes of the
#                                 static pages in public/.htaccess (3h2)
#
# --fix-hashes is an action, not a check: it does its job and stops. A normal
# run on a terminal offers the same fix as a question; the option is for when
# there is no one to answer (CI, a pipe, a script).

set -u

FIX_HASHES=0
for _arg in "$@"; do
	case "$_arg" in
		--fix-hashes) FIX_HASHES=1 ;;
		*) printf 'unknown option: %s (only --fix-hashes)\n' "$_arg" >&2; exit 2 ;;
	esac
done

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT" || exit 1

# php runs most sections: without it, half the checks would be skipped and
# the rest would still say OK.
if ! command -v php >/dev/null 2>&1; then
	printf 'ERROR: php not found in PATH (required by this script).\n' >&2
	exit 1
fi

FAILED=0
# Every failure, reprinted together at the end: on a phone the output is
# several screens long and the FAIL lines are easy to scroll past.
FAIL_LIST=""
# Some POSIX shells lack `echo -e`; printf is portable.
say() { printf '%s\n' "$*"; }
fail() {
	printf '  FAIL  %s\n' "$*"
	FAILED=1
	# First line only: a message carrying a whole PHP error stays readable.
	FAIL_LIST="${FAIL_LIST}  - $(printf '%s\n' "$*" | head -n 1)
"
}

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
	B=$(printf '\033[1m'); Y=$(printf '\033[33m'); R=$(printf '\033[0m')
else
	B=''; Y=''; R=''
fi

# ── Shared by the checks and the two actions ─────────────────────────────────
# The static page whose <style> public/.htaccess allows by hash
# (3h2) and the local config.php compared with the template (5h). Both
# helpers in bin/tools/ print tab-separated lines and never a secret.
CSP_PAGES="public/help.html:style"
tag_label() {
	case "$1" in style) printf 'CSS' ;; script) printf 'JS' ;; *) printf '<%s>' "$1" ;; esac
}
csp_check() {
	# shellcheck disable=SC2086 # the page list splits on purpose
	CSP_OUT=$(php bin/tools/csp-hashes.php public/.htaccess $CSP_PAGES 2>&1)
	CSP_RC=$?
}
csp_ok() {
	[ "$CSP_RC" -eq 0 ] && ! printf '%s\n' "$CSP_OUT" | grep -qv '^OK'
}
csp_fix() {
	# shellcheck disable=SC2086
	CSP_FIXED=$(php bin/tools/csp-hashes.php --fix public/.htaccess $CSP_PAGES 2>/dev/null | awk -F'\t' '$1 == "FIXED"')
	printf '%s\n' "$CSP_FIXED" | while IFS='	' read -r _ TAG FILES; do
		[ -n "$FILES" ] && say "      CSP hashes    updated: $FILES $(tag_label "$TAG")"
	done
}
# "help.html CSS": each page once, with what it has.
csp_ok_list() {
	printf '%s\n' "$CSP_OUT" | awk -F'\t' '$1 == "OK" {
		lab = ($2 == "style") ? "CSS" : (($2 == "script") ? "JS" : $2)
		n = split($3, f, ", ")
		for (i = 1; i <= n; i++) {
			if (!(f[i] in m)) { o[++c] = f[i]; m[f[i]] = lab } else { m[f[i]] = m[f[i]] "+" lab }
		}
	}
	END { for (i = 1; i <= c; i++) printf "%s%s %s", (i > 1 ? ", " : ""), o[i], m[o[i]] }'
}
csp_show() {
	printf '%s\n' "$CSP_OUT" | while IFS='	' read -r ST TAG FILES HAVE WANT; do
		case "$ST" in
			OK) ;;
			STALE)
				say "      ${Y}different hashes${R}  $FILES $(tag_label "$TAG")"
				# .htaccess first, the hash to replace; then each page and its own.
				for h in $HAVE; do [ "$h" = - ] || say "        .htaccess  $h"; done
				for h in $WANT; do [ "$h" = - ] || printf '        %-9s  %s\n' "${h%%=*}" "${h#*=}"; done ;;
			NOTAG) say "      ${Y}no $(tag_label "$TAG") element${R} in $FILES" ;;
			NOBLOCK) say "      ${Y}no CSP line in a <Files> block for${R} $FILES $(tag_label "$TAG"); put by hand:"
			         say "        '$HAVE'" ;;
			*) say "      $ST $TAG $FILES" ;;
		esac
	done
}
cfg_check() {
	CFG_OUT=$(php bin/tools/config-check.php config.php 2>&1)
	CFG_RC=$?
}
cfg_show() {
	printf '%s\n' "$CFG_OUT" | while IFS='	' read -r ST K; do
		case "$ST" in
			ADDED)   say "        new key      $K (its default)" ;;
			CHANGED) say "        value reset  $K (not an allowed value)" ;;
			LOST)    say "        ${Y}dropped${R}      $K" ;;
			TEXT)    say "        $K line(s) of comments or layout" ;;
		esac
	done
}

# ── The action ───────────────────────────────────────────────────────────────
# --fix-hashes does its one job and stops: the checks are a separate run.
if [ "$FIX_HASHES" -eq 1 ]; then
	csp_fix
	csp_check
	if csp_ok; then
		[ -n "$CSP_FIXED" ] || say "      CSP hashes    already up to date — $(csp_ok_list)"
		exit 0
	fi
	csp_show
	exit 1
fi

say "${B}AESBin pre-release checks${R}  ($ROOT)"
say ""

# ── 1. PHP files ─────────────────────────────────────────────────────────────
say "${B}[1/6] PHP files${R}"
COUNT=0
BAD=0
# find -print0 is not portable, so the loop below splits on whitespace.
# That is only safe while no .php path contains any, which this enforces.
if find . \( -path ./app/vendor -o -path ./bin/dist -o -path ./bin/tools/stylelint \) -prune -o -name '*.php' -print | grep -q '[[:space:]]'; then
	fail "a .php path contains whitespace; fix it before relying on this script"
fi
for f in $(find . \( -path ./app/vendor -o -path ./bin/dist -o -path ./bin/tools/stylelint \) -prune -o -name '*.php' -print | sort); do
	COUNT=$((COUNT + 1))
	OUT=$(php -l "$f" 2>&1) || {
		printf '  %s\n' "$OUT"
		BAD=$((BAD + 1))
	}
done
if [ "$BAD" -gt 0 ]; then
	fail "$BAD of $COUNT PHP files failed to parse"
else
	say "      php -l        OK — $COUNT files, PHP $(php -r 'echo PHP_VERSION;')"
fi

# 1b. Every PHP file opens with the PSR-12 header (CONTRIBUTING.md), each
# block followed by one blank line: <?php, the file docblock if any,
# declare(strict_types=1), the namespace and use lines if any, and then the
# direct-access guard as the first statement:
#   defined("AESBIN_APP") || exit;    ('AESBIN_APP' in lang and config files)
# That is all of app/ but the vendor libraries, and config-sample.php (and so
# config.php). public/index.php is the entry point and defines AESBIN_APP
# there instead; bin/ is tooling that never ships, with no guard. Not in
# scope: the index.php silence stubs (one line, no code) and your config.php.
HEADER_OUT=$(for f in $(find . \( -path ./app/vendor -o -path ./bin/dist -o -path ./bin/tools/stylelint \) -prune -o -name '*.php' -print | sort); do
	case "$f" in
		./config.php) continue ;;
		*/index.php) [ "$(cat "$f")" = '<?php // Silence...' ] && continue ;;
	esac
	case "$f" in
		./public/index.php) echo "define $f" ;;
		./bin/*) echo "none $f" ;;
		*) echo "guard $f" ;;
	esac
done | php bin/tools/file-header.php AESBIN_APP)
if [ -n "$HEADER_OUT" ]; then
	printf '%s\n' "$HEADER_OUT"
	fail "$(printf '%s\n' "$HEADER_OUT" | wc -l | tr -d ' ') file(s) without the PSR-12 header and guard"
else
	say "      headers       OK — PSR-12, then defined(\"AESBIN_APP\") || exit"
fi
say ""

# ── 2. JavaScript syntax (optional) ──────────────────────────────────────────
say "${B}[2/6] node --check${R}"
if ! command -v node >/dev/null 2>&1; then
	say "      skipped — node not installed (optional)"
else
	COUNT=0
	BAD=0
	for f in $(find public/assets/js -name '*.js' 2>/dev/null | sort); do
		COUNT=$((COUNT + 1))
		OUT=$(node --check "$f" 2>&1) || {
			printf '  %s\n' "$OUT"
			BAD=$((BAD + 1))
		}
	done
	if [ "$BAD" -gt 0 ]; then
		fail "$BAD of $COUNT JS files failed to parse"
	else
		say "      OK — $COUNT files"
	fi
fi
say ""

# ── 3. Consistency greps ─────────────────────────────────────────────────────
# These encode invariants that are easy to reintroduce. See docs/maintaining.md,
# "Consistency greps", for why each one exists.
say "${B}[3/6] consistency${R}"

# 3a. Request body fields cast without a prior type check. A grep cannot tie
#     a cast to the check for the same key, so this only fails when a file
#     casts body fields and has no type check anywhere.
BODY_BAD=0
for f in $(grep -rl '\$body\[' app/actions/ 2>/dev/null | sort); do
	if grep -q '(\(int\|string\|bool\)) (\?\$body\[\|trim(\$body\[' "$f"; then
		if ! grep -q 'require_fields\|normalize_body_field\|is_scalar\|is_array' "$f"; then
			printf '  %s casts a body field with no type check anywhere in the file\n' "$f"
			BODY_BAD=$((BODY_BAD + 1))
		fi
	fi
done
if [ "$BODY_BAD" -gt 0 ]; then
	fail "$BODY_BAD action file(s) cast request body fields unchecked"
else
	say "      body fields   OK"
fi

# 3b. Share salts are decoded only by ShareService::salt(), which turns a
# corrupt value into the RuntimeException the share flows catch. A bare
# base64_decode() returns false, a TypeError in deriveShareKey().
SALT_BAD=$(grep -rn 'base64_decode([^;]*_salt' app/includes/ app/actions/ 2>/dev/null | grep -v 'prf_salt')
if [ -n "$SALT_BAD" ]; then
	printf '%s\n' "$SALT_BAD" | sed 's/^/  /'
	fail "share salt decoded outside ShareService::salt()"
else
	say "      share salts   OK"
fi

# 3c. Every version in app/includes/Vendor.php must appear in the table of
# docs/vendored-libraries.md. Vendor.php is the source of truth (the About panel renders from
# it), so a stale table contradicts the running application.
# Version and licence on the same table row: a licence stated wrongly in a
# public document is not an ordinary typo.
VENDOR_BAD=0
VENDOR_ROWS=$(php -r 'define("AESBIN_APP", true); require "app/includes/Vendor.php"; foreach (AESBin\Vendor::all() as $l) echo $l["version"], "|", $l["license"], "\n";' 2>/dev/null)
for row in $VENDOR_ROWS; do
	v=${row%%|*}; lic=${row#*|}
	for doc in docs/vendored-libraries.md; do
		if ! grep -qF "| $v | $lic" "$doc"; then
			say "      $doc has no row with version $v and licence $lic"
			VENDOR_BAD=$((VENDOR_BAD + 1))
		fi
	done
done
# Every library's credit line, in every language (the About panel prints
# credit_<role>; a key missing from all five languages keeps them in sync).
CREDIT_BAD=$(php -r '
	define("AESBIN_APP", true);
	require "app/includes/Vendor.php";
	foreach (glob("app/lang/*.php") as $f) {
		$s = require $f;
		if (!is_array($s)) { continue; }
		foreach (AESBin\Vendor::all() as $l) {
			if (!isset($s["credit_" . $l["role"]])) { echo basename($f), ": credit_", $l["role"], "\n"; }
		}
	}' 2>&1)
if [ -n "$CREDIT_BAD" ]; then
	printf '%s\n' "$CREDIT_BAD" | sed 's/^/      missing /'
	VENDOR_BAD=$((VENDOR_BAD + 1))
fi
if [ "$VENDOR_BAD" -gt 0 ]; then
	fail "$VENDOR_BAD vendor row(s) out of step with app/includes/Vendor.php"
else
	say "      vendor docs   OK — versions, licences, credits"
fi

# 3d. Every Prism component the fetcher pulls needs a row in docs/vendored-libraries.md
# saying what it is for.
PRISM_BAD=0
for c in $(sed -n 's/^PARTS="//p;/^prism-/p' bin/fetch-vendor.sh |
	tr ' ' '\n' | sed -n 's/^prism-\([a-z-]*\)"*$/\1/p' | sort -u); do
	grep -qF "\`$c\`" docs/vendored-libraries.md || {
		say "      docs/vendored-libraries.md has no row for prism-$c"
		PRISM_BAD=$((PRISM_BAD + 1))
	}
done
if [ "$PRISM_BAD" -gt 0 ]; then
	fail "$PRISM_BAD Prism component(s) undocumented"
else
	say "      prism table   OK"
fi

# 3e. Every t('key') the JS asks for must exist in app/lang/en.php.
#     A missing key renders as the raw key string in the UI.
if command -v php >/dev/null 2>&1; then
	T_MISSING=$(php -r '
		define("AESBIN_APP", true);
		$en = require "app/lang/en.php";
		$src = "";
		foreach (glob("public/assets/js/*.js") as $f) { $src .= file_get_contents($f); }
		// Comments stripped first: some quote t(\x27key\x27) as an example.
		// Block comments go entirely; line comments only when the // opens the
		// line, so a https:// inside real code is never cut.
		$src = preg_replace("#/\\*.*?\\*/#s", "", $src);
		$src = preg_replace("#^[ \\t]*//.*$#m", "", $src);
		preg_match_all("/(?<![\\w.])t\\(\\s*[\x27\"]([a-zA-Z0-9_]+)[\x27\"]\\s*\\)/", $src, $m);
		echo implode(", ", array_slice(array_values(array_unique(array_diff($m[1], array_keys($en)))), 0, 12));
	' 2>&1)
	if [ -n "$T_MISSING" ]; then
		printf '  JS asks for undefined language keys: %s\n' "$T_MISSING"
		fail "JS references language keys that do not exist"
	else
		say "      t() keys      OK"
	fi
fi

# 3e2. Lang::forScripts() ships a page only the keys it finds as literal
#     t('key') calls, or under a t('prefix' + …) / @lang-prefix declaration.
#     A key named anywhere else (a lookup table, an argument passed on to a
#     helper that calls t()) never reaches the page, and the UI shows the raw
#     key. Flags every quoted snake_case string in public/assets/js that is a
#     language key no script ships, and every quoted prefix concatenated
#     outside t() ('pw_strength_' + score) that no @lang-prefix covers. CODES lists API codes the scripts compare
#     against and never display. setup.js is left out: the installer page
#     carries its whole language table (SETUP_LANGS), not forScripts() keys.
if command -v php >/dev/null 2>&1; then
	T_UNSHIPPED=$(php -r '
		define("AESBIN_APP", true);
		$en = require "app/lang/en.php";
		$src = "";
		foreach (glob("public/assets/js/*.js") as $f) {
			if (basename($f) !== "setup.js") { $src .= file_get_contents($f) . "\n"; }
		}
		$prefixes = [];
		if (preg_match_all("/@lang-prefix\\s+([a-zA-Z0-9_]+)/", $src, $m)) { $prefixes = $m[1]; }
		$src = preg_replace("#/\\*.*?\\*/#s", "", $src);
		$src = preg_replace("#^[ \\t]*//.*$#m", "", $src);
		preg_match_all("/(?<![\\w.])t\\(\\s*[\x27\"]([a-zA-Z0-9_]+)[\x27\"]/", $src, $m);
		$shipped = array_flip($m[1]);
		if (preg_match_all("/(?<![\\w.])t\\(\\s*[\x27\"]([a-zA-Z0-9_]+)[\x27\"]\\s*\\+/", $src, $m)) {
			$prefixes = array_merge($prefixes, $m[1]);
		}
		const CODES = ["csrf_failed", "key_mismatch"];
		preg_match_all("/[\x27\"`]([a-z][a-z0-9]*_[a-z0-9_]+)[\x27\"`]/", $src, $m);
		$bad = [];
		foreach (array_unique($m[1]) as $k) {
			if (!isset($en[$k]) || isset($shipped[$k]) || in_array($k, CODES, true)) { continue; }
			foreach ($prefixes as $p) { if (str_starts_with($k, $p)) { continue 2; } }
			$bad[] = $k;
		}
		// A key built by concatenation outside t() ("pw_strength_" + score,
		// stored and passed to t() later): the prefix needs an @lang-prefix
		// declaration, or none of its keys ships.
		if (preg_match_all("/[\x27\"]([a-z][a-z0-9_]*_)[\x27\"]\\s*\\+/", $src, $m)) {
			foreach (array_unique($m[1]) as $p) {
				foreach ($prefixes as $q) { if (str_starts_with($p, $q)) { continue 2; } }
				foreach (array_keys($en) as $k) {
					if (str_starts_with($k, $p)) { $bad[] = $p . "* (no @lang-prefix)"; break; }
				}
			}
		}
		echo implode(", ", array_slice($bad, 0, 12));
	' 2>&1)
	if [ -n "$T_UNSHIPPED" ]; then
		printf '  keys named in JS but never shipped by Lang::forScripts(): %s\n' "$T_UNSHIPPED"
		fail "JS names language keys outside a literal t() call"
	else
		say "      t() shipping  OK"
	fi
fi

# 3f. Fields the dashboard reads off the integrity API must be ones it emits.
#     A missing field leaves its section empty, and the banner could report
#     "no issues" while ok is false.
INT_BAD=0
for field in schema_checks multi_user missing orphans misplaced duplicates counts; do
	if grep -q "info\.$field" public/assets/js/dashboard.js 2>/dev/null; then
		if ! grep -q "\"$field\" =>" app/actions/system/integrity_check.php 2>/dev/null; then
			printf '  dashboard.js reads info.%s but integrity_check.php never emits it\n' "$field"
			INT_BAD=$((INT_BAD + 1))
		fi
	fi
done
if [ "$INT_BAD" -gt 0 ]; then
	fail "$INT_BAD integrity field(s) read by the UI are never produced"
else
	say "      api fields    OK"
fi

# 3g. Language files must all define the same keys.
if command -v php >/dev/null 2>&1; then
	LANG_OUT=$(php -r '
		// app/lang/*.php carry the same direct-access guard every other file does
		// (defined("AESBIN_APP") || exit;), so requiring one without this
		// kills the whole process before a single key is compared.
		define("AESBIN_APP", true);
		$files = glob("app/lang/*.php");
		$sets = [];
		foreach ($files as $f) {
			$strings = require $f;
			// A file that returns no array (a stray index.php guard, say)
			// is skipped rather than treated as a language.
			if (!is_array($strings)) { continue; }
			$sets[basename($f, ".php")] = $strings;
		}
		$ref = array_keys($sets["en"] ?? reset($sets));
		$bad = 0;
		foreach ($sets as $code => $strings) {
			$missing = array_diff($ref, array_keys($strings));
			$extra   = array_diff(array_keys($strings), $ref);
			if ($missing) { echo "  $code missing: " . implode(", ", array_slice($missing, 0, 8)) . "\n"; $bad++; }
			if ($extra)   { echo "  $code extra:   " . implode(", ", array_slice($extra, 0, 8)) . "\n"; $bad++; }
		}
		echo $bad === 0 ? "OK" : "BAD";
	' 2>&1)
	case "$LANG_OUT" in
		*OK) say "      lang keys     OK — $(find app/lang -maxdepth 1 -name '*.php' ! -name index.php | wc -l | tr -d ' ') locales in sync" ;;
		*)   printf '%s\n' "$LANG_OUT" | grep -v '^BAD$'
		     fail "language files are out of sync" ;;
	esac
fi

# 3g2. One quoting style in the language files: double quotes everywhere, so
# an apostrophe (l'archivio, aujourd'hui) needs no escape and \n is a line
# break. The price is that "$" would interpolate a variable: no string may.
LQ_BAD=$(php -r '
	foreach (glob("app/lang/*.php") as $f) {
		if (basename($f) === "index.php") {
			continue;
		}
		foreach (token_get_all((string) file_get_contents($f)) as $t) {
			if (!is_array($t)) {
				continue;
			}
			if ($t[0] === T_CONSTANT_ENCAPSED_STRING && $t[1][0] !== "\"") {
				echo basename($f), ":", $t[2], ": single-quoted string\n";
			} elseif (in_array($t[0], [T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
				echo basename($f), ":", $t[2], ": a variable in a string (write \\$)\n";
			}
		}
	}
' 2>&1)
if [ -n "$LQ_BAD" ]; then
	printf '%s\n' "$LQ_BAD" | head -n 10 | sed 's/^/  /'
	fail "language files: strings must be double-quoted, with no variables"
else
	say "      lang quotes   OK — double quotes, no variables"
fi

# 3h. Every directory under public/ and app/ needs an index.php (or, in
# public/, index.html) guard against listing. In public/, in case Options
# -Indexes is overridden (a subdirectory .htaccess, another vhost, a host that
# ignores .htaccess); app/ has no URL, but a web root pointed one level too
# high would give it one. The vendor folders are in scope: their stubs are
# AESBin's own, written by bin/fetch-vendor.sh.
DIR_BAD=0
for d in $(find public app -type d | sort); do
	if [ ! -f "$d/index.php" ] && [ ! -f "$d/index.html" ]; then
		printf '  %s has no index.php or index.html guard\n' "$d"
		DIR_BAD=$((DIR_BAD + 1))
	fi
done
if [ "$DIR_BAD" -gt 0 ]; then
	fail "$DIR_BAD director(ies) with no directory-listing guard"
else
	say "      dir guards    OK"
fi

# 3h2. help.html's <style> is allowed by hash (its own <Files> block in
# public/.htaccess): the hash must match the file byte for byte, or the page
# renders unstyled. bin/tools/csp-hashes.php reads the blocks.
# Stale hashes are shown next to the right ones and, on a terminal, fixed on
# request (--fix-hashes does it without asking).
csp_check
CSP_SHOWN=0
if printf '%s\n' "$CSP_OUT" | grep -q '^STALE' && [ -t 0 ] && [ -t 1 ]; then
	csp_show
	CSP_SHOWN=1
	printf '      Fix them automatically? [Y/n] '
	read -r REPLY
	case "$REPLY" in
	""|[yY]*) csp_fix; csp_check; CSP_SHOWN=0 ;;
	esac
fi
if csp_ok; then
	say "      CSP hashes    OK — $(csp_ok_list)"
else
	[ "$CSP_SHOWN" -eq 1 ] || csp_show
	if printf '%s\n' "$CSP_OUT" | grep -q '^STALE'; then
		say "      to fix them: ./bin/lint.sh --fix-hashes"
	fi
	fail "public/.htaccess: CSP hashes of the static pages out of date"
fi

# 3i. The minimum versions in app/includes/Requirements.php (enforced by the
# installer) must match README.md, docs/installation.md, the PHPStan range and
# the first CI matrix entry. A trailing ".0" is dropped, as the documents
# write it ("8.3+", "10.3+").
REQ_BAD=0
req_short() {
	sed -n "s/.*const $1 = \"\([0-9.]*\)\".*/\1/p" app/includes/Requirements.php | sed 's/\.0$//'
}
REQ_PHP=$(req_short PHP_MIN)
REQ_MYSQL=$(req_short MYSQL_MIN)
REQ_MARIADB=$(req_short MARIADB_MIN)
for doc in README.md docs/installation.md; do
	for v in "PHP | $REQ_PHP+" "MySQL $REQ_MYSQL+" "MariaDB $REQ_MARIADB+"; do
		# installation.md puts MySQL in its own column: "| MySQL | 5.7.8+".
		grep -qF "$v" "$doc" || grep -qF "$(printf '%s' "$v" | sed 's/^MySQL /MySQL | /')" "$doc" || {
			say "      $doc does not state \"$v\""
			REQ_BAD=$((REQ_BAD + 1))
		}
	done
done
grep -qF "PHP-$REQ_PHP%2B" README.md || {
	say "      README.md badge does not show PHP $REQ_PHP+"
	REQ_BAD=$((REQ_BAD + 1))
}
REQ_PHP_ID=$(printf '%s' "$REQ_PHP" | awk -F. '{ printf "%d%02d00", $1, $2 }')
grep -qE "^[[:space:]]*min:[[:space:]]*$REQ_PHP_ID\b" bin/tools/phpstan.neon || {
	say "      bin/tools/phpstan.neon min is not $REQ_PHP_ID"
	REQ_BAD=$((REQ_BAD + 1))
}
# .github/ may be missing from a copy that is not a git checkout (a review
# zip): there is no CI to compare then, so the check runs only when it exists.
if [ -d .github/workflows ]; then
	grep -qE "php: \[\"$REQ_PHP\"" .github/workflows/*.yml || {
		say "      the CI matrix does not start at PHP $REQ_PHP"
		REQ_BAD=$((REQ_BAD + 1))
	}
fi
if [ "$REQ_BAD" -gt 0 ]; then
	fail "$REQ_BAD requirement statement(s) out of step with app/includes/Requirements.php"
else
	say "      requirements  OK"
fi

# 3j. The minimum password length is enforced in PHP (CryptoService) and
# checked early in the browser (helper.js). The two must agree.
PW_PHP=$(sed -n 's/.*const PASSWORD_MIN_LENGTH = \([0-9]*\);.*/\1/p' app/includes/CryptoService.php)
PW_JS=$(sed -n 's/.*const PASSWORD_MIN_LENGTH = \([0-9]*\);.*/\1/p' public/assets/js/helper.js)
if [ -z "$PW_PHP" ] || [ "$PW_PHP" != "$PW_JS" ]; then
	fail "password minimum differs: CryptoService '$PW_PHP', helper.js '$PW_JS'"
else
	say "      password min  OK — $PW_PHP"
fi

# 3k. base.css is the only place the palette is declared: the page files,
# setup.css included, load after it and must not redeclare a token.
TOKEN_BAD=$(grep -n '^[[:space:]]*--[a-z0-9-]*:' public/assets/css/*.css |
	grep -v '^public/assets/css/base.css:' | sed 's/^/  /')
if [ -n "$TOKEN_BAD" ]; then
	printf '%s\n' "$TOKEN_BAD"
	fail "a stylesheet other than base.css declares a colour token"
else
	say "      css tokens    OK"
fi

# 3l. Associated data is built only in CryptoService's "Associated data"
# section. A string written by hand at a call site drifts from the one the
# data was sealed with, and nothing reports it until a decrypt fails.
AAD_BAD=$(grep -rnE '"(name|mime|desc|hashes|container|directory|directory_desc|dek|file|totp|masterkey|session|sharekey|sharename|sharedir_desc|sharesess)(_snap)?:("| \.|\{)' \
	app/includes/ app/actions/ app/setup/index.php 2>/dev/null | grep -v '^app/includes/CryptoService.php:' |
	grep -vE '^[^:]+:[0-9]+:[[:space:]]*(//|\*)')
if [ -n "$AAD_BAD" ]; then
	printf '%s\n' "$AAD_BAD" | sed 's/^/  /'
	fail "AAD written by hand outside CryptoService"
else
	say "      AAD builders  OK"
fi

# 3m. Static files are cached for a year (.htaccess), so every URL the code
# prints for one must carry ?v=: asset() or asset_version() in PHP, the URLs
# document-end.php passes to JavaScript. A path to vendor/ or
# public/assets/ built by hand would be served stale after an update. The one
# exception is pdf.js's wasmUrl folder, which .htaccess revalidates instead.
CACHE_BAD=$( {
	grep -rnE "['\"\`][^'\"\`]*/(vendor|assets)/" public/assets/js --include='*.js' 2>/dev/null |
		grep -v '/vendor/pdfjs/nowasm/'
	grep -rnE '(base_url|AESBIN_BASE)[^;]*(vendor|public/assets)/' app/views app/includes app/actions --include='*.php' 2>/dev/null
	grep -nE '/(vendor|assets)/' app/setup/template.php 2>/dev/null | grep -v 'asset_version('
} | grep -vE '^[^:]+:[0-9]+:[[:space:]]*(//|\*|#)')
if [ -n "$CACHE_BAD" ]; then
	printf '%s\n' "$CACHE_BAD" | sed 's/^/  /'
	fail "static file URL without ?v= (see .htaccess, cache for static files)"
else
	say "      static ?v=    OK"
fi

# 3n. Icons: every name the code draws is a <symbol> in the sprite, and every
# symbol is drawn somewhere. A missing one renders as an empty box with no
# error anywhere. Names are read from icon() calls and from the helpers that
# take one (makeCard, setIconText, setup.js setLabel, the eye/copy toggles).
ICON_USED=$( {
	grep -rhoE "icon\(['\"][a-z0-9-]+['\"]" app/views app/setup public/assets app/includes app/actions --include='*.php' --include='*.js'
	grep -rhoE "(makeCard\(|setIconText\([a-zA-Z]+, )'[a-z0-9-]+'" public/assets/js
	grep -hoE "setLabel\(btn, '[a-z_]+', '[a-z0-9-]+'" public/assets/js/setup.js | sed -E "s/^.*, //"
	grep -rhoE "icon\([a-z]+ \? '[a-z-]+' : '[a-z-]+'\)|label\('[a-z-]+', '" public/assets/js
} | grep -oE "['\"][a-z0-9-]+['\"]" | tr -d "'\"" | sort -u)
ICON_DEFINED=$(grep -oE '<symbol id="i-[a-z0-9-]+"' public/assets/img/icons.svg | sed -E 's/.*"i-([^"]+)"/\1/' | sort -u)
ICON_MISSING=$(printf '%s\n' "$ICON_USED" | grep -vxF -f /dev/fd/3 3<<EOF_ICONS
$ICON_DEFINED
EOF_ICONS
)
ICON_UNUSED=$(printf '%s\n' "$ICON_DEFINED" | grep -vxF -f /dev/fd/3 3<<EOF_ICONS
$ICON_USED
EOF_ICONS
)
if [ -n "$ICON_MISSING" ]; then
	printf '  missing from icons.svg: %s\n' $ICON_MISSING
	fail "icon drawn but not in public/assets/img/icons.svg"
elif [ -n "$ICON_UNUSED" ]; then
	printf '  never drawn: %s\n' $ICON_UNUSED
	fail "icons.svg carries symbols nothing uses"
else
	say "      icons         OK — $(printf '%s\n' "$ICON_DEFINED" | wc -l | tr -d ' ') symbols"
fi
say ""

# ── 4. Release hygiene ───────────────────────────────────────────────────────
say "${B}[4/6] release hygiene${R}"

# Leftover fetch-vendor.sh archives, listed for housekeeping only: bin/dist/
# is git-ignored, so they cannot reach a tagged release.
STAGING=$(ls bin/dist/aesbin-vendor-*.zip 2>/dev/null | head -3)
if [ -n "$STAGING" ]; then
	printf '%s\n' "$STAGING" | sed 's/^/                    /'
	say "      staging       old vendor archives, safe to delete"
fi

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
PACKAGE_WANT=".htaccess LICENSE app config-sample.php public"
PACKAGE_HAVE=""
PACKAGE_HOW=""
if command -v git >/dev/null 2>&1 && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	PACKAGE_REV=$(git stash create 2>/dev/null)
	[ -n "$PACKAGE_REV" ] || PACKAGE_REV=HEAD
	# This project's own folder ("" at the repository's root), archived from
	# the top: run from a subfolder, git archive would look for it again inside.
	PACKAGE_DIR=$(git rev-parse --show-prefix)
	# Byte order (LC_ALL=C): in a UTF-8 locale sort ignores case and dots,
	# and "LICENSE" would land after "config-sample.php".
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
	say "      package       skipped (needs git)"
elif [ "$PACKAGE_HAVE" = "$PACKAGE_WANT" ]; then
	say "      package       OK — the site only, as GitHub will zip it$PACKAGE_HOW"
else
	printf '  expected: %s\n  found:    %s\n' "$PACKAGE_WANT" "$PACKAGE_HAVE"
	fail "the Source code archive is not the site only: add export-ignore lines to .gitattributes (or update PACKAGE_WANT)"
fi

# config.php is per-install and holds the database password. In the project
# folder it is allowed only as a local install's copy that git ignores (5h
# compares it with the template); committed, it would ship with the source.
if [ ! -f config.php ]; then
	say "      config.php    absent  OK"
elif command -v git >/dev/null 2>&1 && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	if git ls-files --error-unmatch config.php >/dev/null 2>&1; then
		fail "config.php is committed: it is per-install (git rm --cached config.php)"
	elif git check-ignore -q config.php; then
		say "      config.php    local, ignored by git  OK"
	else
		fail "config.php is not ignored by git: restore /config.php in .gitignore"
	fi
else
	say "      config.php    ${Y}present${R}: leave it out of any zip made by hand"
fi

# AESBIN_VERSION and the latest release in CHANGELOG.md (an [Unreleased]
# heading above it is skipped): the update check compares the tag with the
# constant, so a mismatch tells every install it is out of date.
VERSION=$(sed -n 's/^define("AESBIN_VERSION", "\([^"]*\)");.*/\1/p' app/includes/bootstrap.php)
CHANGED=$(grep -m1 -oE '^## \[[0-9]+\.[0-9]+\.[0-9]+\]' CHANGELOG.md 2>/dev/null | tr -d '#[] ')
if [ -n "$VERSION" ] && [ "$VERSION" = "$CHANGED" ]; then
	say "      version       $VERSION (AESBIN_VERSION = CHANGELOG.md)  OK"
else
	fail "AESBIN_VERSION ($VERSION) differs from CHANGELOG.md's latest release ($CHANGED)"
fi

LEFTOVER=$(find . \( -path ./app/vendor -o -path ./bin/dist -o -path ./bin/tools/stylelint \) -prune -o \( -name '*.orig' -o -name '*.rej' -o -name '*.bak' -o -name '.DS_Store' -o -name '*.swp' \) -print 2>/dev/null)
if [ -n "$LEFTOVER" ]; then
	printf '%s\n' "$LEFTOVER" | sed 's/^/  /'
	fail "editor or merge leftovers present"
else
	say "      leftovers     none    OK"
fi

# Only bin/ is excluded: it is developer tooling that does not ship.
# Everything that ships is in scope; do not widen the exclusion to silence a hit.
DEBUG_HITS=$(grep -rn 'var_dump(\|print_r(\|error_log("DEBUG\|console\.log(\|debugger;' \
	--include='*.php' --include='*.js' . 2>/dev/null \
	| grep -v '^./app/vendor/' | grep -v '^./bin/' | grep -v 'console\.error')
if [ -n "$DEBUG_HITS" ]; then
	printf '%s\n' "$DEBUG_HITS" | sed 's/^/  /'
	fail "debug output left in the code"
else
	say "      debug output  none    OK"
fi
say ""

# ── 5. Extended consistency ──────────────────────────────────────────────────
# Regression checks. Finding nothing is the expected result.
say "${B}[5/6] extended consistency${R}"
say ""

# ── 5a. Language keys defined and never used ─────────────────────────────────
SEC="5a. Language keys defined and never used"
say "${B}$SEC${R}"
# One grep -F -f pass rather than a loop per key, which takes minutes on a phone.
#
# These families are built at runtime from an id, so no literal search finds
# them:
#   log_*                'log_' + event                    dashboard.js
#   req_hint_*           __($r["hint"])                    requirements card
#   cat_*                __("cat_" . $cat)                 MIME categories
#   credit_*             __("credit_" . $lib["role"])      libraries panel
#   integrity_confirm_*  t('integrity_confirm_' + action)  dashboard.js
#   meta_field_*         t('meta_field_' + e.key)          strip-meta.js
# Add a family only after checking it really is built that way.
LANG_KEYS="$(mktemp)"; USED_KEYS="$(mktemp)"
trap 'rm -f "$LANG_KEYS" "$USED_KEYS"' EXIT
grep -oE '^[[:space:]]*"[a-z0-9_]+"[[:space:]]*=>' app/lang/en.php \
	| grep -oE '"[a-z0-9_]+"' | tr -d '"' \
	| grep -vE '^(log_|req_hint_|cat_|credit_|integrity_confirm_|meta_field_)' | sort -u > "$LANG_KEYS"
find app/actions app/includes app/views app/setup public/assets -name '*.php' -o -name '*.js' \
	| grep -v '/vendor/' \
	| xargs grep -ohF -f "$LANG_KEYS" 2>/dev/null | sort -u > "$USED_KEYS"
ORPHANS="$(comm -23 "$LANG_KEYS" "$USED_KEYS")"
if [ -n "$ORPHANS" ]; then
	printf '%s\n' "$ORPHANS" | while IFS= read -r k; do say "      ${Y}orphan${R} $k"; done
	say "      (remove from all 5 languages, or use them)"
	fail "$SEC"
else
	say "      $(wc -l < "$LANG_KEYS" | tr -d ' ') keys, none orphaned OK"
fi
say ""

# ── 5b. CSS classes declared and never used ──────────────────────────────────
SEC="5b. CSS classes never used in markup or JS"
say "${B}$SEC${R}"
# awk strips comments because CSS comments span lines; a line-based sed would
# report commented-out selectors as dead.
#
# prism-aesbin.css is skipped: Prism applies its classes at runtime. toast-* is
# excluded because dashboard.js builds it as `toast toast-${type}`.
CSS_CLASSES="$(mktemp)"; USED_CLASSES="$(mktemp)"
trap 'rm -f "$LANG_KEYS" "$USED_KEYS" "$CSS_CLASSES" "$USED_CLASSES"' EXIT
awk '
	{ line = $0
	  out = ""
	  while (length(line) > 0) {
	    if (inc) { i = index(line, "*/"); if (i == 0) { line = ""; break }
	               line = substr(line, i + 2); inc = 0 }
	    else     { i = index(line, "/*");  if (i == 0) { out = out line; break }
	               out = out substr(line, 1, i - 1); line = substr(line, i + 2); inc = 1 }
	  }
	  print out }
' $(ls public/assets/css/*.css | grep -v prism-aesbin) \
	| grep -oE '\.[a-zA-Z_-][a-zA-Z0-9_-]*' | tr -d '.' \
	| grep -vE '^(altcha|prism|token|line-numbers|toast-)' | sort -u > "$CSS_CLASSES"
find app/actions app/includes app/views app/setup public/assets -name '*.php' -o -name '*.js' -o -name '*.html' -o -name '*.svg' \
	| grep -v '/vendor/' \
	| xargs grep -ohwF -f "$CSS_CLASSES" 2>/dev/null | sort -u > "$USED_CLASSES"
DEAD_CSS="$(comm -23 "$CSS_CLASSES" "$USED_CLASSES")"
if [ -n "$DEAD_CSS" ]; then
	printf '%s\n' "$DEAD_CSS" | while IFS= read -r c; do say "      ${Y}never used${R} .$c"; done
	fail "$SEC"
else
	say "      $(wc -l < "$CSS_CLASSES" | tr -d ' ') classes, none dead OK"
fi
say ""

# ── 5c. Files cited in the documentation that do not exist ───────────────────
SEC="5c. Files cited in docs but absent from the package"
say "${B}$SEC${R}"
MISSING_DOC=""
# A heredoc: dash has no "<<<", and a pipe would run the loop in a subshell
# and lose MISSING_DOC.
# Excluded because they do not ship: bin/tools/phpstan.phar,
# bin/tools/stylelint/ and bin/tools/.latest-versions (fetched or written on
# demand), bin/dist/ (written by make-release.sh and fetch-vendor.sh, git-ignored,
# so a fresh clone or CI run does not have it) and app/setup/_viewme/ (created
# by the installer).
DOC_REFS="$(grep -ohE '`(app|public|bin|docs)/[A-Za-z0-9_./-]+`' \
	README.md SECURITY.md CONTRIBUTING.md docs/*.md 2>/dev/null \
	| tr -d '`' | grep -vE '^(bin/tools/phpstan\.phar|bin/tools/stylelint/|bin/tools/\.latest-versions|bin/dist/|app/setup/_viewme/)$' | sort -u)"
while IFS= read -r ref; do
	[ -z "$ref" ] && continue
	[ -e "$ref" ] || MISSING_DOC="$MISSING_DOC $ref"
done <<EOF
$DOC_REFS
EOF
# Screenshots are linked by absolute URL (they have to show on sites that render
# the Markdown outside GitHub), which no check above reads: a misspelt name would
# only show as a broken image once the repository is public. Each
# .../raw/main/screenshots/<name> must be a file in screenshots/.
SHOT_REFS="$(grep -ohE 'raw/main/screenshots/[A-Za-z0-9_.-]+' \
	README.md CONTRIBUTING.md docs/*.md 2>/dev/null | sed 's|^raw/main/||' | sort -u)"
while IFS= read -r ref; do
	[ -z "$ref" ] && continue
	[ -e "$ref" ] || MISSING_DOC="$MISSING_DOC $ref"
done <<EOF
$SHOT_REFS
EOF
if [ -n "$MISSING_DOC" ]; then
	for f in $MISSING_DOC; do say "      ${Y}cited but missing${R} $f"; done
	fail "$SEC"
else
	say "      all present OK"
fi
say ""

# ── 5d. Placeholders ─────────────────────────────────────────────────────────
# Translations use named {placeholders} only, filled by Lang::fill() in PHP and
# fmt() in helper.js. Three checks:
#   - every language carries exactly the placeholders of en, or a value
#     silently drops out of the sentence (or shows as a literal {name});
#   - no printf-style %s/%d is left in a language file: it would never be
#     filled, and a stray % breaks sprintf();
#   - every literal __("key", [...]), Lang::fill(setup_tr("key"), [...]) and
#     fmt(t('key'), {...}) call passes exactly the names the English string
#     uses.

SEC="5d. {placeholders} in the language files and their callers"
say "${B}$SEC${R}"
PH_OUT=$(php -r '
	define("AESBIN_APP", true);
	$names = static function (string $s): array {
		preg_match_all("/\{([a-z_]+)\}/", $s, $m);
		$n = array_unique($m[1]); sort($n); return $n;
	};
	$sets = [];
	foreach (glob("app/lang/*.php") as $f) {
		$s = require $f;
		if (is_array($s)) { $sets[basename($f, ".php")] = $s; }
	}
	$en = $sets["en"];
	$bad = [];
	foreach ($sets as $code => $strings) {
		foreach ($strings as $key => $val) {
			if (preg_match("/%[a-z]/", (string) $val)) { $bad[] = "$code:$key uses printf-style %"; }
			if (isset($en[$key]) && $names((string) $val) !== $names((string) $en[$key])) {
				$bad[] = "$code:$key placeholders differ from en";
			}
		}
	}
	// Callers: PHP __("key", ["a" => …]) and JS fmt(t(\x27key\x27), { a: …, b }).
	$files = array_merge(
		glob("app/{actions,includes,views,views/partials}/*.php", GLOB_BRACE) ?: [],
		glob("app/actions/*/*.php") ?: [],
		["app/setup/index.php"],
		glob("public/assets/js/*.js") ?: []
	);
	foreach ($files as $file) {
		$src = (string) file_get_contents($file);
		preg_match_all("/(?:__\(|Lang::fill\(setup_tr\()\"([a-z0-9_]+)\"\)?,\s*\[(.*?)\]\s*\)/s", $src, $php, PREG_SET_ORDER);
		preg_match_all("/fmt\((?:esc\()?t\(\x27([a-z0-9_]+)\x27\)\)?,\s*\{(.*?)\}\s*\)/s", $src, $js, PREG_SET_ORDER);
		$check = static function (string $key, array $given) use ($en, $names, $file, &$bad): void {
			if (!isset($en[$key])) { return; }
			$given = array_unique($given); sort($given);
			if ($given !== $names((string) $en[$key])) {
				$bad[] = "$file: $key passes {" . implode(",", $given) . "}, en has {" . implode(",", $names((string) $en[$key])) . "}";
			}
		};
		foreach ($php as [, $key, $args]) {
			preg_match_all("/\"([a-z_]+)\"\s*=>/", $args, $m);
			$check($key, $m[1]);
		}
		foreach ($js as [, $key, $args]) {
			// Top-level names only: strings, calls and index expressions go first.
			$flat = (string) preg_replace(["/\x27[^\x27]*\x27|\"[^\"]*\"|`[^`]*`/", "/\([^()]*\)/", "/\[[^\[\]]*\]/"], "", $args);
			$flat = (string) preg_replace(["/\([^()]*\)/", "/\[[^\[\]]*\]/"], "", $flat);
			preg_match_all("/(?:^|,)\s*([a-z_]+)\s*(?=[:,]|$)/", trim($flat), $m);
			$check($key, $m[1]);
		}
	}
	echo $bad ? implode("\n", $bad) . "\nBAD" : "OK";
' 2>&1)
case "$PH_OUT" in
	OK) say "      all consistent OK" ;;
	*)  printf '%s\n' "$PH_OUT" | grep -v '^BAD$' | while IFS= read -r l; do say "      ${Y}$l${R}"; done
	    fail "$SEC" ;;
esac
say ""

# ── 5e. Inline style.display in the JavaScript ───────────────────────────────
# The application hides elements with the `hidden` property. An inline
# style.display overrides the stylesheet and can leave an element stuck
# visible or stuck hidden.

SEC="5e. Inline style.display in the JavaScript"
say "${B}$SEC${R}"
INLINE_DISPLAY="$(grep -rn "style\.display" public/assets/js --include='*.js' 2>/dev/null \
	| grep -v '/vendor/' | grep -vE ':[[:space:]]*(\*|//)' || true)"
if [ -n "$INLINE_DISPLAY" ]; then
	printf '%s\n' "$INLINE_DISPLAY" | while IFS= read -r l; do say "      ${Y}$l${R}"; done
	say "      (use the hidden property, or .show for alerts)"
	fail "$SEC"
else
	say "      none OK"
fi
say ""

# ── 5f. Alternative PHP syntax balanced ──────────────────────────────────────
# if: / endif;, foreach: / endforeach; and so on. php -l only catches an
# imbalance that stops the file parsing; otherwise it just yields bad HTML.
#
# Tokenizer rather than grep: a regex cannot reliably tell "if (...) {" from
# "if (...):", and misses conditions that wrap across lines.
SEC="5f. Alternative PHP syntax (if:/endif, foreach:/endforeach)"
say "${B}$SEC${R}"
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
' -- $(find app/actions app/includes app/views app/setup public/assets -name '*.php' -print) 2>&1)
if [ -n "$ALT_BAD" ]; then
	printf '%s\n' "$ALT_BAD" | while IFS= read -r l; do say "      ${Y}$l${R}"; done
	fail "$SEC"
else
	say "      balanced OK"
fi
say ""

# ── 5g. Variable reassigned immediately without being read ───────────────────
# $x = ...; followed straight away by another $x = ...; discards the first
# value unread, usually a copy-paste leftover. Valid syntax, so php -l is silent.
SEC="5g. Variable reassigned immediately without being read"
say "${B}$SEC${R}"
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
' -- $(find app/actions app/includes app/views app/setup public/assets -name '*.php' -print) 2>&1)
if [ -n "$DEAD_STORE" ]; then
	printf '%s\n' "$DEAD_STORE" | while IFS= read -r l; do say "      ${Y}$l${R}"; done
	fail "$SEC"
else
	say "      none OK"
fi
say ""

# ── 5h. config-sample.php and config.php in step with the generator ──────────
# config-sample.php (for manual installs) and render_config_php() (the
# installer) must define the same keys and, where PHP is available, the same
# text.
SEC="5h. config-sample.php and config.php in step with the generator"
say "${B}$SEC${R}"
# [[:space:]], not \t: in a POSIX ERE \t is the letter t. Nested keys
# (db.host, captcha.secret, ...) count too.
SAMPLE_KEYS=$(sed -n "s/^[[:space:]]*'\([a-z_]*\)'[[:space:]]*=>.*/\1/p" config-sample.php | sort -u)
GEN_KEYS=$(sed -n "s/.*'\([a-z_]*\)'[[:space:]]*=>.*/\1/p" app/includes/config_template.php | sort -u)
KEY_DIFF=""
for k in $SAMPLE_KEYS; do
	printf '%s\n' "$GEN_KEYS" | grep -qx "$k" || KEY_DIFF="$KEY_DIFF sample-only:$k"
done
for k in $GEN_KEYS; do
	printf '%s\n' "$SAMPLE_KEYS" | grep -qx "$k" || KEY_DIFF="$KEY_DIFF generator-only:$k"
done
if [ -n "$KEY_DIFF" ]; then
	for d in $KEY_DIFF; do say "      ${Y}$d${R}"; done
	fail "config-sample.php and render_config_php() disagree on which keys exist"
else
	say "      $(printf '%s\n' "$SAMPLE_KEYS" | wc -l | tr -d ' ') keys, both agree OK"
fi
# Beyond the keys, the text: the sample rendered with its own values must come
# out identical, except the header and the values the installer fills in
# (storage path, the two captcha secrets).
if command -v php >/dev/null 2>&1; then
	SAMPLE_DIFF=$(php -r '
		define("AESBIN_APP", true);
		define("AESBIN_ROOT", getcwd());
		require "app/includes/helpers.php";
		require "app/includes/Requirements.php";
		require "app/includes/config_template.php";
		$sample = file_get_contents("config-sample.php");
		$out = render_config_php(require "config-sample.php");
		$mask = static fn(string $s): array => explode("\n", preg_replace(
			["#\A<\?php\n\n/\*\*.*?\*/\n\ndeclare\(strict_types=1\);\n\ndefined\(\x27AESBIN_APP\x27\) \|\| exit;\n\n#s", "#(\x27storage_path\x27 => ).*,#", "#(\x27(hmac_secret|key_signature_secret)\x27 +=> ).*,#"],
			["", "\\1…,", "\\1…,"],
			$s
		));
		$a = $mask($sample);
		$b = $mask($out);
		foreach ($a as $i => $line) {
			if (($b[$i] ?? null) !== $line) {
				echo "sample \"", trim($line), "\" vs generated \"", trim($b[$i] ?? ""), "\"";
				exit;
			}
		}
		if (count($b) !== count($a)) echo "generated file has ", count($b) - count($a), " extra line(s)";
	' 2>&1)
	if [ -n "$SAMPLE_DIFF" ]; then
		say "      $SAMPLE_DIFF"
		fail "config-sample.php text differs from what render_config_php() writes"
	else
		say "      text matches the generator OK"
	fi
fi
# A local install's config.php (never shipped, never a failure). Written by
# an older version it keeps the old comments and lacks the keys added since:
# the next settings save rewrites it through the template, so this only
# reports. What it adds is the warning a save would not give: a key written
# by hand that the template does not know is dropped by that save.
# bin/tools/config-check.php never prints a value.
if [ -f config.php ]; then
	cfg_check
	if [ "$CFG_RC" -eq 0 ]; then
		say "      config.php    matches the template OK"
	elif printf '%s\n' "$CFG_OUT" | grep -q '^LOST'; then
		say "      config.php    ${Y}has keys the next settings save will drop${R}"
		cfg_show
		say "      move them, or add them to render_config_php(), before saving"
	elif [ "$CFG_RC" -eq 1 ]; then
		say "      config.php    older than the template: the next settings save updates it"
		cfg_show
	else
		say "      ${Y}config.php: $(printf '%s\n' "$CFG_OUT" | head -n 1)${R}"
	fi
fi
say ""

# ── 5i. JS smoke test ────────────────────────────────────────────────────────
# Loads each page's scripts in a fake DOM, in the order app/views/*.php list them,
# and reports names used before they are defined. See bin/tools/smoke.js.
SEC="5i. JS smoke test (loads the files in a fake DOM)"
say "${B}$SEC${R}"
if command -v node >/dev/null 2>&1; then
	SMOKE=$(node bin/tools/smoke.js 2>&1)
	if [ $? -eq 0 ]; then
		say "      $(printf '%s\n' "$SMOKE" | grep -c '^  OK') checks passed OK"
		printf '%s\n' "$SMOKE" | grep '^  --' | while IFS= read -r l; do say "    $l"; done
	else
		printf '%s\n' "$SMOKE" | grep -E '^  (NO|--)' | while IFS= read -r l; do say "    $l"; done
		fail "smoke test found problems"
	fi
else
	say "      node not installed, skipped"
fi
say ""

# ── 5j. Filename rules ───────────────────────────────────────────────────────
# Every rule docs/features.md promises for a stored name, checked on random
# names built from the characters that break them, and cleaning a clean name
# changing nothing. See bin/tools/filename-props.php.
SEC="5j. Filename rules (random names, every promised rule)"
say "${B}$SEC${R}"
if command -v php >/dev/null 2>&1; then
	FN_BAD=$(php bin/tools/filename-props.php 1 5000 2>&1; php bin/tools/filename-props.php 2 5000 2>&1)
	if [ -z "$FN_BAD" ]; then
		say "      10000 names, every rule held OK"
	else
		printf '%s\n' "$FN_BAD" | head -n 10 | sed 's/^/      /'
		fail "filename rules broken"
	fi
else
	say "      php not installed, skipped"
fi
say ""

# ── 5k. Whole-string regexes end with \z ───────────────────────────────────
# "$" also matches before a final newline, so a pattern that checks a whole
# value ends with \z; only a multi-line one (/m) keeps "$". See
# bin/tools/regex-anchors.php.
SEC='5k. Whole-string regexes end with \z, not $'
say "${B}$SEC${R}"
if command -v php >/dev/null 2>&1; then
	RX_BAD=$(php bin/tools/regex-anchors.php 2>&1)
	if [ -z "$RX_BAD" ]; then
		say "      none OK"
	else
		printf '%s\n' "$RX_BAD" | head -n 10 | sed 's/^/      /'
		fail 'whole-string regex anchored with $ (use \z)'
	fi
else
	say "      php not installed, skipped"
fi
say ""

# ── 6. Static analysis ───────────────────────────────────────────────────────
# Catches what php -l cannot, such as a variable used but never set (PHP 8
# only warns at runtime), and keeps the stylesheets to their rules.
#
# Two tools, two versions on purpose:
#   - here, on your computer, the LATEST release, so a new check shows up at
#     the first run after it is published, before anyone else sees it;
#   - in CI, a PINNED release already known to pass (tools: phpstan:X and
#     stylelint@X in .github/workflows/lint.yml), so the public badge changes
#     colour only when the code does.
# When the latest one passes here and is newer than the pin, the run offers
# to raise the pin (one line of lint.yml, for you to commit). The latest
# versions are looked up at most once a day, on a terminal only (see
# LATEST_CACHE below); under CI, a pipe, offline or with
# AESBIN_LINT_OFFLINE=1, whatever is installed is used.
#
# The tools are not in the repository, so they are offered, never fetched
# unasked: the rest of the script must work offline and in CI.
say "${B}[6/6] static analysis${R}"

LINT_YML=".github/workflows/lint.yml"
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
# The version lint.yml pins for $1 (phpstan or stylelint), or "".
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
# makes without asking, and only on a terminal. AESBIN_LINT_OFFLINE=1 skips
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
if interactive && [ "${AESBIN_LINT_OFFLINE:-0}" != 1 ]; then
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
				say "      ${Y}no answer; retried in an hour (AESBIN_LINT_OFFLINE=1 skips the check)${R}"
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
	printf '      Download it to bin/tools/ (~27 MB)? [Y/n] '
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

# ── Result ───────────────────────────────────────────────────────────────────
if [ "$FAILED" -eq 0 ]; then
	say "${B}All automated checks passed.${R}"
	say ""
	say "Still to do by hand, on the target host (see docs/maintaining.md):"
	say "  - a full install from scratch through the guard code"
	say "  - the installer only while config.php is missing, the app after"
	say "  - both layouts (public/ as document root, the whole project in the web root)"
	say "  - trusted_proxies on a host that does not rewrite REMOTE_ADDR"
	exit 0
else
	say "${B}Checks failed:${R}"
	printf '%s' "$FAIL_LIST"
	say "Fix the items above before releasing."
	exit 1
fi
