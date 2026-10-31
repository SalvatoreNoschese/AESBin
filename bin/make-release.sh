#!/bin/sh
#
# bin/make-release.sh — the release zip of these sources, for testing an
# install before tagging. GitHub builds the real one itself from the tag.
#
#   ./bin/make-release.sh
#
# Requires git (on Termux: pkg install git). POSIX sh.
#
# Output: bin/dist/AESBin-<version>.zip (git-ignored), everything under
# AESBin-<version>/, as in GitHub's *Source code* zip. The folder is packed as
# it is, through a throwaway git repository that reads the project's
# .gitignore and .gitattributes (export-ignore), so it holds the same files
# GitHub's would; the repository is deleted afterwards.

set -u

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT" || exit 1

command -v git >/dev/null 2>&1 || { printf 'ERROR: needs git.\n' >&2; exit 1; }

VERSION=$(sed -n 's/^define("AESBIN_VERSION", "\([^"]*\)");.*/\1/p' app/includes/bootstrap.php 2>/dev/null | head -n 1)
[ -n "$VERSION" ] || { printf 'ERROR: no AESBIN_VERSION in app/includes/bootstrap.php.\n' >&2; exit 1; }

TMP_GIT=$(mktemp -d) || exit 1
trap 'rm -rf "$TMP_GIT"' EXIT INT TERM
export GIT_DIR="$TMP_GIT" GIT_WORK_TREE="$ROOT"
git init -q && git add -A &&
	git -c user.name=make-release -c user.email=make-release@localhost \
		-c commit.gpgsign=false commit -q -m release ||
	{ printf 'ERROR: git could not read the sources.\n' >&2; exit 1; }

NAME="AESBin-$VERSION"
OUT="bin/dist/$NAME.zip"
mkdir -p bin/dist && rm -f "$OUT" &&
	git archive --format=zip --prefix="$NAME/" -o "$OUT" HEAD ||
	{ printf 'ERROR: git archive failed.\n' >&2; exit 1; }

printf '%s  (%s files)\n' "$OUT" "$(git archive --format=tar HEAD | tar -tf - | grep -vc '/$')"
